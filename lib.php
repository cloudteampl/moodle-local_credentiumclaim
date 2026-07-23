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
 * Library functions for the local_credentiumclaim plugin.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Log a diagnostic message when debug logging is enabled.
 *
 * Emits through Moodle's developer debugging channel. Callers must never pass
 * secrets (API keys, claim URLs) — sanitise first via the API client.
 *
 * @param string $message The message to log.
 * @param mixed $data Optional structured data (JSON-encoded).
 * @return void
 */
function local_credentiumclaim_log($message, $data = null) {
    if (!get_config('local_credentiumclaim', 'debuglog')) {
        return;
    }
    $logmessage = '[CredentiumClaim] ' . $message;
    if ($data !== null) {
        $logmessage .= ' | Data: ' . json_encode($data);
    }
    debugging($logmessage, DEBUG_DEVELOPER);
}

/**
 * Whether the plugin is enabled globally.
 *
 * @return bool
 */
function local_credentiumclaim_is_enabled() {
    return (bool) get_config('local_credentiumclaim', 'enabled');
}

/**
 * The scheduled task that polls Credentium for claim statuses.
 *
 * @return \core\task\scheduled_task|null Null if the task is not registered (mid-install).
 */
function local_credentiumclaim_get_sync_task() {
    $task = \core\task\manager::get_scheduled_task('local_credentiumclaim\task\sync_status');
    return $task ?: null;
}

/**
 * Selectable status-check intervals, in minutes, keyed by minutes.
 *
 * Every option divides evenly into an hour (or is a whole number of hours) so it maps
 * onto a valid cron expression without drift.
 *
 * @return array Map of minutes to human-readable label.
 */
function local_credentiumclaim_sync_interval_options() {
    $options = [];
    foreach ([5, 10, 15, 30, 60, 120, 240] as $minutes) {
        $options[$minutes] = format_time($minutes * MINSECS);
    }
    return $options;
}

/**
 * The interval the status-check task currently runs at.
 *
 * Read back from the task itself rather than from plugin config, so a schedule edited
 * directly under Server > Scheduled tasks is reported honestly.
 *
 * @return int|null Interval in minutes, or null when the schedule is a custom
 *                  expression that does not map onto a simple interval.
 */
function local_credentiumclaim_get_sync_interval() {
    $task = local_credentiumclaim_get_sync_task();
    if ($task === null) {
        return null;
    }
    $minute = trim($task->get_minute());
    $hour = trim($task->get_hour());

    if ($hour === '*' && preg_match('~^\*/(\d+)$~', $minute, $matches)) {
        return (int) $matches[1];
    }
    if ($minute === '0' && $hour === '*') {
        return (int) HOURMINS;
    }
    if ($minute === '0' && preg_match('~^\*/(\d+)$~', $hour, $matches)) {
        return ((int) $matches[1]) * HOURMINS;
    }
    return null;
}

/**
 * Rewrite the status-check task's schedule to run every $minutes.
 *
 * @param int $minutes Interval in minutes; must be one of the offered options.
 * @return bool True when the schedule was changed.
 */
function local_credentiumclaim_apply_sync_interval($minutes) {
    $minutes = (int) $minutes;
    if (!array_key_exists($minutes, local_credentiumclaim_sync_interval_options())) {
        return false;
    }
    $task = local_credentiumclaim_get_sync_task();
    if ($task === null) {
        return false;
    }

    if ($minutes >= HOURMINS) {
        $hours = intdiv($minutes, (int) HOURMINS);
        $task->set_minute('0');
        $task->set_hour($hours === 1 ? '*' : '*/' . $hours);
    } else {
        $task->set_minute('*/' . $minutes);
        $task->set_hour('*');
    }
    $task->set_day('*');
    $task->set_month('*');
    $task->set_day_of_week('*');
    // Mark as customised so a later plugin upgrade does not silently reset the choice.
    $task->set_customised(true);

    \core\task\manager::configure_scheduled_task($task);
    return true;
}

/**
 * Add a "My credentials" node to the user's own profile page.
 *
 * @param \core_user\output\myprofile\tree $tree The profile tree.
 * @param stdClass $user The user whose profile is shown.
 * @param bool $iscurrentuser Whether the profile belongs to the current user.
 * @param stdClass|null $course The current course (if any).
 * @return void
 */
function local_credentiumclaim_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    unset($course);
    if (!$iscurrentuser || !local_credentiumclaim_is_enabled()) {
        return;
    }
    if (!has_capability('local/credentiumclaim:claim', \context_user::instance($user->id))) {
        return;
    }

    $label = get_string('nav_mycredentials', 'local_credentiumclaim');
    $count = \local_credentiumclaim\local\claimable::count_for_user((int) $user->id);
    if ($count > 0) {
        $label .= ' (' . $count . ')';
    }

    $node = new \core_user\output\myprofile\node(
        'miscellaneous',
        'local_credentiumclaim',
        $label,
        null,
        new \moodle_url('/local/credentiumclaim/mycredentials.php')
    );
    $tree->add_node($node);
}
