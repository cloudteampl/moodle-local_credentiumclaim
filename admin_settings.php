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
require_once(__DIR__ . '/lib.php');

// Login, capability, URL, title and heading are all handled by the call below.
admin_externalpage_setup('local_credentiumclaim');

$mform = new \local_credentiumclaim\form\admin_settings_form();

$config = new stdClass();
$config->enabled = get_config('local_credentiumclaim', 'enabled');
// Same unset-means-on default the banner hook applies at render time.
$config->showbanner = local_credentiumclaim_show_banner() ? 1 : 0;
$config->debuglog = get_config('local_credentiumclaim', 'debuglog');
// Only the manual override is editable; the learned value is shown beside it as a hint.
$config->walleturl = (string) get_config('local_credentiumclaim', 'walleturl');
// Null means the cron schedule was hand-edited; '' selects the "custom" option.
$config->syncinterval = local_credentiumclaim_get_sync_interval() ?? '';
$mform->set_data($config);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/admin/plugins.php', ['subtype' => 'local']));
} else if ($data = $mform->get_data()) {
    set_config('enabled', !empty($data->enabled) ? 1 : 0, 'local_credentiumclaim');
    set_config('showbanner', !empty($data->showbanner) ? 1 : 0, 'local_credentiumclaim');
    set_config('debuglog', !empty($data->debuglog) ? 1 : 0, 'local_credentiumclaim');
    // Stored without a trailing slash so it composes the same way as a learned value.
    set_config('walleturl', rtrim(trim((string) ($data->walleturl ?? '')), '/'), 'local_credentiumclaim');

    // An empty interval means "leave the hand-edited cron schedule alone".
    if (!empty($data->syncinterval)) {
        local_credentiumclaim_apply_sync_interval((int) $data->syncinterval);
    }

    cache_helper::purge_by_definition('core', 'config');

    redirect(
        new moodle_url('/local/credentiumclaim/admin_settings.php'),
        get_string('changessaved'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('globalsettings', 'local_credentiumclaim'));
echo $mform->render();
echo $OUTPUT->footer();
