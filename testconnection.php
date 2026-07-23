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
 * Tests connectivity and authentication against the Credentium API.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_credentiumclaim\api\client;

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

$PAGE->set_url(new moodle_url('/local/credentiumclaim/testconnection.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('testconnection_heading', 'local_credentiumclaim'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('testconnection_heading', 'local_credentiumclaim'));

try {
    $client = new client();
    if (!$client->is_configured()) {
        echo $OUTPUT->notification(
            get_string('testconnection_disabled', 'local_credentiumclaim'),
            \core\output\notification::NOTIFY_WARNING
        );
    } else {
        $templates = $client->get_templates();
        echo $OUTPUT->notification(
            get_string('testconnection_success', 'local_credentiumclaim'),
            \core\output\notification::NOTIFY_SUCCESS
        );
        echo html_writer::tag(
            'p',
            get_string('testconnection_templatecount', 'local_credentiumclaim', count($templates))
        );

        // The template probe only proves the templates:read scope, which the issuing
        // plugin needs. This plugin lives on credentials:read, so probe that too —
        // otherwise an inherited key that can issue but not read would test "successful"
        // and then fail on every status check. A status query for an id that cannot
        // exist is answered with an empty result set, so this costs nothing.
        $client->get_status_batch(['00000000-0000-0000-0000-000000000000']);
        $readerror = $client->get_last_error();
        if ($readerror === null) {
            echo $OUTPUT->notification(
                get_string('testconnection_readscope_ok', 'local_credentiumclaim'),
                \core\output\notification::NOTIFY_SUCCESS
            );
        } else {
            echo $OUTPUT->notification(
                get_string('testconnection_readscope_fail', 'local_credentiumclaim', s($readerror)),
                \core\output\notification::NOTIFY_ERROR
            );
        }
    }
} catch (moodle_exception $e) {
    // Covers both a bad stored URL (constructor) and an API/auth failure.
    echo $OUTPUT->notification(
        get_string('testconnection_fail', 'local_credentiumclaim'),
        \core\output\notification::NOTIFY_ERROR
    );
}

echo $OUTPUT->footer();
