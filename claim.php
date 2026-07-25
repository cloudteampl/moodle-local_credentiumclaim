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
 * Mints a single-use claim link for one credential and opens it.
 *
 * Invoked as a POST (with sesskey) from a target="_blank" form, so the minted
 * claim URL — a bearer-equivalent secret — travels only in this new tab's
 * redirect header and is never rendered into HTML or logged.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_credentiumclaim\api\client;
use local_credentiumclaim\local\claimable;

require_login();
require_sesskey();

$context = context_user::instance($USER->id);
require_capability('local/credentiumclaim:claim', $context);

$rowid = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/credentiumclaim/claim.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('claim_heading', 'local_credentiumclaim'));

$row = claimable::get_owned_row($USER->id, $rowid);
if ($row === null) {
    throw new moodle_exception('error:credentialnotfound', 'local_credentiumclaim');
}

$message = null;
$messagetype = \core\output\notification::NOTIFY_INFO;

if (!local_credentiumclaim_is_enabled()) {
    // Honour the master kill-switch even if API credentials remain configured.
    $message = get_string('claim_error', 'local_credentiumclaim');
    $messagetype = \core\output\notification::NOTIFY_ERROR;
} else {
    try {
        // Category mode may issue this credential with credentials that differ
        // from the global ones, so resolve them the same way the sync task does.
        $courseid = isset($row->courseid) && $row->courseid !== null ? (int) $row->courseid : null;
        $client = client::for_course($courseid);
        if (!$client->is_configured()) {
            $message = get_string('claim_error', 'local_credentiumclaim');
            $messagetype = \core\output\notification::NOTIFY_ERROR;
        } else {
            $locale = (substr(current_language(), 0, 2) === 'pl') ? 'pl' : 'en';
            $result = $client->get_claim_link($row->credentialkey, $locale);
            switch ($result->actiontype) {
                case client::ACTION_CREATE:
                case client::ACTION_LOGIN:
                    if (!empty($result->claimurl)) {
                        // The user has acted on this credential: stop nagging via the banner.
                        claimable::dismiss($USER->id, (int) $row->id);
                        // The learner is about to claim in Credentium: flag the row so the
                        // next "My credentials" view re-polls it immediately instead of
                        // waiting out the refresher's throttle (or the next cron run).
                        claimable::request_recheck($USER->id, (int) $row->id);
                        // Hand the single-use URL straight to the browser; never render or log it.
                        redirect($result->claimurl);
                    }
                    $message = get_string('claim_error', 'local_credentiumclaim');
                    $messagetype = \core\output\notification::NOTIFY_ERROR;
                    break;

                case client::ACTION_ALREADY:
                    // Already in the wallet: the API now hands back a login-gated link to
                    // *view* it (not to claim again — the wallet fires no claim event on a
                    // repeat visit). Keep the local state honest, then send the learner there.
                    claimable::mark_claimed($USER->id, (int) $row->id);
                    if (!empty($result->claimurl)) {
                        redirect($result->claimurl);
                    }
                    // Older issuer with no view link: a plain confirmation is the best we can do.
                    $message = get_string('claim_alreadyclaimed', 'local_credentiumclaim');
                    $messagetype = \core\output\notification::NOTIFY_SUCCESS;
                    break;

                case client::ACTION_NOTREADY:
                    $message = get_string('claim_notready', 'local_credentiumclaim');
                    $messagetype = \core\output\notification::NOTIFY_INFO;
                    break;

                default:
                    $message = get_string('claim_error', 'local_credentiumclaim');
                    $messagetype = \core\output\notification::NOTIFY_WARNING;
            }
        }
    } catch (moodle_exception $e) {
        // The client already logged a sanitised summary; assume a generic error.
        $message = get_string('claim_error', 'local_credentiumclaim');
        $messagetype = \core\output\notification::NOTIFY_ERROR;

        // Before showing it, ask for the credential's current status once: the most
        // common reason a mint fails is that this credential was claimed a moment
        // ago (a second click on a stale "Claim" button), and that deserves the
        // friendly "already claimed" answer rather than a red error box.
        if (isset($client) && $client->is_configured()) {
            try {
                $statuses = $client->get_status_batch([$row->credentialkey]);
                if (isset($statuses[$row->credentialkey])) {
                    $remote = $statuses[$row->credentialkey]->status;
                    claimable::apply_remote_status($row, $remote);
                    if ($remote === claimable::STATUS_CLAIMED) {
                        $message = get_string('claim_alreadyclaimed', 'local_credentiumclaim');
                        $messagetype = \core\output\notification::NOTIFY_SUCCESS;
                    } else if ($remote === claimable::STATUS_PROCESSING) {
                        $message = get_string('claim_notready', 'local_credentiumclaim');
                        $messagetype = \core\output\notification::NOTIFY_INFO;
                    }
                }
            } catch (moodle_exception $statusfailure) {
                // The status probe failed too: keep the generic error.
                local_credentiumclaim_log('Post-failure status probe failed', ['rowid' => (int) $row->id]);
            }
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('claim_heading', 'local_credentiumclaim'));
echo $OUTPUT->notification($message, $messagetype);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/credentiumclaim/mycredentials.php'),
        get_string('claim_backtolist', 'local_credentiumclaim'),
        ['class' => 'btn btn-secondary']
    )
);
echo $OUTPUT->footer();
