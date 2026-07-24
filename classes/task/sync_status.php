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
 * Scheduled task that syncs Credentium credential claim statuses.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\task;

use local_credentiumclaim\api\client;
use local_credentiumclaim\local\claimable;
use local_credentiumclaim\local\connector_config;
use local_credentiumclaim\local\notifier;

/**
 * Discovers issued credentials from local_credentium and polls Credentium for their claim status.
 *
 * Every run leaves a machine-readable trace in the plugin configuration
 * (see {@see self::record_run()}) so the admin report can explain *why* a sync
 * produced no changes instead of leaving credentials apparently stuck.
 */
class sync_status extends \core\task\scheduled_task {
    /** @var int Max rows discovered/polled per run (bounds cron time and third-party API load). */
    protected const MAX_PER_RUN = 1000;

    /**
     * @var int Wall-clock budget for the polling phase, in seconds.
     *
     * Row and batch limits bound how much work a run takes on, but not how long an
     * unresponsive API can make that work last: in category mode the run costs one
     * batch call per distinct key, each of those splits into chunks, and each chunk
     * brings its own retry ladder. syncnow.php raises the PHP time limit to 300s for
     * a manual run, so without a budget a wide-enough site could hit it mid-poll and
     * die instead of redirecting with a diagnosis.
     *
     * The deadline is handed to the client, which refuses to start an attempt the
     * remaining time cannot finish, so the overshoot is one rounding second rather
     * than a figure that has to be re-derived whenever a retry constant changes.
     * Whatever is left unpolled keeps its older timechecked and is therefore first
     * in the queue on the next run.
     */
    protected const POLL_BUDGET = 150;

    /** @var string Run outcome: the sync completed (possibly with nothing to do). */
    public const RESULT_OK = 'ok';
    /** @var string Run outcome: the plugin is switched off. */
    public const RESULT_DISABLED = 'disabled';
    /** @var string Run outcome: no API credentials could be inherited from the connector. */
    public const RESULT_NOTCONFIGURED = 'notconfigured';
    /** @var string Run outcome: at least one API call failed. */
    public const RESULT_ERROR = 'error';

    /** @var client|null Injected client (for tests). */
    protected $client = null;

    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_syncstatus', 'local_credentiumclaim');
    }

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
     * Resolve the API client for one set of credentials, honouring test injection.
     *
     * @param \stdClass $config Credentials {apiurl, apikey}.
     * @return client
     */
    protected function get_client(\stdClass $config): client {
        return $this->client ?? new client($config->apiurl, $config->apikey);
    }

    /**
     * Run the sync: discover new candidates, then poll their remote status.
     *
     * @return void
     */
    public function execute() {
        require_once(__DIR__ . '/../../lib.php');

        connector_config::reset_cache();

        if (!local_credentiumclaim_is_enabled()) {
            mtrace('Credentium Claim is disabled; nothing to do.');
            $this->record_run(self::RESULT_DISABLED);
            return;
        }

        if ($this->client === null && !connector_config::is_usable()) {
            mtrace('No Credentium API credentials are available from local_credentium; skipping.');
            $this->record_run(self::RESULT_NOTCONFIGURED);
            return;
        }

        try {
            $this->discover();
            $this->poll();
        } catch (\Throwable $e) {
            // Polling records its own outcome, but a hard failure anywhere would leave
            // the report showing the previous run's result as if it were current.
            $this->record_run(self::RESULT_ERROR, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Import newly issued credentials from local_credentium into the tracking table.
     *
     * @return void
     */
    protected function discover(): void {
        $new = 0;
        foreach ($this->fetch_source_issuances(self::MAX_PER_RUN) as $issuance) {
            $courseid = isset($issuance->courseid) && $issuance->courseid !== null ? (int) $issuance->courseid : null;
            $issuanceid = isset($issuance->id) ? (int) $issuance->id : null;
            $tracked = claimable::record_candidate(
                (int) $issuance->userid,
                (string) $issuance->credentialid,
                $issuanceid,
                $courseid
            );
            if ($tracked) {
                $new++;
            }
        }
        mtrace("Discovered {$new} new credential(s) to track.");
    }

    /**
     * Read issued credentials from the local_credentium issuances table.
     *
     * Isolated as its own method so tests can supply source data without depending
     * on the sibling plugin's schema.
     *
     * @param int $limit Maximum rows to return this run.
     * @return \stdClass[] Rows with id, userid, courseid, credentialid.
     */
    protected function fetch_source_issuances(int $limit): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_credentium_issuances')) {
            mtrace('local_credentium issuances table not found; skipping discovery.');
            return [];
        }
        // Only fetch issued credentials we are not already tracking, bounded per run.
        // A single indexed anti-join replaces one record_exists() round-trip per source row.
        $sql = "SELECT i.id, i.userid, i.courseid, i.credentialid
                  FROM {local_credentium_issuances} i
                 WHERE i.status = :status
                   AND i.credentialid IS NOT NULL
                   AND i.credentialid <> :empty
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {local_credentiumclaim_status} s
                        WHERE s.userid = i.userid
                          AND s.credentialkey = i.credentialid)
              ORDER BY i.id ASC";
        return $DB->get_records_sql($sql, ['status' => 'issued', 'empty' => ''], 0, $limit);
    }

    /**
     * Poll Credentium for the status of all non-terminal tracked credentials.
     *
     * Rows are grouped by the credentials that apply to them, so a connector running
     * in category mode (one API key per category) is polled with one batch call per
     * distinct key rather than one wrong call for everything.
     *
     * @return void
     */
    protected function poll(): void {
        $rows = claimable::get_pollable(self::MAX_PER_RUN);
        if (empty($rows)) {
            mtrace('No credentials pending a status check.');
            $this->record_run(self::RESULT_OK);
            return;
        }

        $groups = [];
        $unresolved = 0;
        $unresolvedids = [];
        foreach ($rows as $row) {
            $config = $this->resolve_config($row);
            if ($config === null) {
                $unresolved++;
                // Stamp these too: without it, a credential that can never resolve
                // credentials (deleted course, unconfigured category, no global
                // fallback) would keep timechecked = 0 and, being polled in
                // timechecked ASC order, permanently occupy the head of the queue.
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

        $polled = 0;
        $updated = 0;
        $unmatched = 0;
        $unanswered = 0;
        $notifiedtotal = 0;
        $notifyfailedtotal = 0;
        $error = null;
        $errorkind = null;

        // Once the service, the rate limit, or the route to it has demonstrably failed,
        // retrying each remaining group repeats a wait we already know the outcome of.
        // In category mode that is one full retry ladder per distinct API key. Auth and
        // request failures are per-key, so they say nothing about the next key and do
        // not degrade. This bounds the retrying; POLL_BUDGET bounds the run itself.
        $degraded = false;
        $deadline = microtime(true) + self::POLL_BUDGET;

        foreach ($groups as $group) {
            if (microtime(true) >= $deadline) {
                // Out of budget. These rows are deliberately left unstamped: their older
                // timechecked puts them at the head of the next run's queue.
                $unanswered += count($group['rows']);
                continue;
            }

            $client = $this->get_client($group['config']);
            if ($degraded) {
                $client->set_max_attempts(1);
            }
            // The client enforces the deadline per attempt and per batch chunk, so the
            // bound holds however many chunks and retries a group turns out to need.
            $client->set_deadline($deadline);

            $keys = [];
            foreach ($group['rows'] as $row) {
                $keys[$row->credentialkey] = true;
            }
            $statuses = $client->get_status_batch(array_keys($keys));
            $degraded = $degraded || in_array(
                $client->get_last_failure_kind(),
                [client::FAIL_SERVER, client::FAIL_NETWORK, client::FAIL_BUSY],
                true
            );
            // Ids whose batch call failed outright. Without this split every row of a
            // failed run was counted as "Credentium did not recognise this identifier",
            // which sent admins to check their API key during a Credentium outage.
            $noanswer = array_fill_keys($client->get_last_unanswered_ids(), true);

            $unreported = [];
            $notified = 0;
            $notifyfailed = 0;
            foreach ($group['rows'] as $row) {
                $polled++;
                if (isset($statuses[$row->credentialkey])) {
                    $snapshot = $statuses[$row->credentialkey];
                    $becameready = claimable::apply_remote_status(
                        $row,
                        $snapshot->status,
                        $snapshot->credentialid ?? null
                    );
                    if ($becameready) {
                        // Best-effort push; a failure is counted so the report can flag it.
                        if (notifier::credential_ready($row)) {
                            $notified++;
                        } else {
                            $notifyfailed++;
                        }
                    }
                    $updated++;
                } else if (isset($noanswer[$row->credentialkey])) {
                    $unanswered++;
                    $unreported[] = (int) $row->id;
                } else {
                    $unmatched++;
                    $unreported[] = (int) $row->id;
                }
            }
            if ($notified > 0) {
                mtrace('Notified ' . $notified . ' learner(s) of a newly claimable credential.');
            }
            if ($notifyfailed > 0) {
                mtrace('Failed to notify ' . $notifyfailed . ' learner(s) of a claimable credential.');
            }
            $notifiedtotal += $notified;
            $notifyfailedtotal += $notifyfailed;
            // Stamp the ones the API stayed silent about so the poll queue keeps moving.
            claimable::mark_checked($unreported);

            if ($error === null) {
                // The kind must travel with the message it describes, or a later group's
                // success would leave the report advising on the wrong kind of failure.
                $error = $client->get_last_error();
                $errorkind = $client->get_last_failure_kind();
            }
        }

        mtrace('Polled ' . $polled . ' credential(s); updated ' . $updated . '.');
        if ($unmatched > 0) {
            mtrace('Credentium did not recognise ' . $unmatched . ' identifier(s).');
        }
        if ($unanswered > 0) {
            // Covers both "the call failed" and "the run ran out of budget before
            // reaching this group": from the credential's point of view, the same thing.
            mtrace('Could not check ' . $unanswered . ' credential(s) this run; they stay pending.');
        }
        if ($unresolved > 0) {
            mtrace('Skipped ' . $unresolved . ' credential(s) with no usable API credentials.');
        }
        if ($error !== null) {
            mtrace('Last API error: ' . $error);
        }

        $this->record_run($error === null ? self::RESULT_OK : self::RESULT_ERROR, [
            'polled' => $polled,
            'updated' => $updated,
            'unmatched' => $unmatched,
            'unanswered' => $unanswered,
            'unresolved' => $unresolved,
            'notified' => $notifiedtotal,
            'notifyfailed' => $notifyfailedtotal,
            'error' => $error,
            'errorkind' => $errorkind,
        ]);
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
     * Persist a machine-readable summary of this run for the admin report.
     *
     * @param string $result One of the RESULT_* constants.
     * @param array $counters Optional counters: polled, updated, unmatched, unanswered,
     *                        unresolved, notified, notifyfailed, error, errorkind.
     * @return void
     */
    protected function record_run(string $result, array $counters = []): void {
        set_config('lastrun', time(), 'local_credentiumclaim');
        set_config('lastrunresult', $result, 'local_credentiumclaim');
        set_config('lastrunpolled', (int) ($counters['polled'] ?? 0), 'local_credentiumclaim');
        set_config('lastrunupdated', (int) ($counters['updated'] ?? 0), 'local_credentiumclaim');
        set_config('lastrununmatched', (int) ($counters['unmatched'] ?? 0), 'local_credentiumclaim');
        set_config('lastrununanswered', (int) ($counters['unanswered'] ?? 0), 'local_credentiumclaim');
        set_config('lastrununresolved', (int) ($counters['unresolved'] ?? 0), 'local_credentiumclaim');
        set_config('lastrunnotified', (int) ($counters['notified'] ?? 0), 'local_credentiumclaim');
        set_config('lastrunnotifyfailed', (int) ($counters['notifyfailed'] ?? 0), 'local_credentiumclaim');
        set_config('lastrunerror', (string) ($counters['error'] ?? ''), 'local_credentiumclaim');
        set_config('lastrunerrorkind', (string) ($counters['errorkind'] ?? ''), 'local_credentiumclaim');
    }
}
