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
 * Unit tests for the configurable status-check interval.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

/**
 * Tests for the sync interval helpers in lib.php.
 *
 * @covers ::local_credentiumclaim_apply_sync_interval
 * @covers ::local_credentiumclaim_get_sync_interval
 */
final class sync_interval_test extends \advanced_testcase {
    public function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/local/credentiumclaim/lib.php');
    }

    public function test_default_schedule_is_every_fifteen_minutes(): void {
        $this->assertSame(15, local_credentiumclaim_get_sync_interval());
    }

    /**
     * Every offered interval must survive a write/read round trip.
     *
     * @param int $minutes Interval to apply.
     * @dataProvider interval_provider
     */
    public function test_interval_round_trip(int $minutes): void {
        $this->assertTrue(local_credentiumclaim_apply_sync_interval($minutes));
        $this->assertSame($minutes, local_credentiumclaim_get_sync_interval());
    }

    /**
     * Each selectable interval.
     *
     * @return array[] Rows of [minutes].
     */
    public static function interval_provider(): array {
        $rows = [];
        foreach ([5, 10, 15, 30, 60, 120, 240] as $minutes) {
            $rows['every ' . $minutes . ' minutes'] = [$minutes];
        }
        return $rows;
    }

    public function test_unknown_interval_is_rejected(): void {
        $this->assertFalse(local_credentiumclaim_apply_sync_interval(7));
        // The schedule must be left exactly as it was.
        $this->assertSame(15, local_credentiumclaim_get_sync_interval());
    }

    public function test_hand_edited_schedule_reads_back_as_custom(): void {
        $task = local_credentiumclaim_get_sync_task();
        $task->set_minute('7');
        $task->set_hour('3');
        $task->set_customised(true);
        \core\task\manager::configure_scheduled_task($task);

        $this->assertNull(
            local_credentiumclaim_get_sync_interval(),
            'A schedule that is not a simple interval must not be misreported as one.'
        );
    }

    public function test_applying_an_interval_marks_the_task_customised(): void {
        local_credentiumclaim_apply_sync_interval(5);

        $task = local_credentiumclaim_get_sync_task();
        $this->assertTrue(
            $task->is_customised(),
            'A plugin upgrade must not silently reset an admin-chosen interval.'
        );
    }
}
