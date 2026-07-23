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
 * Runs the status-check task on demand, so admins do not have to wait for cron.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_credentiumclaim\task\sync_status;

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

$returnurl = new moodle_url('/local/credentiumclaim/index.php');

$task = new sync_status();

// Take the same lock cron uses for this task, under the same resource name, so a
// manual run cannot overlap a scheduled one. Without it both could discover the
// same credentials concurrently and race on the (userid, credentialkey) unique key.
$cronlockfactory = \core\lock\lock_config::get_lock_factory('cron');
$lock = $cronlockfactory->get_lock(ltrim(sync_status::class, '\\'), 5);
if (!$lock) {
    redirect(
        $returnurl,
        get_string('report_checknow_busy', 'local_credentiumclaim'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

// The task is bounded (1000 credentials per run) but still talks to a third party.
\core_php_time_limit::raise(300);

// Swallow the task's mtrace() output: the outcome is reported from the recorded run.
$failed = false;
ob_start();
try {
    $task->execute();
} catch (Throwable $e) {
    $failed = true;
    // The message below tells the admin to check the logs, so put something there.
    debugging('[CredentiumClaim] Manual status check failed: ' . $e->getMessage(), DEBUG_NORMAL);
}
ob_end_clean();

// Released explicitly rather than in a finally block: redirect() exits, and exit
// does not unwind finally.
$lock->release();

if ($failed) {
    redirect(
        $returnurl,
        get_string('report_checknow_failed', 'local_credentiumclaim'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$result = get_config('local_credentiumclaim', 'lastrunresult');
switch ($result) {
    case sync_status::RESULT_OK:
        $counters = (object) [
            'polled' => (int) get_config('local_credentiumclaim', 'lastrunpolled'),
            'updated' => (int) get_config('local_credentiumclaim', 'lastrunupdated'),
        ];
        $message = get_string('report_result_ok', 'local_credentiumclaim', $counters);
        $type = \core\output\notification::NOTIFY_SUCCESS;
        break;
    case sync_status::RESULT_DISABLED:
        $message = get_string('report_result_disabled', 'local_credentiumclaim');
        $type = \core\output\notification::NOTIFY_WARNING;
        break;
    case sync_status::RESULT_NOTCONFIGURED:
        $message = get_string('report_result_notconfigured', 'local_credentiumclaim');
        $type = \core\output\notification::NOTIFY_ERROR;
        break;
    default:
        $error = (string) get_config('local_credentiumclaim', 'lastrunerror');
        $message = get_string('report_result_error', 'local_credentiumclaim', s($error));
        $type = \core\output\notification::NOTIFY_ERROR;
}

redirect($returnurl, $message, null, $type);
