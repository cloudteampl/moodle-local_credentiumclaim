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
 * Unit tests for the learner-facing notification surfaces.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

use local_credentiumclaim\local\claimable;
use local_credentiumclaim\local\hook_callbacks;
use local_credentiumclaim\local\notifier;

/**
 * Tests for the bell notification and the user-menu entry.
 *
 * @covers \local_credentiumclaim\local\notifier
 * @covers \local_credentiumclaim\local\hook_callbacks
 */
final class notify_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('enabled', 1, 'local_credentiumclaim');
    }

    public function test_a_credential_becoming_ready_sends_one_notification(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        $row = $this->row($user->id, 'key-1');

        $sink = $this->redirectMessages();
        $becameready = claimable::apply_remote_status($row, 'issued');
        $this->assertTrue($becameready);
        $this->assertTrue(notifier::credential_ready($row));

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($user->id, $messages[0]->useridto);
        $this->assertSame('local_credentiumclaim', $messages[0]->component);
        $this->assertSame('credentialready', $messages[0]->eventtype);
        $this->assertEquals(1, $messages[0]->notification);
    }

    public function test_notification_names_the_course_when_known(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Astrophysics 101']);
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, (int) $course->id);
        $row = $this->row($user->id, 'key-1');

        $sink = $this->redirectMessages();
        $this->assertTrue(notifier::credential_ready($row));

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Astrophysics 101', $messages[0]->fullmessage);
    }

    public function test_notification_body_links_to_my_credentials(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        $row = $this->row($user->id, 'key-1');

        $sink = $this->redirectMessages();
        $this->assertTrue(notifier::credential_ready($row));

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        // The learner must be able to act straight from the message: a real link in
        // the HTML body, and a spelled-out URL in the plain-text fallback.
        $this->assertStringContainsString('mycredentials.php', $messages[0]->fullmessagehtml);
        $this->assertStringContainsString('<a ', $messages[0]->fullmessagehtml);
        $this->assertStringContainsString('mycredentials.php', $messages[0]->fullmessage);
    }

    public function test_banner_shows_even_when_the_setting_was_never_saved(): void {
        // No set_config('showbanner', ...): the site enabled the plugin but never
        // (re)saved the settings form, which is exactly the state that used to
        // silently disable the banner.
        $this->assertStringContainsString(
            'local-credentiumclaim-banner',
            $this->render_banner_hook(),
            'An unset showbanner must count as enabled.'
        );
    }

    public function test_banner_respects_an_explicit_off_switch(): void {
        set_config('showbanner', 0, 'local_credentiumclaim');
        $this->assertSame('', $this->render_banner_hook());
    }

    /**
     * Run the top-of-body banner hook for a user holding one claimable credential.
     *
     * @return string The HTML the hook injected (empty when suppressed).
     */
    private function render_banner_hook(): string {
        global $OUTPUT, $PAGE;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        claimable::record_candidate($user->id, 'key-banner', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-banner'), 'issued');

        // The hook fires mid-header when $OUTPUT is a real renderer; mirror that
        // (the early-bootstrap $OUTPUT is not one). Restored by resetAfterTest().
        $PAGE->set_url('/');
        $OUTPUT = $PAGE->get_renderer('core');

        $hook = new \core\hook\output\before_standard_top_of_body_html_generation($OUTPUT);
        hook_callbacks::before_standard_top_of_body_html($hook);
        return $hook->get_output();
    }

    public function test_no_notification_for_a_suspended_user(): void {
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);
        claimable::record_candidate($user->id, 'key-1', null, null);
        $row = $this->row($user->id, 'key-1');

        $sink = $this->redirectMessages();
        $this->assertFalse(notifier::credential_ready($row), 'A suspended learner must not be messaged.');
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_user_menu_entry_appears_only_with_a_claimable_credential(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Nothing to claim yet: no menu clutter.
        $empty = new \core_user\hook\extend_user_menu();
        hook_callbacks::extend_user_menu($empty);
        $this->assertCount(0, $empty->get_navitems());

        claimable::record_candidate($user->id, 'key-1', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');

        $hook = new \core_user\hook\extend_user_menu();
        hook_callbacks::extend_user_menu($hook);
        $items = $hook->get_navitems();
        $this->assertCount(1, $items);
        $this->assertSame('link', $items[0]->itemtype);
        $this->assertStringContainsString('(1)', $items[0]->title);
    }

    public function test_user_menu_entry_survives_a_dismissed_banner(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        claimable::record_candidate($user->id, 'key-1', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');

        // Dismissing the banner must not remove the standing menu pointer.
        claimable::dismiss($user->id, $this->row($user->id, 'key-1')->id);

        $hook = new \core_user\hook\extend_user_menu();
        hook_callbacks::extend_user_menu($hook);
        $this->assertCount(1, $hook->get_navitems());
    }

    public function test_no_menu_entry_when_the_plugin_is_disabled(): void {
        set_config('enabled', 0, 'local_credentiumclaim');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        claimable::record_candidate($user->id, 'key-1', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');

        $hook = new \core_user\hook\extend_user_menu();
        hook_callbacks::extend_user_menu($hook);
        $this->assertCount(0, $hook->get_navitems());
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
