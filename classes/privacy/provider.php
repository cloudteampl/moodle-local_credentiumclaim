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
 * Privacy provider for local_credentiumclaim.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider implementation for local_credentiumclaim.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string Tracking table. */
    private const TABLE = 'local_credentiumclaim_status';

    /**
     * Describe the personal data stored and disclosed by this plugin.
     *
     * @param collection $collection The metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            self::TABLE,
            [
                'userid' => 'privacy:metadata:local_credentiumclaim_status:userid',
                'credentialkey' => 'privacy:metadata:local_credentiumclaim_status:credentialkey',
                'credentialid' => 'privacy:metadata:local_credentiumclaim_status:credentialid',
                'courseid' => 'privacy:metadata:local_credentiumclaim_status:courseid',
                'remotestatus' => 'privacy:metadata:local_credentiumclaim_status:remotestatus',
                'timecreated' => 'privacy:metadata:local_credentiumclaim_status:timecreated',
            ],
            'privacy:metadata:local_credentiumclaim_status'
        );

        $collection->add_external_location_link(
            'credentium_api',
            [
                'issuerequestid' => 'privacy:metadata:credentium_api:issuerequestid',
                'locale' => 'privacy:metadata:credentium_api:locale',
            ],
            'privacy:metadata:credentium_api'
        );

        return $collection;
    }

    /**
     * Contexts containing data for a user. Data lives in the user's own context.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {" . self::TABLE . "} ccs ON ccs.userid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel AND ccs.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_USER,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Users who have data within a context.
     *
     * @param userlist $userlist The userlist to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $sql = "SELECT userid FROM {" . self::TABLE . "} WHERE userid = :userid";
        $userlist->add_from_sql('userid', $sql, ['userid' => $context->instanceid]);
    }

    /**
     * Export all tracked credentials for the approved user contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_USER || $context->instanceid != $userid) {
                continue;
            }
            $records = $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated ASC');
            if (empty($records)) {
                continue;
            }
            $data = [];
            foreach ($records as $record) {
                $coursename = '';
                if (!empty($record->courseid)) {
                    $fullname = $DB->get_field('course', 'fullname', ['id' => $record->courseid]);
                    $coursename = ($fullname !== false) ? $fullname : '';
                }
                $data[] = [
                    'coursename' => $coursename,
                    'credentialkey' => $record->credentialkey,
                    'credentialid' => $record->credentialid,
                    'status' => $record->remotestatus,
                    'timecreated' => transform::datetime($record->timecreated),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_credentiumclaim')],
                (object) ['credentials' => $data]
            );
        }
    }

    /**
     * Delete all data for all users in a context.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $DB->delete_records(self::TABLE, ['userid' => $context->instanceid]);
        \local_credentiumclaim\local\claimable::purge_cache((int) $context->instanceid);
    }

    /**
     * Delete all data for a user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $userid) {
                $DB->delete_records(self::TABLE, ['userid' => $userid]);
                \local_credentiumclaim\local\claimable::purge_cache((int) $userid);
            }
        }
    }

    /**
     * Delete data for multiple users within a context.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids) || !in_array($context->instanceid, $userids)) {
            return;
        }
        $DB->delete_records(self::TABLE, ['userid' => $context->instanceid]);
        \local_credentiumclaim\local\claimable::purge_cache((int) $context->instanceid);
    }
}
