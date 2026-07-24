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
 * Post-install steps for the local_credentiumclaim plugin.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Persist setting defaults at install time.
 *
 * The settings live on a custom moodleform, so unlike admin_settings their
 * defaults are never written automatically. Runtime code treats an unset
 * `showbanner` as enabled anyway, but persisting the default keeps what the
 * admin UI shows and what the site does trivially in sync.
 *
 * @return bool
 */
function xmldb_local_credentiumclaim_install() {
    set_config('showbanner', 1, 'local_credentiumclaim');
    return true;
}
