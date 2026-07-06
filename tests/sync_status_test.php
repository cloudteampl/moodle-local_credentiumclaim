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

        // rq-1 issued => banner-worthy for u1.
        $this->assertSame(1, claimable::count_for_user($u1->id));
        // rq-2 claimed => nothing to claim for u2.
        $this->assertSame(0, claimable::count_for_user($u2->id));
        $this->assertCount(0, claimable::list_for_user($u2->id));
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

    /**
     * Build a sync task with canned source issuances and a canned status map.
     *
     * @param \stdClass[] $source Fake source issuances.
     * @param array<string,string> $statusmap issueRequestId => status.
     * @return \local_credentiumclaim\task\sync_status
     */
    private function make_task(array $source, array $statusmap) {
        $client = new class('https://api.example.com', 'pub.key', $statusmap)
            extends \local_credentiumclaim\api\client {
            /** @var array<string,string> */
            private array $statusmap;

            public function __construct($url, $key, array $statusmap) {
                parent::__construct($url, $key);
                $this->statusmap = $statusmap;
            }

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

        $task = new class extends \local_credentiumclaim\task\sync_status {
            /** @var \stdClass[] */
            public array $source = [];

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
