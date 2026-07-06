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
