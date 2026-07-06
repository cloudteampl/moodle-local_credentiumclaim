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
 * Unit tests for the reminder banner renderable.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

/**
 * Tests for {@see \local_credentiumclaim\output\banner}.
 *
 * @covers \local_credentiumclaim\output\banner
 */
final class banner_test extends \advanced_testcase {
    public function test_export_for_template_contains_expected_data(): void {
        global $PAGE;
        $this->resetAfterTest(true);

        // Use a real renderer_base (the early-bootstrap $OUTPUT is not one).
        $renderer = $PAGE->get_renderer('core');
        $banner = new \local_credentiumclaim\output\banner(3);
        $data = $banner->export_for_template($renderer);

        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('sesskey', $data);
        $this->assertStringContainsString('mycredentials.php', $data['ctaurl']);
        $this->assertStringContainsString('dismiss.php', $data['dismissurl']);
        $this->assertStringContainsString('3', $data['message'], 'The count should appear in the message.');
    }
}
