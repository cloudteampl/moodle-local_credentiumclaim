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
 * Hook callbacks for local_credentiumclaim.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Output hook callbacks.
 */
class hook_callbacks {

    /**
     * Inject the "unclaimed credential" reminder banner at the top of the page body.
     *
     * Runs on every page, so it must stay cheap: a single cached, indexed lookup and
     * no network access. The scheduled task and user actions do the heavy lifting.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook The output hook.
     * @return void
     */
    public static function before_standard_top_of_body_html(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $USER, $OUTPUT, $CFG;

        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }

        require_once($CFG->dirroot . '/local/credentiumclaim/lib.php');
        if (!local_credentiumclaim_is_enabled() || !get_config('local_credentiumclaim', 'showbanner')) {
            return;
        }

        if (!has_capability('local/credentiumclaim:claim', \context_user::instance($USER->id))) {
            return;
        }

        $count = claimable::count_for_user((int) $USER->id);
        if ($count < 1) {
            return;
        }

        $banner = new \local_credentiumclaim\output\banner($count);
        $hook->add_html($OUTPUT->render_from_template('local_credentiumclaim/banner', $banner->export_for_template($OUTPUT)));
    }
}
