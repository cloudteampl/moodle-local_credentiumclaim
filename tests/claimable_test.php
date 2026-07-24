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
 * Unit tests for the claimable service.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

use local_credentiumclaim\local\claimable;

/**
 * Tests for {@see \local_credentiumclaim\local\claimable}.
 *
 * @covers \local_credentiumclaim\local\claimable
 */
final class claimable_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_candidate_seeds_processing_and_is_not_countable(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue(claimable::record_candidate($user->id, 'key-1', 10, 5));
        $this->assertSame(0, claimable::count_for_user($user->id), 'A processing credential is not banner-worthy.');
        $this->assertFalse(claimable::record_candidate($user->id, 'key-1', 10, 5), 'Duplicate must be ignored.');
    }

    public function test_issued_makes_countable_and_dismiss_hides_it(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);

        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');
        $this->assertSame(1, claimable::count_for_user($user->id));

        claimable::dismiss($user->id, $this->row($user->id, 'key-1')->id);
        $this->assertSame(0, claimable::count_for_user($user->id), 'Dismissed credential is not banner-worthy.');
    }

    public function test_claimed_is_removed_from_count_and_list(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');

        $this->assertCount(1, claimable::list_for_user($user->id));

        claimable::mark_claimed($user->id, $this->row($user->id, 'key-1')->id);
        $this->assertSame(0, claimable::count_for_user($user->id));
        $this->assertCount(0, claimable::list_for_user($user->id));
    }

    public function test_cache_is_invalidated_on_status_change(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);

        // Prime the cache with the current (processing => 0) value.
        $this->assertSame(0, claimable::count_for_user($user->id));

        // A status change must invalidate the cache, so the next read reflects it.
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');
        $this->assertSame(1, claimable::count_for_user($user->id));
    }

    public function test_claimable_count_ignores_dismissal(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);
        claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued');

        claimable::dismiss($user->id, $this->row($user->id, 'key-1')->id);

        // The banner honours dismissal; the persistent menu count deliberately does not.
        $this->assertSame(0, claimable::count_for_user($user->id), 'Banner count drops after dismissal.');
        $this->assertSame(
            1,
            claimable::count_claimable_for_user($user->id),
            'The standing menu pointer must survive a dismissed banner.'
        );
    }

    public function test_apply_remote_status_signals_only_a_fresh_issued_transition(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);

        // The processing-to-issued transition is the claim-me moment.
        $this->assertTrue(claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued'));
        // Re-polling an already-issued row must not re-signal, or the learner is spammed.
        $this->assertFalse(claimable::apply_remote_status($this->row($user->id, 'key-1'), 'issued'));
        // Issued-to-claimed is a change, but not into the claimable state.
        $this->assertFalse(claimable::apply_remote_status($this->row($user->id, 'key-1'), 'claimed'));
    }

    public function test_apply_remote_status_ignores_a_stale_caller_snapshot(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'key-1', null, null);

        // Two concurrent pollers (cron and the page-load refresher) both read the row
        // while it still said "processing", then both learn "issued" from the API.
        // The transition must be decided against the database, not the caller's
        // snapshot, or the learner would be notified twice.
        $stalesnapshot = $this->row($user->id, 'key-1');

        $this->assertTrue(claimable::apply_remote_status($stalesnapshot, 'issued'));
        $this->assertFalse(
            claimable::apply_remote_status($stalesnapshot, 'issued'),
            'The second writer, still holding the processing snapshot, must not re-signal.'
        );
    }

    public function test_pollable_excludes_terminal_statuses(): void {
        $user = $this->getDataGenerator()->create_user();
        claimable::record_candidate($user->id, 'k-proc', null, null);
        claimable::record_candidate($user->id, 'k-issued', null, null);
        claimable::record_candidate($user->id, 'k-claimed', null, null);
        claimable::apply_remote_status($this->row($user->id, 'k-issued'), 'issued');
        claimable::apply_remote_status($this->row($user->id, 'k-claimed'), 'claimed');

        $pollable = claimable::get_pollable();
        $keys = array_map(fn($r) => $r->credentialkey, $pollable);
        sort($keys);
        $this->assertSame(['k-issued', 'k-proc'], $keys);
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
