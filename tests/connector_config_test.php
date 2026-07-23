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
 * Unit tests for credentials inherited from the connector plugin.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

use local_credentiumclaim\local\connector_config;

/**
 * Tests for {@see \local_credentiumclaim\local\connector_config}.
 *
 * @covers \local_credentiumclaim\local\connector_config
 */
final class connector_config_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        connector_config::reset_cache();
    }

    /**
     * The connector's URL ends in /api; this plugin adds its own /api prefix.
     *
     * @param string $input Raw URL as stored by the connector.
     * @param string $expected Expected normalised base URL.
     * @dataProvider base_url_provider
     */
    public function test_normalize_base_url(string $input, string $expected): void {
        $this->assertSame($expected, connector_config::normalize_base_url($input));
    }

    /**
     * URLs and their expected normalised form.
     *
     * @return array[] Rows of [input, expected].
     */
    public static function base_url_provider(): array {
        return [
            'trailing /api is stripped' => ['https://issuer.example.com/api', 'https://issuer.example.com'],
            'trailing slash and /api' => ['https://issuer.example.com/api/', 'https://issuer.example.com'],
            'uppercase /API' => ['https://issuer.example.com/API', 'https://issuer.example.com'],
            'already normalised' => ['https://issuer.example.com', 'https://issuer.example.com'],
            'api in the host is kept' => ['https://api.example.com', 'https://api.example.com'],
            'deeper path keeps its prefix' => ['https://example.com/moodle/api', 'https://example.com/moodle'],
            'empty stays empty' => ['', ''],
        ];
    }

    public function test_global_credentials_are_inherited_and_normalised(): void {
        set_config('apiurl', 'https://issuer.example.com/api', 'local_credentium');
        set_config('apikey', ' pub.secret ', 'local_credentium');

        $credentials = connector_config::global_credentials();

        $this->assertNotNull($credentials);
        $this->assertSame('https://issuer.example.com', $credentials->apiurl);
        $this->assertSame('pub.secret', $credentials->apikey);
        $this->assertTrue(connector_config::is_configured());
    }

    public function test_incomplete_credentials_resolve_to_null(): void {
        set_config('apiurl', 'https://issuer.example.com/api', 'local_credentium');
        set_config('apikey', '', 'local_credentium');

        $this->assertNull(connector_config::global_credentials());
        $this->assertFalse(connector_config::is_configured());
    }

    public function test_course_without_category_config_falls_back_to_global(): void {
        set_config('apiurl', 'https://issuer.example.com/api', 'local_credentium');
        set_config('apikey', 'pub.secret', 'local_credentium');
        set_config('categorymode', 0, 'local_credentium');

        $course = $this->getDataGenerator()->create_course();

        $credentials = connector_config::for_course((int) $course->id);

        $this->assertNotNull($credentials);
        $this->assertSame('https://issuer.example.com', $credentials->apiurl);
    }

    public function test_unknown_course_does_not_break_resolution(): void {
        set_config('apiurl', 'https://issuer.example.com/api', 'local_credentium');
        set_config('apikey', 'pub.secret', 'local_credentium');

        // A deleted course must not stop the sync: fall back to the global credentials.
        $credentials = connector_config::for_course(-1);

        $this->assertNotNull($credentials);
        $this->assertSame('https://issuer.example.com', $credentials->apiurl);
    }
}
