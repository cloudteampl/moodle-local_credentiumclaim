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
    if ($oldversion < 2026072300) {
        // The plugin no longer keeps its own copy of the Credentium API credentials:
        // they are inherited from local_credentium. Drop the duplicates so a rotated
        // key cannot linger here, and so nobody edits a value that is no longer read.
        unset_config('apiurl', 'local_credentiumclaim');
        unset_config('apikey', 'local_credentiumclaim');

        upgrade_plugin_savepoint(true, 2026072300, 'local', 'credentiumclaim');
    }

    return true;
}
