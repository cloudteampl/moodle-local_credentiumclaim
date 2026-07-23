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
 * Resolves Credentium API credentials from the sibling local_credentium plugin.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\local;

/**
 * Single source of truth for the API endpoint and key used by this plugin.
 *
 * This plugin never stores its own credentials: it is a hard dependency of
 * local_credentium (the Credentium® Integration plugin), which already holds a
 * working endpoint and key. Duplicating them would mean two places to rotate a
 * secret and two ways to get it wrong.
 *
 * Two shapes of connector configuration are supported:
 *  - global credentials in the local_credentium plugin config;
 *  - per-category credentials (category mode), resolved from the course the
 *    credential was issued for and decrypted by the connector itself.
 */
class connector_config {
    /** @var string Component whose credentials are reused. */
    public const CONNECTOR = 'local_credentium';

    /** @var array Per-request cache of resolved credentials, keyed by course id. */
    protected static $coursecache = [];

    /**
     * Whether the connector plugin is present on disk.
     *
     * @return bool
     */
    public static function is_installed(): bool {
        global $CFG;
        return file_exists($CFG->dirroot . '/local/credentium/lib.php');
    }

    /**
     * Whether usable credentials (endpoint and key) can be resolved globally.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::global_credentials() !== null;
    }

    /**
     * Whether it is worth attempting API calls at all.
     *
     * True when global credentials exist, or when the connector runs in category mode
     * and may therefore hold per-category credentials we can only resolve per course.
     *
     * @return bool
     */
    public static function is_usable(): bool {
        if (self::is_configured()) {
            return true;
        }
        return self::is_installed() && !empty(get_config(self::CONNECTOR, 'categorymode'));
    }

    /**
     * Site-wide credentials taken from the connector's global settings.
     *
     * @return \stdClass|null {apiurl, apikey}, or null when either is missing.
     */
    public static function global_credentials(): ?\stdClass {
        return self::build(
            (string) get_config(self::CONNECTOR, 'apiurl'),
            (string) get_config(self::CONNECTOR, 'apikey')
        );
    }

    /**
     * Credentials that apply to credentials issued for a given course.
     *
     * Falls back to the global credentials whenever the course is unknown or the
     * connector cannot resolve a category-specific configuration.
     *
     * @param int|null $courseid Course the credential was issued for.
     * @return \stdClass|null {apiurl, apikey}, or null when nothing is configured.
     */
    public static function for_course(?int $courseid): ?\stdClass {
        if (empty($courseid)) {
            return self::global_credentials();
        }
        if (array_key_exists($courseid, self::$coursecache)) {
            return self::$coursecache[$courseid];
        }

        $resolved = null;
        if (self::load_connector() && function_exists('local_credentium_resolve_category_config')) {
            try {
                $config = local_credentium_resolve_category_config($courseid);
                if (is_object($config)) {
                    $resolved = self::build((string) ($config->apiurl ?? ''), (string) ($config->apikey ?? ''));
                }
            } catch (\Throwable $e) {
                // A deleted course (or any connector-side failure) must not stop the sync:
                // fall through to the global credentials below.
                $resolved = null;
            }
        }

        self::$coursecache[$courseid] = $resolved ?? self::global_credentials();
        return self::$coursecache[$courseid];
    }

    /**
     * Forget cached per-course resolutions (used by tests and long-running tasks).
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$coursecache = [];
    }

    /**
     * Admin page where the inherited credentials are actually edited.
     *
     * @return \moodle_url
     */
    public static function settings_url(): \moodle_url {
        return new \moodle_url('/local/credentium/admin_settings.php');
    }

    /**
     * Strip the connector's `/api` suffix so this plugin's `/api/...` paths resolve.
     *
     * The connector calls `{apiurl}/credential/issue`, so its configured URL ends in
     * `/api`. This plugin calls `{apiurl}/api/credential-issue-requests/...`. Without
     * this normalisation the shared URL would produce `/api/api/...` and every status
     * poll would 404 — silently, leaving credentials stuck in "processing".
     *
     * @param string $url Raw URL as configured in the connector.
     * @return string Base URL with no trailing slash and no trailing `/api` segment.
     */
    public static function normalize_base_url(string $url): string {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '';
        }
        if (preg_match('~^(.*?)/api$~i', $url, $matches)) {
            $stripped = rtrim($matches[1], '/');
            // Only accept the shorter URL if it is still a URL (guards against "https:/").
            if (filter_var($stripped, FILTER_VALIDATE_URL)) {
                return $stripped;
            }
        }
        return $url;
    }

    /**
     * Build a credentials object, or null when it would be unusable.
     *
     * @param string $apiurl Raw API URL.
     * @param string $apikey Raw API key.
     * @return \stdClass|null
     */
    protected static function build(string $apiurl, string $apikey): ?\stdClass {
        $apiurl = self::normalize_base_url($apiurl);
        $apikey = trim($apikey);
        if ($apiurl === '' || $apikey === '') {
            return null;
        }
        return (object) ['apiurl' => $apiurl, 'apikey' => $apikey];
    }

    /**
     * Include the connector's library so its resolver functions are callable.
     *
     * @return bool Whether the library is available.
     */
    protected static function load_connector(): bool {
        global $CFG;
        if (!self::is_installed()) {
            return false;
        }
        require_once($CFG->dirroot . '/local/credentium/lib.php');
        return true;
    }
}
