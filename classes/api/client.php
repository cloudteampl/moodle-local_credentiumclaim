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

use local_credentiumclaim\local\connector_config;

/**
 * Thin HTTP client for the Credentium issuer API (v2 surface).
 *
 * All network access is funneled through {@see self::raw_request()}, which is the
 * single override point for unit tests. Secrets (API key, claim URLs) are never
 * written to logs — see {@see self::sanitize_for_log()}.
 *
 * Credentials are inherited from the local_credentium connector plugin unless the
 * caller passes them explicitly.
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

    /** @var string|null Technical description of the most recent failure. */
    private $lasterror = null;

    /** @var int Identifiers submitted to the batch status endpoint in the last call. */
    private $lastrequested = 0;

    /** @var int Identifiers Credentium recognised in the last batch status call. */
    private $lastreturned = 0;

    /**
     * Constructor.
     *
     * @param string|null $apiurl Base API URL (defaults to the inherited connector URL).
     * @param string|null $apikey API key (defaults to the inherited connector key).
     */
    public function __construct(?string $apiurl = null, ?string $apikey = null) {
        if ($apiurl === null || $apiurl === '' || $apikey === null || $apikey === '') {
            $inherited = connector_config::global_credentials();
            $apiurl = ($apiurl !== null && $apiurl !== '') ? $apiurl : ($inherited->apiurl ?? null);
            $apikey = ($apikey !== null && $apikey !== '') ? $apikey : ($inherited->apikey ?? null);
        }
        $this->apiurl = $apiurl;
        $this->apikey = $apikey;

        if (!empty($this->apiurl) && !filter_var($this->apiurl, FILTER_VALIDATE_URL)) {
            throw new \moodle_exception('error:invalidapiurl', 'local_credentiumclaim');
        }
        if (!empty($this->apikey)) {
            // Keys use the format public_id.secret — PARAM_RAW_TRIMMED preserves the dot.
            $this->apikey = clean_param($this->apikey, PARAM_RAW_TRIMMED);
        }
    }

    /**
     * Build a client for credentials issued in a given course (honours category mode).
     *
     * @param int|null $courseid Course id, or null for the site-wide credentials.
     * @return self
     */
    public static function for_course(?int $courseid = null): self {
        $config = connector_config::for_course($courseid);
        return new self($config->apiurl ?? null, $config->apikey ?? null);
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
     * Technical description of the most recent failure, for admin diagnostics.
     *
     * Never contains secrets: the message is built from the HTTP status and path only.
     *
     * @return string|null Null when the last operation succeeded.
     */
    public function get_last_error(): ?string {
        return $this->lasterror;
    }

    /**
     * Identifier counts from the most recent {@see self::get_status_batch()} call.
     *
     * A `returned` lower than `requested` means Credentium did not recognise some
     * identifiers — typically an API key belonging to a different organisation.
     *
     * @return array Counts keyed by 'requested' and 'returned'.
     */
    public function get_last_batch_stats(): array {
        return ['requested' => $this->lastrequested, 'returned' => $this->lastreturned];
    }

    /**
     * Fetch claim/issue status for a set of issue-request ids, in batches of 500.
     *
     * @param string[] $issuerequestids Credentium issueRequestId values.
     * @return array Keyed by issueRequestId; each value is an object {status, credentialid, issuedat, claimedat}.
     */
    public function get_status_batch(array $issuerequestids): array {
        $ids = array_values(array_unique(array_filter(array_map('strval', $issuerequestids), 'strlen')));
        $result = [];
        $this->lasterror = null;
        $this->lastrequested = count($ids);
        $this->lastreturned = 0;
        foreach (array_chunk($ids, self::BATCH_MAX) as $chunk) {
            try {
                $response = $this->request('POST', self::PATH_STATUS, [], ['issueRequestIds' => $chunk]);
            } catch (\moodle_exception $e) {
                // Isolate the failure: keep results already gathered and poll the remaining chunks.
                // The reason is retained so the admin report can explain a sync that did nothing.
                require_once(__DIR__ . '/../../lib.php');
                local_credentiumclaim_log('Batch status chunk failed', ['size' => count($chunk)]);
                continue;
            }
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
        $this->lastreturned = count($result);
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
            $this->lasterror = 'API credentials are not available.';
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

        $path = parse_url($url, PHP_URL_PATH);

        if ($httpcode >= 200 && $httpcode < 300) {
            if ($responsebody === null || $responsebody === false || $responsebody === '') {
                return null;
            }
            $decoded = json_decode($responsebody);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->lasterror = 'Invalid JSON response from ' . $path;
                throw new \moodle_exception('error:invalidjsonresponse', 'local_credentiumclaim');
            }
            return $decoded;
        }

        $this->lasterror = 'HTTP ' . $httpcode . ' from ' . $method . ' ' . $path;
        $reason = $this->extract_error_reason($responsebody);
        if ($reason !== null) {
            // Every documented error shape has a plain-text reason, and a 401 caused by
            // scope may name the missing scope (e.g. "required_scope": "credentials:read").
            // Neither field is documented as ever carrying a secret, but it is still run
            // through the same redaction as everything else logged or shown to admins.
            $this->lasterror .= ': ' . $this->sanitize_for_log($reason);
        }

        // Non-2xx: log a sanitised summary and throw without leaking secrets.
        local_credentiumclaim_log('API request failed', [
            'path' => $path,
            'method' => $method,
            'http_code' => $httpcode,
            'body' => $this->sanitize_for_log((string)$responsebody),
        ]);
        $debuginfo = 'HTTP ' . $httpcode . ' for ' . $path;
        throw new \moodle_exception('apierror', 'local_credentiumclaim', '', null, $debuginfo);
    }

    /**
     * Pull a human-readable reason out of a Credentium error envelope, when present.
     *
     * Every documented error shape (validation, not-found, and the legacy auth shapes)
     * carries a plain-text `error` field, and an insufficient-scope 401 adds
     * `required_scope` naming the missing permission — turning "HTTP 401" into an
     * actionable diagnostic (e.g. an inherited key that can issue but not read).
     *
     * @param mixed $responsebody Raw response body.
     * @return string|null Null when the body has no recognisable reason.
     */
    private function extract_error_reason($responsebody): ?string {
        if (!is_string($responsebody) || $responsebody === '') {
            return null;
        }
        $decoded = json_decode($responsebody);
        if (!is_object($decoded) || !isset($decoded->error) || !is_string($decoded->error)) {
            return null;
        }
        $reason = $decoded->error;
        if (!empty($decoded->required_scope) && is_string($decoded->required_scope)) {
            $reason .= ' (required scope: ' . $decoded->required_scope . ')';
        }
        return $reason;
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
