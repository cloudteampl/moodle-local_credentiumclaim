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

defined('MOODLE_INTERNAL') || die();

/**
 * Discovers issued credentials from local_credentium and polls Credentium for their claim status.
 */
class sync_status extends \core\task\scheduled_task {

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
     * Resolve the API client, creating a live one if none was injected.
     *
     * @return client
     */
    protected function get_client(): client {
        return $this->client ?? new client();
    }

    /**
     * Run the sync: discover new candidates, then poll their remote status.
     *
     * @return void
     */
    public function execute() {
        require_once(__DIR__ . '/../../lib.php');

        if (!local_credentiumclaim_is_enabled()) {
            mtrace('Credentium Claim is disabled; nothing to do.');
            return;
        }

        $client = $this->get_client();
        if (!$client->is_configured()) {
            mtrace('Credentium Claim API is not configured; skipping.');
            return;
        }

        $this->discover();
        $this->poll($client);
    }

    /**
     * Import newly issued credentials from local_credentium into the tracking table.
     *
     * @return void
     */
    protected function discover(): void {
        $new = 0;
        foreach ($this->fetch_source_issuances() as $issuance) {
            $courseid = isset($issuance->courseid) && $issuance->courseid !== null ? (int) $issuance->courseid : null;
            if (claimable::record_candidate(
                (int) $issuance->userid,
                (string) $issuance->credentialid,
                isset($issuance->id) ? (int) $issuance->id : null,
                $courseid
            )) {
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
     * @return \stdClass[] Rows with id, userid, courseid, credentialid.
     */
    protected function fetch_source_issuances(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_credentium_issuances')) {
            mtrace('local_credentium issuances table not found; skipping discovery.');
            return [];
        }
        return $DB->get_records_select(
            'local_credentium_issuances',
            "status = :status AND credentialid IS NOT NULL AND credentialid <> ''",
            ['status' => 'issued'],
            'id ASC',
            'id, userid, courseid, credentialid'
        );
    }

    /**
     * Poll Credentium for the status of all non-terminal tracked credentials.
     *
     * @param client $client The API client.
     * @return void
     */
    protected function poll(client $client): void {
        $rows = claimable::get_pollable();
        if (empty($rows)) {
            mtrace('No credentials pending a status check.');
            return;
        }

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row->credentialkey] = true;
        }

        $statuses = $client->get_status_batch(array_keys($keys));

        $updated = 0;
        foreach ($rows as $row) {
            if (isset($statuses[$row->credentialkey])) {
                claimable::apply_remote_status($row, $statuses[$row->credentialkey]->status);
                $updated++;
            }
        }
        mtrace('Polled ' . count($rows) . ' credential(s); updated ' . $updated . '.');
    }
}
