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
 * Global admin settings page for Credentium Claim.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_credentiumclaim');

$PAGE->set_url(new moodle_url('/local/credentiumclaim/admin_settings.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'local_credentiumclaim'));
$PAGE->set_heading(get_string('globalsettings', 'local_credentiumclaim'));

$mform = new \local_credentiumclaim\form\admin_settings_form();

$config = new stdClass();
$config->enabled = get_config('local_credentiumclaim', 'enabled');
$config->apiurl = get_config('local_credentiumclaim', 'apiurl');
$config->apikey = get_config('local_credentiumclaim', 'apikey');
$showbanner = get_config('local_credentiumclaim', 'showbanner');
$config->showbanner = ($showbanner === false) ? 1 : $showbanner;
$config->debuglog = get_config('local_credentiumclaim', 'debuglog');
$mform->set_data($config);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/admin/plugins.php', ['subtype' => 'local']));
} else if ($data = $mform->get_data()) {
    set_config('enabled', !empty($data->enabled) ? 1 : 0, 'local_credentiumclaim');
    set_config('apiurl', !empty($data->apiurl) ? trim($data->apiurl) : '', 'local_credentiumclaim');
    set_config('apikey', !empty($data->apikey) ? $data->apikey : '', 'local_credentiumclaim');
    set_config('showbanner', !empty($data->showbanner) ? 1 : 0, 'local_credentiumclaim');
    set_config('debuglog', !empty($data->debuglog) ? 1 : 0, 'local_credentiumclaim');

    cache_helper::purge_by_definition('core', 'config');

    redirect(
        new moodle_url('/local/credentiumclaim/admin_settings.php'),
        get_string('changessaved'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $mform->render();
echo $OUTPUT->footer();
