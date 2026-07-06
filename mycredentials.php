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
 * "My credentials" page: lists a learner's unclaimed Credentium credentials.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_credentiumclaim\local\claimable;

require_login();
$context = context_user::instance($USER->id);
require_capability('local/credentiumclaim:claim', $context);

$PAGE->set_url(new moodle_url('/local/credentiumclaim/mycredentials.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mycredentials', 'local_credentiumclaim'));
$PAGE->set_heading(get_string('mycredentials_heading', 'local_credentiumclaim'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mycredentials_heading', 'local_credentiumclaim'));

if (!local_credentiumclaim_is_enabled()) {
    echo $OUTPUT->notification(
        get_string('error:notconfigured', 'local_credentiumclaim'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    die();
}

$rows = claimable::list_for_user($USER->id);

if (empty($rows)) {
    echo $OUTPUT->notification(
        get_string('mycredentials_empty', 'local_credentiumclaim'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    echo html_writer::tag('p', get_string('mycredentials_intro', 'local_credentiumclaim'));

    $table = new html_table();
    $table->head = [
        get_string('col_course', 'local_credentiumclaim'),
        get_string('col_status', 'local_credentiumclaim'),
        get_string('col_action', 'local_credentiumclaim'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($rows as $row) {
        $coursename = '-';
        if (!empty($row->courseid)) {
            $fullname = $DB->get_field('course', 'fullname', ['id' => $row->courseid]);
            if ($fullname !== false) {
                $coursename = format_string($fullname);
            }
        }

        $statuslabel = get_string('status_' . $row->remotestatus, 'local_credentiumclaim');

        if ($row->remotestatus === claimable::STATUS_ISSUED) {
            $action = html_writer::start_tag('form', [
                'method' => 'post',
                'action' => (new moodle_url('/local/credentiumclaim/claim.php'))->out(false),
                'target' => '_blank',
                'class' => 'm-0',
            ]);
            $action .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $action .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $row->id]);
            $action .= html_writer::tag(
                'button',
                get_string('claim', 'local_credentiumclaim'),
                ['type' => 'submit', 'class' => 'btn btn-primary btn-sm']
            );
            $action .= html_writer::end_tag('form');
        } else {
            $action = html_writer::span($statuslabel, 'text-muted');
        }

        $table->data[] = [$coursename, $statuslabel, $action];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
