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
 * Sends learner-facing notifications for the local_credentiumclaim plugin.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\local;

/**
 * Wraps Moodle's Message API so the sync task can nudge a learner exactly once,
 * the moment one of their credentials becomes ready to claim.
 */
class notifier {
    /** @var string Message provider name (matches db/messages.php). */
    public const PROVIDER = 'credentialready';

    /**
     * Notify the owner of a tracking row that their credential is ready to claim.
     *
     * Delivered through the bell (popup) and email, per the learner's messaging
     * preferences. Failure is swallowed and logged: a notification hiccup must never
     * abort a cron sync mid-run.
     *
     * @param \stdClass $row Tracking row (must include userid; courseid optional).
     * @return bool True when a message was handed to the Message API.
     */
    public static function credential_ready(\stdClass $row): bool {
        global $DB;

        // The whole body is guarded: a notification is best-effort (the banner and the
        // user-menu entry surface the credential regardless), and must never abort a
        // cron sync mid-batch. Any failure is logged unconditionally so it leaves a trail.
        try {
            $user = \core_user::get_user((int) $row->userid);
            if (!$user || $user->deleted || !empty($user->suspended)) {
                return false;
            }

            $coursename = '';
            if (!empty($row->courseid)) {
                $fetched = $DB->get_field('course', 'fullname', ['id' => $row->courseid]);
                if ($fetched !== false) {
                    // Explicit system context so formatting never depends on $PAGE state.
                    $coursename = format_string($fetched, true, ['context' => \context_system::instance()]);
                }
            }

            $url = new \moodle_url('/local/credentiumclaim/mycredentials.php');
            $body = ($coursename !== '')
                ? get_string('message_ready_body_course', 'local_credentiumclaim', (object) ['course' => $coursename])
                : get_string('message_ready_body', 'local_credentiumclaim');

            $message = new \core\message\message();
            $message->component = 'local_credentiumclaim';
            $message->name = self::PROVIDER;
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->notification = 1;
            $message->courseid = !empty($row->courseid) ? (int) $row->courseid : SITEID;
            $message->subject = get_string('message_ready_subject', 'local_credentiumclaim');
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = \html_writer::tag('p', $body);
            $message->smallmessage = get_string('message_ready_small', 'local_credentiumclaim');
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('nav_mycredentials', 'local_credentiumclaim');

            return message_send($message) !== false;
        } catch (\Throwable $e) {
            debugging('[CredentiumClaim] credential-ready notification failed for user '
                . (int) $row->userid . ': ' . $e->getMessage(), DEBUG_NORMAL);
            return false;
        }
    }
}
