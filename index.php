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
 * Status report for Credentium Claim (admin).
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_credentiumclaim\local\claimable;

admin_externalpage_setup('local_credentiumclaim_report');

$PAGE->set_url(new moodle_url('/local/credentiumclaim/index.php'));
$PAGE->set_title(get_string('report', 'local_credentiumclaim'));
$PAGE->set_heading(get_string('report_heading', 'local_credentiumclaim'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_heading', 'local_credentiumclaim'));
echo html_writer::tag('p', get_string('report_intro', 'local_credentiumclaim'));

$counts = $DB->get_records_sql(
    'SELECT remotestatus, COUNT(*) AS cnt FROM {local_credentiumclaim_status} GROUP BY remotestatus'
);

$total = 0;
$table = new html_table();
$table->head = [
    get_string('report_status', 'local_credentiumclaim'),
    get_string('report_count', 'local_credentiumclaim'),
];
$table->attributes['class'] = 'generaltable';

$statuses = [
    claimable::STATUS_PROCESSING,
    claimable::STATUS_ISSUED,
    claimable::STATUS_CLAIMED,
    claimable::STATUS_FAILED,
    claimable::STATUS_UNKNOWN,
];
foreach ($statuses as $status) {
    $cnt = isset($counts[$status]) ? (int) $counts[$status]->cnt : 0;
    $total += $cnt;
    $table->data[] = [get_string('status_' . $status, 'local_credentiumclaim'), $cnt];
}

echo html_writer::table($table);
echo html_writer::tag('p', get_string('report_total', 'local_credentiumclaim') . ': ' . $total);

$lastcheck = $DB->get_field_sql('SELECT MAX(timechecked) FROM {local_credentiumclaim_status}');
if (!empty($lastcheck)) {
    echo html_writer::tag('p', get_string('report_lastsync', 'local_credentiumclaim', userdate($lastcheck)));
} else {
    echo html_writer::tag('p', get_string('report_neversynced', 'local_credentiumclaim'));
}

echo $OUTPUT->footer();
