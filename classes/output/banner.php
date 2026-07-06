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
 * Renderable for the unclaimed-credential reminder banner.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\output;

/**
 * The reminder banner shown to learners with unclaimed credentials.
 */
class banner implements \renderable, \templatable {
    /** @var int Number of unclaimed credentials. */
    protected $count;

    /**
     * Constructor.
     *
     * @param int $count Number of unclaimed credentials.
     */
    public function __construct(int $count) {
        $this->count = $count;
    }

    /**
     * Export data for the banner template.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        return [
            'title' => get_string('banner_title', 'local_credentiumclaim'),
            'message' => get_string('banner_message', 'local_credentiumclaim', $this->count),
            'cta' => get_string('banner_cta', 'local_credentiumclaim'),
            'ctaurl' => (new \moodle_url('/local/credentiumclaim/mycredentials.php'))->out(false),
            'dismisslabel' => get_string('banner_dismiss', 'local_credentiumclaim'),
            'dismissurl' => (new \moodle_url('/local/credentiumclaim/dismiss.php'))->out(false),
            'sesskey' => sesskey(),
        ];
    }
}
