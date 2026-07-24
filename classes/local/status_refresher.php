<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * On-demand refresh of one learner's credential claim statuses.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\local;

use local_credentiumclaim\api\client;

/**
 * Re-polls the remote status of a single learner's non-terminal credentials.
 *
 * The scheduled task keeps statuses fresh in bulk, but between two cron runs the
 * "My credentials" page would otherwise show a credential as *Ready to claim* even
 * after the learner has just saved it to their wallet. This service closes that gap
 * at the moment it matters: when the learner is actually looking at the page.
 *
 * It is deliberately defensive, because it runs inside an interactive request:
 * - bounded (at most {@see self::MAX_ROWS} rows per call);
 * - throttled per row through the existing `timechecked` stamp, so page reloads
 *   cannot hammer the API (and a failed call is not retried for a minute either);
 * - short-fused (an {@see self::TIMEOUT}-second HTTP timeout instead of cron's 30);
 * - silent on failure: the page then simply renders the last known statuses.
 */
class status_refresher {
    /** @var int Rows checked more recently than this many seconds ago are left alone. */
    public const MIN_AGE_SECONDS = 60;

    /** @var int Upper bound of rows refreshed in one call. */
    protected const MAX_ROWS = 50;

    /** @var int HTTP timeout in seconds per API call; an interactive page must not wait longer. */
    protected const TIMEOUT = 8;

    /** @var int Overall time budget in seconds for one refresh, across all API calls. */
    protected const TIME_BUDGET = 10;

    /** @var client|null Injected client (for tests). */
    protected $client = null;

    /**
     * Inject an API client (used by unit tests).
     *
     * @param client $client The client to use.
     * @return void
     */
    public function set_client(client $client): void {
        $this->client = $client;
    }

    /**
     * Refresh the remote status of a user's stale, non-terminal credentials.
     *
     * Never throws: any failure is logged and the caller keeps rendering the last
     * known statuses.
     *
     * @param int $userid User id.
     * @param int $minage Only rows last checked more than this many seconds ago are polled.
     * @return int Number of rows whose remote status was applied.
     */
    public function refresh_for_user(int $userid, int $minage = self::MIN_AGE_SECONDS): int {
        try {
            return $this->do_refresh($userid, $minage);
        } catch (\Throwable $e) {
            require_once(__DIR__ . '/../../lib.php');
            local_credentiumclaim_log('Live status refresh failed', [
                'userid' => $userid,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * The actual refresh, mirroring the grouping logic of the sync task.
     *
     * @param int $userid User id.
     * @param int $minage Minimum staleness in seconds.
     * @return int Number of rows whose remote status was applied.
     */
    protected function do_refresh(int $userid, int $minage): int {
        global $DB;

        $rows = $DB->get_records_select(
            claimable::TABLE,
            'userid = :userid AND remotestatus <> :claimed AND remotestatus <> :failed AND timechecked < :cutoff',
            [
                'userid' => $userid,
                'claimed' => claimable::STATUS_CLAIMED,
                'failed' => claimable::STATUS_FAILED,
                'cutoff' => time() - $minage,
            ],
            'timechecked ASC',
            '*',
            0,
            self::MAX_ROWS
        );
        if (empty($rows)) {
            return 0;
        }

        // Group rows by the API credentials that apply to them, so a connector in
        // category mode is asked with the right key per course (same as the sync task).
        $groups = [];
        $unresolvedids = [];
        foreach ($rows as $row) {
            $config = $this->resolve_config($row);
            if ($config === null) {
                // No usable credentials for this row (same as the sync task): stamp it,
                // or it would stay maximally stale and hog the MAX_ROWS window forever.
                $unresolvedids[] = (int) $row->id;
                continue;
            }
            $groupkey = sha1($config->apiurl . "\0" . $config->apikey);
            if (!isset($groups[$groupkey])) {
                $groups[$groupkey] = ['config' => $config, 'rows' => []];
            }
            $groups[$groupkey]['rows'][] = $row;
        }
        claimable::mark_checked($unresolvedids);

        // One overall deadline across all groups: in category mode each distinct key
        // is a separate HTTP call, and a learner's page must not stack N slow calls.
        $deadline = microtime(true) + self::TIME_BUDGET;

        $applied = 0;
        foreach ($groups as $group) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                // Budget exhausted: the remaining rows keep their last known status
                // and stay stale, so the next page view simply picks them up first.
                break;
            }
            $client = $this->get_client($group['config'], (int) min(self::TIMEOUT, ceil($remaining)));
            // Same structural bound the sync task relies on: the budget covers every
            // chunk of the batch, not just the first call.
            $client->set_deadline($deadline);

            $keys = [];
            foreach ($group['rows'] as $row) {
                $keys[$row->credentialkey] = true;
            }
            $statuses = $client->get_status_batch(array_keys($keys));

            $unreported = [];
            foreach ($group['rows'] as $row) {
                if (isset($statuses[$row->credentialkey])) {
                    $becameready = claimable::apply_remote_status($row, $statuses[$row->credentialkey]->status);
                    if ($becameready) {
                        // Same exactly-once semantics as cron: apply_remote_status()
                        // decides the transition under a per-row lock against the
                        // database, so even a concurrent cron poll cannot observe the
                        // same "became issued" moment and notify a second time.
                        notifier::credential_ready($row);
                    }
                    $applied++;
                } else {
                    // Stamp what the API stayed silent about (including a failed call,
                    // which yields an empty batch): the per-row throttle then prevents
                    // every page reload from re-hitting an unresponsive API.
                    $unreported[] = (int) $row->id;
                }
            }
            claimable::mark_checked($unreported);
        }
        return $applied;
    }

    /**
     * Credentials that apply to one tracked credential.
     *
     * @param \stdClass $row Tracking row.
     * @return \stdClass|null {apiurl, apikey}, or null when nothing is configured.
     */
    protected function resolve_config(\stdClass $row): ?\stdClass {
        if ($this->client !== null) {
            // A client was injected (tests): the credentials are irrelevant but a group
            // key is still needed, so return a stable placeholder.
            return (object) ['apiurl' => 'injected', 'apikey' => 'injected'];
        }
        return connector_config::for_course(isset($row->courseid) ? (int) $row->courseid : null);
    }

    /**
     * Resolve the API client for one set of credentials, honouring test injection.
     *
     * @param \stdClass $config Credentials {apiurl, apikey}.
     * @param int $timeout HTTP timeout in seconds for this client.
     * @return client
     */
    protected function get_client(\stdClass $config, int $timeout = self::TIMEOUT): client {
        $client = $this->client ?? new client($config->apiurl, $config->apikey);
        $client->set_timeout($timeout);
        // No retrying on this path. The scheduled task is where a transient API fault is
        // ridden out; spending a learner's page load on a second attempt would only turn
        // one slow render into a slower one, and the page falls back to the last known
        // statuses perfectly well. Applied to an injected client too, so the bound is
        // part of what the tests exercise rather than an untested production-only branch.
        $client->set_max_attempts(1);
        return $client;
    }
}
