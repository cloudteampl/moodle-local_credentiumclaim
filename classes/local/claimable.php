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
 * Service for reading and mutating per-user credential claim state.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\local;

/**
 * Reads and mutates rows in {local_credentiumclaim_status}, with a cached
 * per-user count of banner-worthy (issued, not-dismissed) credentials.
 */
class claimable {
    /** @var string Tracking table. */
    public const TABLE = 'local_credentiumclaim_status';

    /** @var string Remote status: still being issued. */
    public const STATUS_PROCESSING = 'processing';
    /** @var string Remote status: issued and ready to claim. */
    public const STATUS_ISSUED = 'issued';
    /** @var string Remote status: claimed by the recipient. */
    public const STATUS_CLAIMED = 'claimed';
    /** @var string Remote status: issuance failed. */
    public const STATUS_FAILED = 'failed';
    /** @var string Remote status: unclassifiable. */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Cached count of banner-worthy credentials for a user.
     *
     * Banner-worthy means remotestatus = issued and not dismissed.
     *
     * @param int $userid User id.
     * @return int
     */
    public static function count_for_user(int $userid): int {
        $cache = \cache::make('local_credentiumclaim', 'claimable');
        $cached = $cache->get($userid);
        if ($cached !== false) {
            return (int)$cached;
        }
        $count = self::query_count($userid);
        $cache->set($userid, $count);
        return $count;
    }

    /**
     * Uncached count query, defensive against a missing table (e.g. mid-upgrade).
     *
     * @param int $userid User id.
     * @return int
     */
    protected static function query_count(int $userid): int {
        global $DB;
        try {
            return $DB->count_records_select(
                self::TABLE,
                'userid = :userid AND remotestatus = :status AND dismissed = 0',
                ['userid' => $userid, 'status' => self::STATUS_ISSUED]
            );
        } catch (\dml_exception $e) {
            // Never break page rendering because of this plugin.
            return 0;
        }
    }

    /**
     * All non-terminal credentials for a user (for the "My credentials" page).
     *
     * @param int $userid User id.
     * @return \stdClass[] Rows ordered most-recent first.
     */
    public static function list_for_user(int $userid): array {
        global $DB;
        return $DB->get_records_select(
            self::TABLE,
            'userid = :userid AND remotestatus <> :claimed AND remotestatus <> :failed',
            ['userid' => $userid, 'claimed' => self::STATUS_CLAIMED, 'failed' => self::STATUS_FAILED],
            'timemodified DESC'
        );
    }

    /**
     * A single tracking row scoped to its owner, or null.
     *
     * @param int $userid User id.
     * @param int $rowid Row id.
     * @return \stdClass|null
     */
    public static function get_owned_row(int $userid, int $rowid): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $rowid, 'userid' => $userid]);
        return $row ?: null;
    }

    /**
     * Rows that still need remote status polling (not claimed/failed).
     *
     * @param int $limit Max rows (0 = no limit).
     * @return \stdClass[]
     */
    public static function get_pollable(int $limit = 0): array {
        global $DB;
        return $DB->get_records_select(
            self::TABLE,
            'remotestatus <> :claimed AND remotestatus <> :failed',
            ['claimed' => self::STATUS_CLAIMED, 'failed' => self::STATUS_FAILED],
            'timechecked ASC',
            '*',
            0,
            $limit
        );
    }

    /**
     * Insert a newly discovered credential candidate if not already tracked.
     *
     * Seeds remotestatus = processing; the same sync run then confirms the true status.
     *
     * @param int $userid User id.
     * @param string $credentialkey Credentium issueRequestId.
     * @param int|null $issuanceid Source local_credentium_issuances.id.
     * @param int|null $courseid Course id.
     * @return bool True if a new row was inserted.
     */
    public static function record_candidate(int $userid, string $credentialkey, ?int $issuanceid, ?int $courseid): bool {
        global $DB;
        if ($DB->record_exists(self::TABLE, ['userid' => $userid, 'credentialkey' => $credentialkey])) {
            return false;
        }
        $now = time();
        $record = (object) [
            'userid' => $userid,
            'credentialkey' => $credentialkey,
            'issuanceid' => $issuanceid,
            'courseid' => $courseid,
            'remotestatus' => self::STATUS_PROCESSING,
            'dismissed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timechecked' => 0,
        ];
        try {
            $DB->insert_record(self::TABLE, $record);
        } catch (\dml_write_exception $e) {
            // A concurrent run (cron and the manual "Check status now" can overlap)
            // inserted the same credential between the check above and this insert.
            // The unique key on (userid, credentialkey) makes that harmless.
            return false;
        }
        self::purge_cache($userid);
        return true;
    }

    /**
     * Apply a freshly polled remote status to a tracking row.
     *
     * @param \stdClass $row Existing row (must include id, userid, remotestatus).
     * @param string $status Raw Credentium status.
     * @return void
     */
    public static function apply_remote_status(\stdClass $row, string $status): void {
        global $DB;
        $normalized = self::normalize_status($status);
        $now = time();
        $DB->update_record(self::TABLE, (object) [
            'id' => $row->id,
            'remotestatus' => $normalized,
            'timechecked' => $now,
            'timemodified' => $now,
        ]);
        if ($row->remotestatus !== $normalized) {
            self::purge_cache((int) $row->userid);
        }
    }

    /**
     * Stamp rows as checked without changing their status.
     *
     * Used for credentials the API did not report on. Without this the rows would keep
     * `timechecked = 0`, and since polling is ordered by `timechecked ASC` they would
     * occupy the head of the queue on every run and starve everything behind them.
     *
     * @param int[] $rowids Row ids to stamp.
     * @return void
     */
    public static function mark_checked(array $rowids): void {
        global $DB;
        $rowids = array_values(array_unique(array_map('intval', $rowids)));
        if (empty($rowids)) {
            return;
        }
        $now = time();
        // Chunked to stay clear of the 1000-item limit some databases place on IN ().
        foreach (array_chunk($rowids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'id');
            $params['now'] = $now;
            $DB->execute(
                'UPDATE {' . self::TABLE . '} SET timechecked = :now WHERE id ' . $insql,
                $params
            );
        }
    }

    /**
     * Suppress the banner for one credential (explicit dismiss or after the user clicks claim).
     *
     * @param int $userid User id.
     * @param int $rowid Row id.
     * @return void
     */
    public static function dismiss(int $userid, int $rowid): void {
        global $DB;
        $row = self::get_owned_row($userid, $rowid);
        if ($row === null || $row->dismissed) {
            return;
        }
        $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'dismissed' => 1, 'timemodified' => time()]);
        self::purge_cache($userid);
    }

    /**
     * Suppress the banner for all of a user's currently issued credentials.
     *
     * New credentials issued later are not affected, so the banner can return.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function dismiss_all(int $userid): void {
        global $DB;
        $DB->set_field_select(
            self::TABLE,
            'dismissed',
            1,
            'userid = :userid AND remotestatus = :status AND dismissed = 0',
            ['userid' => $userid, 'status' => self::STATUS_ISSUED]
        );
        self::purge_cache($userid);
    }

    /**
     * Mark a credential as claimed locally (e.g. Credentium reported already_claimed).
     *
     * @param int $userid User id.
     * @param int $rowid Row id.
     * @return void
     */
    public static function mark_claimed(int $userid, int $rowid): void {
        global $DB;
        $row = self::get_owned_row($userid, $rowid);
        if ($row === null) {
            return;
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $row->id,
            'remotestatus' => self::STATUS_CLAIMED,
            'timemodified' => time(),
        ]);
        self::purge_cache($userid);
    }

    /**
     * Invalidate the cached count for a user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function purge_cache(int $userid): void {
        \cache::make('local_credentiumclaim', 'claimable')->delete($userid);
    }

    /**
     * Map a raw Credentium status onto the plugin's status vocabulary.
     *
     * @param string $status Raw status.
     * @return string One of the STATUS_* constants.
     */
    private static function normalize_status(string $status): string {
        $known = [self::STATUS_PROCESSING, self::STATUS_ISSUED, self::STATUS_CLAIMED, self::STATUS_FAILED];
        return in_array($status, $known, true) ? $status : self::STATUS_UNKNOWN;
    }
}
