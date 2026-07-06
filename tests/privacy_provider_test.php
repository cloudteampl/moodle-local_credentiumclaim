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
 * Privacy provider tests.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_credentiumclaim\local\claimable;
use local_credentiumclaim\privacy\provider;

/**
 * Tests for {@see \local_credentiumclaim\privacy\provider}.
 *
 * @covers \local_credentiumclaim\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_get_contexts_for_userid_returns_user_context(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'k1', null, null);

        $contextids = provider::get_contexts_for_userid($user->id)->get_contextids();
        $expected = \context_user::instance($user->id)->id;

        $this->assertEqualsCanonicalizing([$expected], $contextids);
    }

    public function test_export_user_data_writes_credentials(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'k1', null, null);
        $usercontext = \context_user::instance($user->id);

        $this->export_context_data_for_user($user->id, $usercontext, 'local_credentiumclaim');

        $this->assertTrue(writer::with_context($usercontext)->has_any_data());
    }

    public function test_delete_data_for_user(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'k1', null, null);
        $usercontext = \context_user::instance($user->id);

        provider::delete_data_for_user(
            new approved_contextlist($user, 'local_credentiumclaim', [$usercontext->id])
        );

        $this->assertSame(0, $DB->count_records(claimable::TABLE, ['userid' => $user->id]));
    }

    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        claimable::record_candidate($u1->id, 'k1', null, null);
        claimable::record_candidate($u2->id, 'k2', null, null);

        provider::delete_data_for_all_users_in_context(\context_user::instance($u1->id));

        $this->assertSame(0, $DB->count_records(claimable::TABLE, ['userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records(claimable::TABLE, ['userid' => $u2->id]));
    }

    public function test_get_users_in_context_and_delete_for_users(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'k1', null, null);
        $usercontext = \context_user::instance($user->id);

        $userlist = new userlist($usercontext, 'local_credentiumclaim');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$user->id], $userlist->get_userids());

        provider::delete_data_for_users(
            new approved_userlist($usercontext, 'local_credentiumclaim', $userlist->get_userids())
        );
        $this->assertSame(0, $DB->count_records(claimable::TABLE, ['userid' => $user->id]));
    }

    public function test_user_deleted_observer_removes_rows(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'k1', null, null);

        delete_user($user);

        $this->assertSame(0, $DB->count_records(claimable::TABLE, ['userid' => $user->id]));
    }
}
