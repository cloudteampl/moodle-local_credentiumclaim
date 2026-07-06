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
        $client = new client();
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
                        // Hand the single-use URL straight to the browser; never render or log it.
                        redirect($result->claimurl);
                    }
                    $message = get_string('claim_error', 'local_credentiumclaim');
                    $messagetype = \core\output\notification::NOTIFY_ERROR;
                    break;

                case client::ACTION_ALREADY:
                    claimable::mark_claimed($USER->id, (int) $row->id);
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
        // The client already logged a sanitised summary; show a generic message.
        $message = get_string('claim_error', 'local_credentiumclaim');
        $messagetype = \core\output\notification::NOTIFY_ERROR;
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
