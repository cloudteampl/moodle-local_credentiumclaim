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
 * Unit tests for the status sync scheduled task.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

use local_credentiumclaim\api\client as apiclient;
use local_credentiumclaim\local\claimable;

/**
 * Tests for {@see \local_credentiumclaim\task\sync_status}.
 *
 * @covers \local_credentiumclaim\task\sync_status
 */
final class sync_status_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_execute_discovers_candidates_and_applies_remote_status(): void {
        set_config('enabled', 1, 'local_credentiumclaim');
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();

        $task = $this->make_task(
            [
                (object) ['id' => 1, 'userid' => $u1->id, 'courseid' => null, 'credentialid' => 'rq-1'],
                (object) ['id' => 2, 'userid' => $u2->id, 'courseid' => null, 'credentialid' => 'rq-2'],
            ],
            ['rq-1' => 'issued', 'rq-2' => 'claimed']
        );

        $this->run_task($task);

        // Credential rq-1 is issued => banner-worthy for u1.
        $this->assertSame(1, claimable::count_for_user($u1->id));
        // Credential rq-2 is claimed => nothing to claim for u2.
        $this->assertSame(0, claimable::count_for_user($u2->id));
        $this->assertCount(0, claimable::list_for_user($u2->id));
    }

    public function test_the_sync_stores_the_credential_id_the_api_reports(): void {
        global $DB;
        set_config('enabled', 1, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        $this->run_task($this->make_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            ['rq-1' => 'claimed'],
            ['rq-1' => 'cred-1']
        ));

        // The page links a claimed credential to its page in the wallet, which needs
        // the credential's own id — not the issue-request id we poll with.
        $row = $DB->get_record(claimable::TABLE, ['credentialkey' => 'rq-1'], '*', MUST_EXIST);
        $this->assertSame('cred-1', $row->credentialid);
    }

    public function test_execute_is_a_noop_when_disabled(): void {
        global $DB;
        set_config('enabled', 0, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        $task = $this->make_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            ['rq-1' => 'issued']
        );

        $this->run_task($task);

        $this->assertSame(0, $DB->count_records(claimable::TABLE), 'Nothing should be tracked while disabled.');
    }

    public function test_second_run_is_idempotent(): void {
        global $DB;
        set_config('enabled', 1, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();
        $source = [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']];

        $this->run_task($this->make_task($source, ['rq-1' => 'issued']));
        $this->run_task($this->make_task($source, ['rq-1' => 'issued']));

        $this->assertSame(1, $DB->count_records(claimable::TABLE), 'Re-running must not duplicate rows.');
        $this->assertSame(1, claimable::count_for_user($u->id));
    }

    public function test_unrecognised_identifiers_are_recorded_for_the_admin_report(): void {
        set_config('enabled', 1, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        // Credentium knows nothing about rq-1 — e.g. the key belongs to another org.
        $task = $this->make_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            []
        );

        $this->run_task($task);

        $this->assertSame('ok', get_config('local_credentiumclaim', 'lastrunresult'));
        $this->assertSame('1', get_config('local_credentiumclaim', 'lastrunpolled'));
        $this->assertSame('0', get_config('local_credentiumclaim', 'lastrunupdated'));
        $this->assertSame(
            '1',
            get_config('local_credentiumclaim', 'lastrununmatched'),
            'A credential the API does not recognise must be reported, not silently ignored.'
        );
    }

    public function test_failed_notification_is_counted_for_the_report(): void {
        set_config('enabled', 1, 'local_credentiumclaim');
        // A suspended learner cannot be messaged, so the notification "fails" — the
        // report must count it rather than let it vanish.
        $u = $this->getDataGenerator()->create_user(['suspended' => 1]);

        $this->run_task($this->make_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            ['rq-1' => 'issued']
        ));

        $this->assertSame('0', get_config('local_credentiumclaim', 'lastrunnotified'));
        $this->assertSame('1', get_config('local_credentiumclaim', 'lastrunnotifyfailed'));
    }

    public function test_disabled_run_is_recorded(): void {
        set_config('enabled', 0, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        $this->run_task($this->make_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            ['rq-1' => 'issued']
        ));

        $this->assertSame('disabled', get_config('local_credentiumclaim', 'lastrunresult'));
        $this->assertNotEmpty(get_config('local_credentiumclaim', 'lastrun'));
    }

    /**
     * A credential whose course/category resolves to no usable API credentials at all
     * (e.g. category mode with no global fallback) must still be stamped as checked.
     *
     * Without this, it would keep timechecked = 0 forever and, since polling is ordered
     * by timechecked ASC, permanently occupy the head of the queue on every run.
     */
    public function test_unresolvable_credentials_do_not_starve_the_poll_queue(): void {
        global $DB;
        if (!\local_credentiumclaim\local\connector_config::is_installed()) {
            // This path runs through the connector's own resolver, so it needs the
            // (hard-dependency) plugin present. CI installs it; a bare checkout may not.
            $this->markTestSkipped('local_credentium is not installed.');
        }
        set_config('enabled', 1, 'local_credentiumclaim');
        set_config('categorymode', 1, 'local_credentium');
        set_config('apiurl', '', 'local_credentium');
        set_config('apikey', '', 'local_credentium');

        $course = $this->getDataGenerator()->create_course();
        $u = $this->getDataGenerator()->create_user();

        // No client is injected: this exercises the real connector_config resolution,
        // which must fail to find any usable credentials for this course.
        $task = new class extends \local_credentiumclaim\task\sync_status {
            /** @var \stdClass[] */
            public array $source = [];

            /**
             * Return the canned source issuances (bounded by the limit).
             *
             * @param int $limit Maximum rows.
             * @return \stdClass[]
             */
            protected function fetch_source_issuances(int $limit): array {
                return array_slice($this->source, 0, $limit);
            }
        };
        $task->source = [
            (object) ['id' => 1, 'userid' => $u->id, 'courseid' => (int) $course->id, 'credentialid' => 'rq-1'],
        ];

        $this->run_task($task);

        $row = $DB->get_record(claimable::TABLE, ['credentialkey' => 'rq-1'], '*', MUST_EXIST);
        $this->assertGreaterThan(
            0,
            $row->timechecked,
            'An unresolvable credential must still be stamped as checked, or it would starve the poll queue.'
        );
        $this->assertSame('1', get_config('local_credentiumclaim', 'lastrununresolved'));
    }

    public function test_an_api_outage_is_not_blamed_on_the_identifiers(): void {
        set_config('enabled', 1, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        $task = $this->make_failing_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            500
        );

        $this->run_task($task);

        // An unanswered call and an id Credentium does not recognise both leave the
        // status missing, but only the latter means "check your API key". Counting an
        // outage as unmatched sent admins looking in entirely the wrong place.
        $this->assertSame('error', get_config('local_credentiumclaim', 'lastrunresult'));
        $this->assertSame('0', get_config('local_credentiumclaim', 'lastrununmatched'));
        $this->assertSame('1', get_config('local_credentiumclaim', 'lastrununanswered'));
        $this->assertSame(
            \local_credentiumclaim\api\client::FAIL_SERVER,
            get_config('local_credentiumclaim', 'lastrunerrorkind'),
            'The report can only advise on a failure it has classified.'
        );
    }

    public function test_an_unreachable_api_is_recorded_as_a_network_failure(): void {
        set_config('enabled', 1, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        $task = $this->make_failing_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            0
        );

        $this->run_task($task);

        $this->assertSame(
            \local_credentiumclaim\api\client::FAIL_NETWORK,
            get_config('local_credentiumclaim', 'lastrunerrorkind'),
            'Advice to widen an API key must not be given when nothing was reachable.'
        );
    }

    public function test_a_failed_run_still_stamps_rows_so_the_queue_keeps_moving(): void {
        global $DB;
        set_config('enabled', 1, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        $this->run_task($this->make_failing_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            500
        ));

        $row = $DB->get_record(claimable::TABLE, ['credentialkey' => 'rq-1'], '*', MUST_EXIST);
        $this->assertGreaterThan(0, $row->timechecked);
        $this->assertSame(
            claimable::STATUS_PROCESSING,
            $row->remotestatus,
            'A failed poll must never invent a status.'
        );
    }

    public function test_a_dead_api_is_not_retried_once_per_category_key(): void {
        set_config('enabled', 1, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        // Three credentials that resolve to three different API keys, as a connector in
        // category mode produces. Without the degradation guard each one would pay a
        // full retry ladder against an API already known to be down.
        $courses = [
            $this->getDataGenerator()->create_course(),
            $this->getDataGenerator()->create_course(),
            $this->getDataGenerator()->create_course(),
        ];
        $source = [];
        foreach ($courses as $i => $course) {
            $source[] = (object) [
                'id' => $i + 1,
                'userid' => $u->id,
                'courseid' => (int) $course->id,
                'credentialid' => 'rq-' . ($i + 1),
            ];
        }

        $task = $this->make_failing_task($source, 500, true);

        $this->run_task($task);

        $this->assertSame(
            5,
            $task->countingclient->calls,
            'The first group may retry (3 calls); once the service has failed the rest get one attempt each.'
        );
        $this->assertSame('3', get_config('local_credentiumclaim', 'lastrununanswered'));
        $this->assertSame('0', get_config('local_credentiumclaim', 'lastrununmatched'));
    }

    public function test_a_rate_limit_is_not_mistaken_for_a_bad_request(): void {
        set_config('enabled', 1, 'local_credentiumclaim');
        $u = $this->getDataGenerator()->create_user();

        $this->run_task($this->make_failing_task(
            [(object) ['id' => 1, 'userid' => $u->id, 'courseid' => null, 'credentialid' => 'rq-1']],
            429
        ));

        // Advice to "check for a plugin update" is useless for a rate limit; the admin
        // needs to hear that backing off is already happening.
        $this->assertSame(
            \local_credentiumclaim\api\client::FAIL_BUSY,
            get_config('local_credentiumclaim', 'lastrunerrorkind')
        );
    }

    /**
     * Build a sync task whose every API call fails with the given status.
     *
     * @param \stdClass[] $source Fake source issuances.
     * @param int $httpcode Status to answer with (0 means the call never completed).
     * @param bool $percourse Give each course its own credentials, as category mode does,
     *                        so the task polls one group per course instead of one overall.
     * @return \local_credentiumclaim\task\sync_status
     */
    private function make_failing_task(array $source, int $httpcode, bool $percourse = false) {
        $client = new class ('https://api.example.com', 'pub.key', $httpcode) extends \local_credentiumclaim\api\client {
            /** @var int Status every call answers with. */
            private int $httpcode;
            /** @var int How many HTTP calls were attempted. */
            public int $calls = 0;

            /**
             * Configure the client double with a canned failure.
             *
             * @param string $url Base URL.
             * @param string $key API key.
             * @param int $httpcode Status to answer with.
             */
            public function __construct($url, $key, int $httpcode) {
                parent::__construct($url, $key);
                $this->httpcode = $httpcode;
            }

            /**
             * Always fail, counting the attempts.
             *
             * @param string $method HTTP method.
             * @param string $url Request URL.
             * @param string[] $headers Request headers.
             * @param string|null $body Request body.
             * @return array [http_code, response_body, curl_info]
             */
            protected function raw_request(string $method, string $url, array $headers, ?string $body): array {
                $this->calls++;
                return [$this->httpcode, '', []];
            }

            /**
             * Do not really wait between the retries this test drives.
             *
             * @param int $seconds Seconds the client wanted to wait.
             * @return void
             */
            protected function backoff_sleep(int $seconds): void {
                return;
            }
        };

        $task = new class extends \local_credentiumclaim\task\sync_status {
            /** @var \stdClass[] */
            public array $source = [];
            /** @var bool Whether each course resolves to its own credentials. */
            public bool $percourse = false;
            /** @var \local_credentiumclaim\api\client|null The counting client double. */
            public $countingclient = null;

            /**
             * Return the canned source issuances (bounded by the limit).
             *
             * @param int $limit Maximum rows.
             * @return \stdClass[]
             */
            protected function fetch_source_issuances(int $limit): array {
                return array_slice($this->source, 0, $limit);
            }

            /**
             * Resolve credentials per course, so grouping can be exercised without the
             * connector plugin (whose category resolver a bare checkout may not have).
             *
             * @param \stdClass $row Tracking row.
             * @return \stdClass|null
             */
            protected function resolve_config(\stdClass $row): ?\stdClass {
                if (!$this->percourse) {
                    return parent::resolve_config($row);
                }
                $courseid = (int) ($row->courseid ?? 0);
                return (object) ['apiurl' => 'https://api.example.com', 'apikey' => 'key-' . $courseid];
            }
        };
        $task->source = $source;
        $task->percourse = $percourse;
        $task->countingclient = $client;
        $task->set_client($client);
        return $task;
    }

    /**
     * Build a sync task with canned source issuances and a canned status map.
     *
     * @param \stdClass[] $source Fake source issuances.
     * @param array $statusmap Map of issueRequestId to status string.
     * @param array $credentialids Optional map of issueRequestId to credentialId.
     * @return \local_credentiumclaim\task\sync_status
     */
    private function make_task(array $source, array $statusmap, array $credentialids = []) {
        $client = new class ('https://api.example.com', 'pub.key', $statusmap, $credentialids) extends apiclient {
            /** @var array Map of issueRequestId to status string. */
            private array $statusmap;
            /** @var array Map of issueRequestId to credentialId. */
            private array $credentialids;

            /**
             * Configure the client double with a canned status map.
             *
             * @param string $url Base URL.
             * @param string $key API key.
             * @param array $statusmap Map of issueRequestId to status string.
             * @param array $credentialids Map of issueRequestId to credentialId.
             */
            public function __construct($url, $key, array $statusmap, array $credentialids = []) {
                parent::__construct($url, $key);
                $this->statusmap = $statusmap;
                $this->credentialids = $credentialids;
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
                        $result = ['issueRequestId' => $id, 'status' => $this->statusmap[$id]];
                        if (isset($this->credentialids[$id])) {
                            $result['credentialId'] = $this->credentialids[$id];
                        }
                        $results[] = $result;
                    }
                }
                return [200, json_encode(['results' => $results]), []];
            }
        };

        $task = new class extends \local_credentiumclaim\task\sync_status {
            /** @var \stdClass[] */
            public array $source = [];

            /**
             * Return the canned source issuances (bounded by the limit).
             *
             * @param int $limit Maximum rows.
             * @return \stdClass[]
             */
            protected function fetch_source_issuances(int $limit): array {
                return array_slice($this->source, 0, $limit);
            }
        };
        $task->source = $source;
        $task->set_client($client);
        return $task;
    }

    /**
     * Execute a task while swallowing its mtrace output.
     *
     * @param \core\task\scheduled_task $task Task to run.
     * @return void
     */
    private function run_task(\core\task\scheduled_task $task): void {
        ob_start();
        $task->execute();
        ob_get_clean();
    }
}
