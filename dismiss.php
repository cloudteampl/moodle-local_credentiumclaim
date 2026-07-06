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
 * Dismisses the reminder banner for the current user.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_credentiumclaim\local\claimable;

require_login();
require_sesskey();

$context = context_user::instance($USER->id);
require_capability('local/credentiumclaim:claim', $context);

$rowid = optional_param('id', 0, PARAM_INT);

if ($rowid > 0) {
    claimable::dismiss($USER->id, $rowid);
} else {
    claimable::dismiss_all($USER->id);
}

$returnurl = get_local_referer(false);
if (empty($returnurl)) {
    $returnurl = new moodle_url('/');
}
redirect($returnurl);
