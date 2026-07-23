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
 * Global settings form for local_credentiumclaim.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\form;

use local_credentiumclaim\local\connector_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/local/credentiumclaim/lib.php');

/**
 * Admin settings form.
 *
 * Deliberately has no API URL or API key fields: both are inherited from the
 * local_credentium connector plugin, which this plugin already requires.
 */
class admin_settings_form extends \moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'static',
            'intro',
            '',
            '<div class="alert alert-info">' . get_string('settings_desc', 'local_credentiumclaim') . '</div>'
        );

        // Master switch.
        $mform->addElement('checkbox', 'enabled', get_string('enabled', 'local_credentiumclaim'));
        $mform->addHelpButton('enabled', 'enabled', 'local_credentiumclaim');

        // Inherited API connection (read-only): the connector plugin owns these values.
        $mform->addElement(
            'static',
            'inheritedconnection',
            get_string('connection', 'local_credentiumclaim'),
            $this->render_inherited_connection()
        );
        $mform->addHelpButton('inheritedconnection', 'connection', 'local_credentiumclaim');

        // Test connection button (only useful once credentials can be inherited).
        if (connector_config::is_configured()) {
            $testurl = new \moodle_url('/local/credentiumclaim/testconnection.php', ['sesskey' => sesskey()]);
            $onclick = "window.open('" . $testurl->out(false) . "', '_blank'); return false;";
        } else {
            $warning = get_string('testconnection_disabled', 'local_credentiumclaim');
            $onclick = 'alert(' . json_encode($warning) . '); return false;';
        }
        $mform->addElement(
            'button',
            'testconnection',
            get_string('testconnection', 'local_credentiumclaim'),
            ['onclick' => $onclick]
        );
        $mform->hideIf('testconnection', 'enabled', 'notchecked');

        // How often the status-check task runs.
        $intervals = local_credentiumclaim_sync_interval_options();
        if (local_credentiumclaim_get_sync_interval() === null) {
            // The schedule was hand-edited under Server > Scheduled tasks; say so
            // rather than silently overwriting it with the nearest offered value.
            $intervals = ['' => get_string('syncinterval_custom', 'local_credentiumclaim')] + $intervals;
        }
        $mform->addElement('select', 'syncinterval', get_string('syncinterval', 'local_credentiumclaim'), $intervals);
        $mform->addHelpButton('syncinterval', 'syncinterval', 'local_credentiumclaim');
        $mform->hideIf('syncinterval', 'enabled', 'notchecked');

        // Show banner.
        $mform->addElement('advcheckbox', 'showbanner', get_string('showbanner', 'local_credentiumclaim'));
        $mform->addHelpButton('showbanner', 'showbanner', 'local_credentiumclaim');
        $mform->setDefault('showbanner', 1);
        $mform->hideIf('showbanner', 'enabled', 'notchecked');

        // Debug logging.
        $mform->addElement('checkbox', 'debuglog', get_string('debuglog', 'local_credentiumclaim'));
        $mform->addHelpButton('debuglog', 'debuglog', 'local_credentiumclaim');
        $mform->hideIf('debuglog', 'enabled', 'notchecked');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Read-only summary of the credentials inherited from the connector plugin.
     *
     * @return string HTML.
     */
    protected function render_inherited_connection(): string {
        $manage = \html_writer::link(
            connector_config::settings_url(),
            get_string('connection_manage', 'local_credentiumclaim')
        );

        if (!connector_config::is_installed()) {
            return \html_writer::div(
                get_string('connection_missingplugin', 'local_credentiumclaim'),
                'alert alert-danger mb-0'
            );
        }

        $credentials = connector_config::global_credentials();
        if ($credentials === null) {
            $body = \html_writer::tag('p', get_string('connection_notconfigured', 'local_credentiumclaim'));
            $body .= \html_writer::tag('p', $manage, ['class' => 'mb-0']);
            return \html_writer::div($body, 'alert alert-warning mb-0');
        }

        $rows = \html_writer::tag(
            'div',
            \html_writer::tag('strong', get_string('connection_apiurl', 'local_credentiumclaim') . ': ')
                . \html_writer::tag('code', s($credentials->apiurl))
        );
        $rows .= \html_writer::tag(
            'div',
            \html_writer::tag('strong', get_string('connection_apikey', 'local_credentiumclaim') . ': ')
                . get_string('connection_apikey_set', 'local_credentiumclaim')
        );
        $rows .= \html_writer::tag('div', $manage, ['class' => 'mt-2']);

        return \html_writer::div($rows, 'alert alert-light border mb-0');
    }
}
