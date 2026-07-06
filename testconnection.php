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

$client = new client();
if (!$client->is_configured()) {
    echo $OUTPUT->notification(get_string('testconnection_disabled', 'local_credentiumclaim'),
        \core\output\notification::NOTIFY_WARNING);
} else {
    try {
        $templates = $client->get_templates();
        echo $OUTPUT->notification(get_string('testconnection_success', 'local_credentiumclaim'),
            \core\output\notification::NOTIFY_SUCCESS);
        echo html_writer::tag('p',
            get_string('testconnection_templatecount', 'local_credentiumclaim', count($templates)));
    } catch (moodle_exception $e) {
        echo $OUTPUT->notification(get_string('testconnection_fail', 'local_credentiumclaim'),
            \core\output\notification::NOTIFY_ERROR);
    }
}

echo html_writer::div(
    html_writer::tag('button', get_string('closewindow'), ['type' => 'button', 'class' => 'btn btn-secondary',
        'onclick' => 'window.close();']),
    'mt-3'
);

echo $OUTPUT->footer();
