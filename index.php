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
require_once(__DIR__ . '/lib.php');

use local_credentiumclaim\local\claimable;
use local_credentiumclaim\local\connector_config;
use local_credentiumclaim\task\sync_status;

// Login, capability, URL, title and heading are all handled by the call below.
// Overriding the page heading here would render the report title twice.
admin_externalpage_setup('local_credentiumclaim_report');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_heading', 'local_credentiumclaim'));
echo html_writer::tag('p', get_string('report_intro', 'local_credentiumclaim'));

// Blocking conditions first: they explain an otherwise mysteriously idle report.
if (!local_credentiumclaim_is_enabled()) {
    echo $OUTPUT->notification(
        get_string('report_plugindisabled', 'local_credentiumclaim'),
        \core\output\notification::NOTIFY_WARNING
    );
} else if (!connector_config::is_usable()) {
    echo $OUTPUT->notification(
        get_string('report_connection_missing', 'local_credentiumclaim'),
        \core\output\notification::NOTIFY_ERROR
    );
}

// Status breakdown.
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

// Diagnostics: what the sync task last did, and when it will run again.
echo $OUTPUT->heading(get_string('report_diagnostics', 'local_credentiumclaim'), 3);

$diagnostics = new html_table();
$diagnostics->attributes['class'] = 'generaltable';
$diagnostics->head = [
    get_string('report_property', 'local_credentiumclaim'),
    get_string('report_value', 'local_credentiumclaim'),
];

$never = get_string('report_never', 'local_credentiumclaim');

// Inherited API connection.
$credentials = connector_config::global_credentials();
$connectionvalue = ($credentials === null)
    ? get_string('report_connection_missing', 'local_credentiumclaim')
    : get_string('report_connection_ok', 'local_credentiumclaim', s($credentials->apiurl));
$diagnostics->data[] = [get_string('connection', 'local_credentiumclaim'), $connectionvalue];

// Schedule, plus the task's own last/next run times.
$interval = local_credentiumclaim_get_sync_interval();
$schedule = ($interval === null)
    ? get_string('report_schedule_custom', 'local_credentiumclaim')
    : get_string('report_schedule_every', 'local_credentiumclaim', format_time($interval * MINSECS));
$diagnostics->data[] = [get_string('syncinterval', 'local_credentiumclaim'), $schedule];

$task = local_credentiumclaim_get_sync_task();
if ($task !== null) {
    $lastrun = $task->get_last_run_time();
    $diagnostics->data[] = [
        get_string('report_tasklastrun', 'local_credentiumclaim'),
        empty($lastrun) ? $never : userdate($lastrun),
    ];
    $diagnostics->data[] = [
        get_string('report_tasknextrun', 'local_credentiumclaim'),
        userdate($task->get_next_scheduled_time()),
    ];
}

// Outcome of the most recent run, recorded by the task itself.
$lastrunresult = get_config('local_credentiumclaim', 'lastrunresult');
$lastrunerror = (string) get_config('local_credentiumclaim', 'lastrunerror');
switch ($lastrunresult) {
    case sync_status::RESULT_OK:
        $counters = (object) [
            'polled' => (int) get_config('local_credentiumclaim', 'lastrunpolled'),
            'updated' => (int) get_config('local_credentiumclaim', 'lastrunupdated'),
        ];
        $outcome = get_string('report_result_ok', 'local_credentiumclaim', $counters);
        break;
    case sync_status::RESULT_DISABLED:
        $outcome = get_string('report_result_disabled', 'local_credentiumclaim');
        break;
    case sync_status::RESULT_NOTCONFIGURED:
        $outcome = get_string('report_result_notconfigured', 'local_credentiumclaim');
        break;
    case sync_status::RESULT_ERROR:
        $outcome = get_string('report_result_error', 'local_credentiumclaim', s($lastrunerror));
        break;
    default:
        $outcome = $never;
}
$lastrun = (int) get_config('local_credentiumclaim', 'lastrun');
$diagnostics->data[] = [
    get_string('report_lastrun', 'local_credentiumclaim'),
    empty($lastrun) ? $never : userdate($lastrun),
];
$diagnostics->data[] = [get_string('report_lastresult', 'local_credentiumclaim'), $outcome];

// Bell notifications sent on the last run (confirms the "ready to claim" nudge fired).
$diagnostics->data[] = [
    get_string('report_notified', 'local_credentiumclaim'),
    (int) get_config('local_credentiumclaim', 'lastrunnotified'),
];

// Per-credential freshness.
$lastcheck = $DB->get_field_sql('SELECT MAX(timechecked) FROM {local_credentiumclaim_status}');
$diagnostics->data[] = [
    get_string('report_lastsync', 'local_credentiumclaim'),
    empty($lastcheck) ? $never : userdate($lastcheck),
];

echo html_writer::table($diagnostics);

// Actionable warnings about the last run.
$unmatched = (int) get_config('local_credentiumclaim', 'lastrununmatched');
if ($lastrunresult === sync_status::RESULT_ERROR) {
    // An API failure explains the missing statuses on its own; blaming the
    // identifiers here would send the admin looking in the wrong place.
    // The table above already carries the raw failure, so this box carries the part
    // an admin cannot derive from it: which of the five causes it was, and what to do.
    echo $OUTPUT->notification(
        local_credentiumclaim_run_error_message(),
        \core\output\notification::NOTIFY_ERROR
    );
} else if ($unmatched > 0) {
    echo $OUTPUT->notification(
        get_string('report_unmatched', 'local_credentiumclaim', $unmatched),
        \core\output\notification::NOTIFY_WARNING
    );
}
$pending = (int) get_config('local_credentiumclaim', 'lastrununanswered');
if ($lastrunresult !== sync_status::RESULT_ERROR && $pending > 0) {
    // A run can leave credentials unchecked without failing — it can simply run out
    // of its time budget. Saying so beats a clean "Completed" that quietly did less.
    echo $OUTPUT->notification(
        get_string('report_error_pending', 'local_credentiumclaim', $pending),
        \core\output\notification::NOTIFY_WARNING
    );
}
$unresolved = (int) get_config('local_credentiumclaim', 'lastrununresolved');
if ($unresolved > 0) {
    echo $OUTPUT->notification(
        get_string('report_unresolved', 'local_credentiumclaim', $unresolved),
        \core\output\notification::NOTIFY_WARNING
    );
}
$notifyfailed = (int) get_config('local_credentiumclaim', 'lastrunnotifyfailed');
if ($notifyfailed > 0) {
    echo $OUTPUT->notification(
        get_string('report_notifyfailed', 'local_credentiumclaim', $notifyfailed),
        \core\output\notification::NOTIFY_WARNING
    );
}

// Run the check on demand (site administrators only).
if (has_capability('moodle/site:config', context_system::instance())) {
    $form = html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/credentiumclaim/syncnow.php'))->out(false),
        'class' => 'mt-3',
    ]);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $form .= html_writer::tag(
        'button',
        get_string('report_checknow', 'local_credentiumclaim'),
        ['type' => 'submit', 'class' => 'btn btn-secondary']
    );
    $form .= html_writer::end_tag('form');
    echo $form;
}

echo $OUTPUT->footer();
