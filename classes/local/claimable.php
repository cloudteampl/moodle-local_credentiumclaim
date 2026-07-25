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

    /** @var string Cache-key prefix for the dismiss-independent claimable count. */
    private const ALL_CACHE_PREFIX = 'all';

    /** @var string Cache-key prefix for the count of credentials the page will list. */
    private const VISIBLE_CACHE_PREFIX = 'visible';

    /** @var int Column width of credentialid, per db/install.xml. */
    private const CREDENTIALID_MAX = 255;

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
     * Cached count of claimable credentials for a user, ignoring the dismissed flag.
     *
     * The banner honours "dismissed" so it can be closed; the persistent user-menu
     * entry deliberately does not, so a learner who dismissed the banner still has a
     * standing, countable pointer to what they can claim.
     *
     * @param int $userid User id.
     * @return int
     */
    public static function count_claimable_for_user(int $userid): int {
        $cache = \cache::make('local_credentiumclaim', 'claimable');
        $key = self::ALL_CACHE_PREFIX . $userid;
        $cached = $cache->get($key);
        if ($cached !== false) {
            return (int) $cached;
        }
        $count = self::query_claimable_count($userid);
        $cache->set($key, $count);
        return $count;
    }

    /**
     * Cached count of credentials the "My credentials" page would list for a user.
     *
     * Drives whether the page is reachable at all. Counting only *claimable*
     * credentials made the user-menu entry vanish the moment a learner claimed the
     * last one — taking away the only route back to the credentials they had just
     * collected. Learners who have never been issued anything still see no entry.
     *
     * @param int $userid User id.
     * @return int
     */
    public static function count_visible_for_user(int $userid): int {
        $cache = \cache::make('local_credentiumclaim', 'claimable');
        $key = self::VISIBLE_CACHE_PREFIX . $userid;
        $cached = $cache->get($key);
        if ($cached !== false) {
            return (int) $cached;
        }
        $count = self::query_visible_count($userid);
        $cache->set($key, $count);
        return $count;
    }

    /**
     * Uncached count of the rows {@see self::list_for_user()} would return.
     *
     * @param int $userid User id.
     * @return int
     */
    protected static function query_visible_count(int $userid): int {
        global $DB;
        try {
            return $DB->count_records_select(
                self::TABLE,
                'userid = :userid AND remotestatus <> :failed',
                ['userid' => $userid, 'failed' => self::STATUS_FAILED]
            );
        } catch (\dml_exception $e) {
            // Never break page rendering because of this plugin.
            return 0;
        }
    }

    /**
     * Uncached count of issued (claimable) credentials, ignoring dismissal.
     *
     * @param int $userid User id.
     * @return int
     */
    protected static function query_claimable_count(int $userid): int {
        global $DB;
        try {
            return $DB->count_records_select(
                self::TABLE,
                'userid = :userid AND remotestatus = :status',
                ['userid' => $userid, 'status' => self::STATUS_ISSUED]
            );
        } catch (\dml_exception $e) {
            // Never break page rendering because of this plugin.
            return 0;
        }
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
     * Everything the "My credentials" page shows a user.
     *
     * Includes claimed credentials: hiding them turned the page into a to-do list
     * that emptied itself, so a learner who had collected everything was told they
     * had nothing — and had no way back to what they had just earned. Failed
     * issuances stay out: the learner can do nothing about one, and the admin
     * report already accounts for them.
     *
     * Ordered by what the learner can act on: ready to claim first, then still
     * processing, then the collected ones, each group most-recent first.
     *
     * @param int $userid User id.
     * @return \stdClass[] Rows in display order.
     */
    public static function list_for_user(int $userid): array {
        global $DB;
        // CASE rather than a PHP sort: the ordering is part of the query's contract,
        // and the row count here is per-user and small either way.
        $order = "CASE remotestatus
                       WHEN :issuedorder THEN 0
                       WHEN :claimedorder THEN 2
                       ELSE 1
                  END ASC, timemodified DESC";
        return $DB->get_records_select(
            self::TABLE,
            'userid = :userid AND remotestatus <> :failed',
            [
                'userid' => $userid,
                'failed' => self::STATUS_FAILED,
                'issuedorder' => self::STATUS_ISSUED,
                'claimedorder' => self::STATUS_CLAIMED,
            ],
            $order
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
            // Most likely a concurrent run (cron and the manual "Check status now" can
            // overlap) inserted the same credential between the check above and this
            // insert; the unique key on (userid, credentialkey) makes that harmless.
            // Anything else is a real write failure and must not vanish silently.
            if (!$DB->record_exists(self::TABLE, ['userid' => $userid, 'credentialkey' => $credentialkey])) {
                require_once(__DIR__ . '/../../lib.php');
                local_credentiumclaim_log('Failed to track credential', [
                    'userid' => $userid,
                    'credentialkey' => $credentialkey,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
            return false;
        }
        self::purge_cache($userid);
        return true;
    }

    /**
     * Apply a freshly polled remote status to a tracking row.
     *
     * Two writers can race here: the cron sync and the page-load refresher both
     * follow "read row, ask the API, apply", so the transition into "issued" — the
     * one outcome that triggers a notification — must be observed by exactly one
     * of them. The cost of guaranteeing that is tiered, because cron applies up to
     * 1000 statuses per run and locking every write would multiply its round-trips:
     *
     * 1. Incoming status is not "issued": no notification can possibly result, so
     *    a single guarded UPDATE suffices (no lock, no extra read).
     * 2. Incoming "issued" and the caller's snapshot already says "issued": a
     *    snapshot can only ever lag the database, so the transition provably
     *    happened in the past and was some earlier writer's to signal. One UPDATE.
     * 3. Otherwise — a candidate transition into "issued" — the decision is made
     *    against a fresh read under a per-row lock, so exactly one of two
     *    concurrent writers observes it. This fires roughly once per credential
     *    lifetime, keeping steady-state cron traffic entirely lock-free.
     *
     * Because tiers 1 and 2 write without the lock, every write (including tier
     * 3's) goes through {@see self::write_status()}, which is monotonic: it never
     * regresses a more-advanced stored state. A late "issued" answer arriving
     * after a concurrent writer stored "claimed" therefore cannot resurrect the
     * credential, and tier 3 only signals when the fresh read shows a pre-issued
     * state — never when the moment has already passed.
     *
     * The caller's $row must include id and userid; remotestatus, when present, is
     * only a fast-path hint — a stale or missing value costs an unnecessary lock,
     * never a wrong decision.
     *
     * @param \stdClass $row Existing row (must include id and userid).
     * @param string $status Raw Credentium status.
     * @param string|null $credentialid Credentium credentialId, when the poll reported one.
     * @return bool True when the credential has just become claimable (a fresh
     *              transition into "issued"), so the caller can notify the learner once.
     */
    public static function apply_remote_status(\stdClass $row, string $status, ?string $credentialid = null): bool {
        global $DB;
        $normalized = self::normalize_status($status);
        $snapshot = $row->remotestatus ?? null;

        self::remember_credential_id($row, $credentialid);

        if ($normalized !== self::STATUS_ISSUED) {
            // Tier 1: never a claim-me moment, regardless of races. A missing
            // snapshot purges defensively — an extra purge is harmless, a missed
            // one would leave the banner count stale.
            self::write_status($row, $normalized, $snapshot !== $normalized);
            return false;
        }

        if ($snapshot === self::STATUS_ISSUED) {
            // Tier 2: already issued when the caller read the row, so the
            // transition (and its one notification) belongs to the past.
            self::write_status($row, $normalized, false);
            return false;
        }

        // Tier 3: candidate transition into "issued".
        $factory = \core\lock\lock_config::get_lock_factory('local_credentiumclaim_status');
        // Held for milliseconds; the short max lifetime just stops a killed process
        // from wedging this row for the default 24h on DB/Redis lock factories.
        $lock = $factory->get_lock('row_' . (int) $row->id, 3, 60);
        if (!$lock) {
            // Another process is applying a status to this row right now; its
            // result is at least as fresh as ours, so leave the outcome to it.
            return false;
        }
        try {
            $current = $DB->get_field(self::TABLE, 'remotestatus', ['id' => $row->id]);
            if ($current === false) {
                // The row vanished (e.g. the user was deleted mid-poll).
                return false;
            }
            self::write_status($row, $normalized, $current !== $normalized);
            if (!in_array($current, [self::STATUS_PROCESSING, self::STATUS_UNKNOWN], true)) {
                // Not an upward transition from a pre-issued state: a fresh read of
                // "issued" means a concurrent writer beat us to the moment, and
                // "claimed"/"failed" mean the moment has already passed.
                return false;
            }
            // One residual window remains: a lock-free terminal write can land
            // between the read above and our guarded write (which then keeps it).
            // Confirm "issued" actually stuck before signalling the claim-me
            // moment — one extra read, only ever on this once-per-credential path.
            return $DB->get_field(self::TABLE, 'remotestatus', ['id' => $row->id]) === self::STATUS_ISSUED;
        } finally {
            $lock->release();
        }
    }

    /**
     * Store the Credentium credentialId a poll reported, if it is news.
     *
     * Kept for traceability: it names the credential itself rather than the request
     * that produced it, which is what Credentium support asks for. The wallet link is
     * not built from it — the plugin mints that link through the API (see claim.php),
     * it never composes a wallet URL itself.
     *
     * Deliberately outside {@see self::write_status()}: the status write is a
     * carefully guarded, monotonic statement and this value needs none of that.
     * A credentialId is assigned once by Credentium and never changes, so a plain
     * conditional write is both correct under the same races and cheap — no write
     * at all in the steady state, where the row already has it.
     *
     * @param \stdClass $row Tracking row (must include id).
     * @param string|null $credentialid Value reported by the API, if any.
     * @return void
     */
    private static function remember_credential_id(\stdClass $row, ?string $credentialid): void {
        global $DB;
        if ($credentialid === null || $credentialid === '') {
            return;
        }
        if (($row->credentialid ?? null) === $credentialid) {
            return;
        }
        if (\core_text::strlen($credentialid) > self::CREDENTIALID_MAX) {
            // Longer than the column: storing it would throw and abort the whole poll
            // for a value that cannot be a Credentium identifier anyway. Truncating
            // would keep a value that no longer identifies anything.
            require_once(__DIR__ . '/../../lib.php');
            local_credentiumclaim_log('Ignoring an oversized credentialId', ['rowid' => (int) $row->id]);
            return;
        }
        $DB->set_field(self::TABLE, 'credentialid', $credentialid, ['id' => $row->id]);
        $row->credentialid = $credentialid;
    }

    /**
     * Persist a polled status onto a row and optionally invalidate cached counts.
     *
     * The write is monotonic: a stored state is never regressed by a
     * less-advanced incoming one (see {@see self::states_above()}), because
     * callers race without a common lock and a slow API answer can describe the
     * past. The poll bookkeeping (timechecked/timemodified) is stamped either
     * way — the check did happen, its answer was merely out of date.
     *
     * @param \stdClass $row Tracking row (must include id and userid).
     * @param string $normalized Normalised status to store.
     * @param bool $purge Whether the user's cached counts must be invalidated.
     * @return void
     */
    private static function write_status(\stdClass $row, string $normalized, bool $purge): void {
        global $DB;
        $now = time();
        $higher = self::states_above($normalized);
        if (empty($higher)) {
            // Terminal incoming state: nothing outranks it, plain write.
            $DB->update_record(self::TABLE, (object) [
                'id' => $row->id,
                'remotestatus' => $normalized,
                'timechecked' => $now,
                'timemodified' => $now,
            ]);
        } else {
            [$insql, $inparams] = $DB->get_in_or_equal($higher, SQL_PARAMS_NAMED, 'keep');
            $sql = "UPDATE {" . self::TABLE . "}
                       SET remotestatus = CASE WHEN remotestatus $insql
                                               THEN remotestatus ELSE :newstatus END,
                           timechecked = :timechecked,
                           timemodified = :timemodified
                     WHERE id = :id";
            $DB->execute($sql, $inparams + [
                'newstatus' => $normalized,
                'timechecked' => $now,
                'timemodified' => $now,
                'id' => $row->id,
            ]);
        }
        if ($purge) {
            self::purge_cache((int) $row->userid);
        }
    }

    /**
     * The states a given status must never overwrite.
     *
     * Progression rank: processing/unknown (0) < issued (1) < claimed/failed (2).
     * A write may keep or advance the rank, never lower it; the two terminal
     * states may replace each other, since the remote is the source of truth.
     *
     * @param string $status Normalised status about to be written.
     * @return string[] Statuses that outrank it.
     */
    private static function states_above(string $status): array {
        $rank = [
            self::STATUS_PROCESSING => 0,
            self::STATUS_UNKNOWN => 0,
            self::STATUS_ISSUED => 1,
            self::STATUS_CLAIMED => 2,
            self::STATUS_FAILED => 2,
        ];
        $own = $rank[$status] ?? 0;
        return array_keys(array_filter($rank, static fn(int $r): bool => $r > $own));
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
     * Ask for this row to be re-polled at the next opportunity.
     *
     * Zeroing `timechecked` makes the row look maximally stale, so the page-load
     * refresher picks it up immediately instead of honouring its usual per-row
     * throttle. Used right after a claim link is minted: the learner is about to
     * claim, and the next look at "My credentials" should reflect that promptly.
     *
     * @param int $userid User id.
     * @param int $rowid Row id.
     * @return void
     */
    public static function request_recheck(int $userid, int $rowid): void {
        global $DB;
        $row = self::get_owned_row($userid, $rowid);
        if ($row === null) {
            return;
        }
        $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'timechecked' => 0]);
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
        $cache = \cache::make('local_credentiumclaim', 'claimable');
        // The dismiss-aware (banner), dismiss-independent (menu badge) and
        // page-reachability counts all move together.
        $cache->delete($userid);
        $cache->delete(self::ALL_CACHE_PREFIX . $userid);
        $cache->delete(self::VISIBLE_CACHE_PREFIX . $userid);
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
