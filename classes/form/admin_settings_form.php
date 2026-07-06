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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Admin settings form.
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

        // API URL.
        $mform->addElement('text', 'apiurl', get_string('apiurl', 'local_credentiumclaim'), ['size' => 60]);
        $mform->setType('apiurl', PARAM_URL);
        $mform->addHelpButton('apiurl', 'apiurl', 'local_credentiumclaim');
        $mform->hideIf('apiurl', 'enabled', 'notchecked');

        // API key.
        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', 'local_credentiumclaim'), ['size' => 60]);
        $mform->setType('apikey', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('apikey', 'apikey', 'local_credentiumclaim');
        $mform->hideIf('apikey', 'enabled', 'notchecked');

        // Test connection button (enabled only once both URL and key are saved).
        $savedurl = get_config('local_credentiumclaim', 'apiurl');
        $savedkey = get_config('local_credentiumclaim', 'apikey');
        if (!empty($savedurl) && !empty($savedkey)) {
            $testurl = new \moodle_url('/local/credentiumclaim/testconnection.php', ['sesskey' => sesskey()]);
            $onclick = "window.open('" . $testurl->out(false) . "', '_blank'); return false;";
        } else {
            $onclick = 'alert(' . json_encode(get_string('testconnection_disabled', 'local_credentiumclaim')) . '); return false;';
        }
        $mform->addElement(
            'button',
            'testconnection',
            get_string('testconnection', 'local_credentiumclaim'),
            ['onclick' => $onclick]
        );
        $mform->hideIf('testconnection', 'enabled', 'notchecked');

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
     * Server-side validation.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['enabled'])) {
            if (empty($data['apiurl'])) {
                $errors['apiurl'] = get_string('required');
            } else if (!filter_var($data['apiurl'], FILTER_VALIDATE_URL)) {
                $errors['apiurl'] = get_string('error:invalidapiurl', 'local_credentiumclaim');
            }
            if (empty($data['apikey'])) {
                $errors['apikey'] = get_string('required');
            }
        }

        return $errors;
    }
}
