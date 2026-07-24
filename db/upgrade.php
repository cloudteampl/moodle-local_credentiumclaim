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
 * Upgrade steps for the local_credentiumclaim plugin.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the plugin upgrade from the given old version.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_credentiumclaim_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026072300) {
        // The plugin no longer keeps its own copy of the Credentium API credentials:
        // they are inherited from local_credentium. Drop the duplicates so a rotated
        // key cannot linger here, and so nobody edits a value that is no longer read.
        unset_config('apiurl', 'local_credentiumclaim');
        unset_config('apikey', 'local_credentiumclaim');

        upgrade_plugin_savepoint(true, 2026072300, 'local', 'credentiumclaim');
    }

    if ($oldversion < 2026072400) {
        // The banner toggle lives on a custom moodleform, so its default was never
        // persisted: sites that enabled the plugin without re-saving that form had
        // showbanner unset, and the banner hook read that as "off". Persist the
        // documented default (on) without touching an explicit admin choice.
        if (get_config('local_credentiumclaim', 'showbanner') === false) {
            set_config('showbanner', 1, 'local_credentiumclaim');
        }

        upgrade_plugin_savepoint(true, 2026072400, 'local', 'credentiumclaim');
    }

    if ($oldversion < 2026072403) {
        // Store the Credentium credentialId the status endpoint has always returned
        // and the plugin has always thrown away. It identifies the credential itself
        // rather than the request that produced it, so a support query can be tied to
        // Credentium's own records. Existing rows fill theirs in on their next poll.
        $table = new xmldb_table('local_credentiumclaim_status');
        $field = new xmldb_field('credentialid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072403, 'local', 'credentiumclaim');
    }

    return true;
}
