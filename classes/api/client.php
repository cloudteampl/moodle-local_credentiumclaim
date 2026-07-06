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
 * Credentium v2 API client for status checks and claim links.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin HTTP client for the Credentium issuer API (v2 surface).
 *
 * All network access is funneled through {@see self::raw_request()}, which is the
 * single override point for unit tests. Secrets (API key, claim URLs) are never
 * written to logs — see {@see self::sanitize_for_log()}.
 */
class client {

    /** @var string Endpoint: batch status. */
    private const PATH_STATUS = '/api/credential-issue-requests/statuses';

    /** @var string Endpoint: credential templates (used only as an auth probe). */
    private const PATH_TEMPLATES = '/api/credential-template';

    /** @var int Maximum number of ids per batch status call. */
    private const BATCH_MAX = 500;

    /** @var int HTTP timeout in seconds. */
    private const TIMEOUT = 30;

    /** @var string[] Locales accepted by the claim-link endpoint. */
    private const ALLOWED_LOCALES = ['en', 'pl'];

    /** Claim action: recipient has no wallet yet; the URL creates it and claims. */
    public const ACTION_CREATE = 'create_wallet_and_claim';
    /** Claim action: recipient has a wallet; the URL logs them in and claims. */
    public const ACTION_LOGIN = 'login_and_claim';
    /** Claim action: already claimed; no URL. */
    public const ACTION_ALREADY = 'already_claimed';
    /** Claim action: still being issued; retry later. */
    public const ACTION_NOTREADY = 'not_ready';
    /** Claim action: unclassifiable. */
    public const ACTION_UNKNOWN = 'unknown';

    /** @var string|null Base API URL. */
    private $apiurl;

    /** @var string|null API key. */
    private $apikey;

    /**
     * Constructor.
     *
     * @param string|null $apiurl Base API URL (defaults to plugin config).
     * @param string|null $apikey API key (defaults to plugin config).
     */
    public function __construct(?string $apiurl = null, ?string $apikey = null) {
        $this->apiurl = ($apiurl !== null && $apiurl !== '') ? $apiurl : get_config('local_credentiumclaim', 'apiurl');
        $this->apikey = ($apikey !== null && $apikey !== '') ? $apikey : get_config('local_credentiumclaim', 'apikey');

        if (!empty($this->apiurl) && !filter_var($this->apiurl, FILTER_VALIDATE_URL)) {
            throw new \moodle_exception('error:invalidapiurl', 'local_credentiumclaim');
        }
        if (!empty($this->apikey)) {
            // Keys use the format public_id.secret — PARAM_RAW_TRIMMED preserves the dot.
            $this->apikey = clean_param($this->apikey, PARAM_RAW_TRIMMED);
        }
    }

    /**
     * Whether the client has enough configuration to make requests.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return !empty($this->apiurl) && !empty($this->apikey);
    }

    /**
     * Fetch claim/issue status for a set of issue-request ids, in batches of 500.
     *
     * @param string[] $issuerequestids Credentium issueRequestId values.
     * @return array<string,\stdClass> Map issueRequestId => {status, credentialid, issuedat, claimedat}.
     */
    public function get_status_batch(array $issuerequestids): array {
        $ids = array_values(array_unique(array_filter(array_map('strval', $issuerequestids), 'strlen')));
        $result = [];
        foreach (array_chunk($ids, self::BATCH_MAX) as $chunk) {
            $response = $this->request('POST', self::PATH_STATUS, [], ['issueRequestIds' => $chunk]);
            if (!is_object($response) || !isset($response->results) || !is_array($response->results)) {
                continue;
            }
            foreach ($response->results as $row) {
                if (!is_object($row) || !isset($row->issueRequestId)) {
                    continue;
                }
                $snapshot = new \stdClass();
                $snapshot->status = isset($row->status) ? (string)$row->status : self::ACTION_UNKNOWN;
                $snapshot->credentialid = isset($row->credentialId) ? (string)$row->credentialId : null;
                $snapshot->issuedat = isset($row->issuedAt) ? (string)$row->issuedAt : null;
                $snapshot->claimedat = isset($row->claimedAt) ? (string)$row->claimedAt : null;
                $result[(string)$row->issueRequestId] = $snapshot;
            }
        }
        return $result;
    }

    /**
     * Mint a single-use claim link for one credential.
     *
     * The returned claimUrl is a bearer-equivalent secret: never log or persist it.
     *
     * @param string $issuerequestid Credentium issueRequestId.
     * @param string $locale Preferred locale (allow-listed to en/pl, fallback en).
     * @return \stdClass {actiontype, claimurl, expiresat}
     */
    public function get_claim_link(string $issuerequestid, string $locale = 'en'): \stdClass {
        $locale = in_array($locale, self::ALLOWED_LOCALES, true) ? $locale : 'en';
        $endpoint = '/api/credential-issue-requests/' . rawurlencode($issuerequestid) . '/claim-link';
        $response = $this->request('POST', $endpoint, [], ['locale' => $locale]);

        $out = new \stdClass();
        $raw = (is_object($response) && isset($response->actionType)) ? (string)$response->actionType : self::ACTION_UNKNOWN;
        $out->actiontype = $this->normalize_actiontype($raw);
        $out->claimurl = (is_object($response) && isset($response->claimUrl) && $response->claimUrl !== '')
            ? (string)$response->claimUrl : null;
        $out->expiresat = (is_object($response) && isset($response->expiresAt)) ? (string)$response->expiresAt : null;
        return $out;
    }

    /**
     * Fetch the credential template catalogue (lightweight auth probe for Test Connection).
     *
     * @return array List of template objects (may be empty).
     */
    public function get_templates(): array {
        $response = $this->request('GET', self::PATH_TEMPLATES, []);
        return is_array($response) ? $response : [];
    }

    /**
     * Redact secrets (claim URLs, API key) from a string before it is logged.
     *
     * @param mixed $body Response body or arbitrary payload.
     * @return string Sanitised text safe for logs.
     */
    public function sanitize_for_log($body): string {
        $text = is_string($body) ? $body : json_encode($body);
        if (!is_string($text)) {
            return '';
        }
        // Redact any claimUrl / claim_url JSON value.
        $text = preg_replace('/("claim_?url"\s*:\s*)"[^"]*"/i', '$1"[REDACTED]"', $text);
        // Redact the API key should it ever appear in a response echo.
        if (!empty($this->apikey)) {
            $text = str_replace($this->apikey, '[REDACTED]', $text);
        }
        return $text;
    }

    /**
     * Map a raw Credentium actionType onto a known constant.
     *
     * @param string $raw Raw action type.
     * @return string One of the ACTION_* constants.
     */
    private function normalize_actiontype(string $raw): string {
        $known = [self::ACTION_CREATE, self::ACTION_LOGIN, self::ACTION_ALREADY, self::ACTION_NOTREADY];
        return in_array($raw, $known, true) ? $raw : self::ACTION_UNKNOWN;
    }

    /**
     * Build and execute an API request, decode JSON, and map errors.
     *
     * @param string $method HTTP method.
     * @param string $endpoint Path beginning with '/'.
     * @param array $params Query parameters.
     * @param array|null $data Request body (JSON-encoded when present).
     * @return mixed Decoded response, or null for an empty 2xx body.
     */
    private function request(string $method, string $endpoint, array $params = [], ?array $data = null) {
        if (!$this->is_configured()) {
            throw new \moodle_exception('error:apinotconfigured', 'local_credentiumclaim');
        }

        $url = rtrim($this->apiurl, '/') . '/' . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = [
            'API-KEY: ' . $this->apikey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        require_once(__DIR__ . '/../../lib.php');
        local_credentiumclaim_log('API request: ' . $method . ' ' . parse_url($url, PHP_URL_PATH));

        $bodyjson = ($data !== null) ? json_encode($data) : null;
        [$httpcode, $responsebody, $info] = $this->raw_request($method, $url, $headers, $bodyjson);
        unset($info);

        if ($httpcode >= 200 && $httpcode < 300) {
            if ($responsebody === null || $responsebody === false || $responsebody === '') {
                return null;
            }
            $decoded = json_decode($responsebody);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \moodle_exception('error:invalidjsonresponse', 'local_credentiumclaim');
            }
            return $decoded;
        }

        // Non-2xx: log a sanitised summary and throw without leaking secrets.
        local_credentiumclaim_log('API request failed', [
            'path' => parse_url($url, PHP_URL_PATH),
            'method' => $method,
            'http_code' => $httpcode,
            'body' => $this->sanitize_for_log((string)$responsebody),
        ]);
        $debuginfo = 'HTTP ' . $httpcode . ' for ' . parse_url($url, PHP_URL_PATH);
        throw new \moodle_exception('apierror', 'local_credentiumclaim', '', null, $debuginfo);
    }

    /**
     * Perform the raw HTTP call. Overridden in unit tests to avoid the network.
     *
     * @param string $method HTTP method.
     * @param string $url Fully-qualified URL.
     * @param string[] $headers Request headers.
     * @param string|null $body Raw request body.
     * @return array Numeric array: [int http_code, string|false response_body, array curl_info].
     */
    protected function raw_request(string $method, string $url, array $headers, ?string $body): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl(['debug' => false]);
        $curl->setopt([
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_TIMEOUT' => self::TIMEOUT,
        ]);

        if ($method === 'POST') {
            $responsebody = $curl->post($url, $body ?? '');
        } else {
            $responsebody = $curl->get($url);
        }

        $info = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);
        return [$httpcode, $responsebody, $info];
    }
}
