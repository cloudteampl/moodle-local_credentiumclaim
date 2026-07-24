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
 * Unit tests for the page-load status refresher.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

use local_credentiumclaim\local\claimable;
use local_credentiumclaim\local\status_refresher;

/**
 * Tests for {@see \local_credentiumclaim\local\status_refresher}.
 *
 * @covers \local_credentiumclaim\local\status_refresher
 */
final class status_refresher_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('enabled', 1, 'local_credentiumclaim');
    }

    public function test_a_freshly_claimed_credential_leaves_the_list(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');
        $this->make_stale($user->id, 'key-1');

        $applied = $this->make_refresher(['key-1' => 'claimed'])->refresh_for_user((int) $user->id);

        $this->assertSame(1, $applied);
        $this->assertSame('claimed', $this->row($user->id, 'key-1')->remotestatus);
        $this->assertCount(0, claimable::list_for_user((int) $user->id), 'A claimed credential must drop off the page.');
    }

    public function test_recently_checked_rows_are_not_repolled(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');
        // The apply_remote_status() call just stamped timechecked = now: within the throttle.

        $applied = $this->make_refresher(['key-1' => 'claimed'])->refresh_for_user((int) $user->id);

        $this->assertSame(0, $applied);
        $this->assertSame('issued', $this->row($user->id, 'key-1')->remotestatus, 'A fresh row must be left alone.');
    }

    public function test_a_zeroed_timecheck_bypasses_the_throttle(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');

        // What claim.php does right before redirecting to the claim URL.
        claimable::request_recheck((int) $user->id, (int) $this->row($user->id, 'key-1')->id);

        $applied = $this->make_refresher(['key-1' => 'claimed'])->refresh_for_user((int) $user->id);

        $this->assertSame(1, $applied);
        $this->assertSame('claimed', $this->row($user->id, 'key-1')->remotestatus);
    }

    public function test_becoming_ready_through_a_page_view_notifies_once(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        $this->make_stale($user->id, 'key-1');

        $sink = $this->redirectMessages();
        $this->make_refresher(['key-1' => 'issued'])->refresh_for_user((int) $user->id);
        $this->assertCount(1, $sink->get_messages(), 'The processing -> issued transition must notify.');

        // A second refresh sees issued -> issued: no re-notification.
        $this->make_stale($user->id, 'key-1');
        $this->make_refresher(['key-1' => 'issued'])->refresh_for_user((int) $user->id);
        $this->assertCount(1, $sink->get_messages());
    }

    public function test_rows_the_api_does_not_report_are_stamped_not_looped(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-unknown', null, null);
        $this->make_stale($user->id, 'key-unknown');

        $applied = $this->make_refresher([])->refresh_for_user((int) $user->id);

        $this->assertSame(0, $applied);
        $row = $this->row($user->id, 'key-unknown');
        $this->assertSame('processing', $row->remotestatus);
        $this->assertGreaterThan(
            0,
            (int) $row->timechecked,
            'An unreported row must be stamped so reloads cannot hammer the API.'
        );
    }

    public function test_an_exploding_client_never_breaks_the_page(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        $this->make_stale($user->id, 'key-1');

        $refresher = new status_refresher();
        $refresher->set_client(new class ('https://api.example.com', 'pub.key') extends \local_credentiumclaim\api\client {
            /**
             * Simulate a hard client failure.
             *
             * @param string[] $issuerequestids Ignored.
             * @return array Never returns.
             */
            public function get_status_batch(array $issuerequestids): array {
                throw new \moodle_exception('apierror', 'local_credentiumclaim');
            }
        });

        $this->assertSame(0, $refresher->refresh_for_user((int) $user->id));
        $this->assertSame('processing', $this->row($user->id, 'key-1')->remotestatus);
    }

    /**
     * Build a refresher whose client answers from a canned status map.
     *
     * @param array $statusmap Map of issueRequestId to status string.
     * @return status_refresher
     */
    private function make_refresher(array $statusmap): status_refresher {
        $client = new class ('https://api.example.com', 'pub.key', $statusmap) extends \local_credentiumclaim\api\client {
            /** @var array Map of issueRequestId to status string. */
            private array $statusmap;

            /**
             * Configure the client double with a canned status map.
             *
             * @param string $url Base URL.
             * @param string $key API key.
             * @param array $statusmap Map of issueRequestId to status string.
             */
            public function __construct($url, $key, array $statusmap = []) {
                parent::__construct($url, $key);
                $this->statusmap = $statusmap;
            }

            /**
             * Return a canned batch-status response built from the status map.
             *
             * @param string $method HTTP method.
             * @param string $url Request URL.
             * @param string[] $headers Request headers.
             * @param string|null $body Request body.
             * @return array [http_code, response_body, curl_info]
             */
            protected function raw_request(string $method, string $url, array $headers, ?string $body): array {
                $ids = json_decode($body)->issueRequestIds;
                $results = [];
                foreach ($ids as $id) {
                    if (isset($this->statusmap[$id])) {
                        $results[] = ['issueRequestId' => $id, 'status' => $this->statusmap[$id]];
                    }
                }
                return [200, json_encode(['results' => $results]), []];
            }
        };

        $refresher = new status_refresher();
        $refresher->set_client($client);
        return $refresher;
    }

    /**
     * Push a row's timechecked far enough into the past to defeat the throttle.
     *
     * @param int $userid User id.
     * @param string $key Credential key.
     * @return void
     */
    private function make_stale(int $userid, string $key): void {
        global $DB;
        $row = $this->row($userid, $key);
        $DB->set_field(claimable::TABLE, 'timechecked', time() - 3600, ['id' => $row->id]);
    }

    /**
     * Fetch a tracking row by user and credential key.
     *
     * @param int $userid User id.
     * @param string $key Credential key.
     * @return \stdClass
     */
    private function row(int $userid, string $key): \stdClass {
        global $DB;
        return $DB->get_record(claimable::TABLE, ['userid' => $userid, 'credentialkey' => $key], '*', MUST_EXIST);
    }
}
