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

    /** @var int Default HTTP timeout in seconds. */
    private const TIMEOUT = 30;

    /** @var int Default attempts per request, including the first (1 disables retrying). */
    private const ATTEMPTS = 3;

    /** @var int First backoff in seconds; doubled on every further attempt. */
    private const BACKOFF_BASE_SECONDS = 1;

    /** @var int Upper bound on any single backoff wait, in seconds. */
    private const BACKOFF_MAX_SECONDS = 8;

    /** Failure kind: the key was refused, or lacks the scope the endpoint requires. */
    public const FAIL_AUTH = 'auth';
    /** Failure kind: Credentium understood the request and rejected it (4xx). */
    public const FAIL_CLIENT = 'client';
    /** Failure kind: Credentium is rate-limiting the caller, or timed out answering (408/429). */
    public const FAIL_BUSY = 'busy';
    /** Failure kind: Credentium accepted the request and failed to answer it (5xx). */
    public const FAIL_SERVER = 'server';
    /** Failure kind: the request never reached Credentium (DNS, TLS, connect/read timeout). */
    public const FAIL_NETWORK = 'network';

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

    /** @var int HTTP timeout in seconds for this instance. */
    private $timeout = self::TIMEOUT;

    /** @var int Attempts per request for this instance, including the first. */
    private $maxattempts = self::ATTEMPTS;

    /** @var float|null Wall-clock deadline (microtime) for all work by this instance. */
    private $deadline = null;

    /** @var string|null Base API URL. */
    private $apiurl;

    /** @var string|null API key. */
    private $apikey;

    /** @var string|null Technical description of the most recent failure. */
    private $lasterror = null;

    /** @var int|null HTTP status of the response that produced $lasterror. */
    private $lasthttpstatus = null;

    /** @var int Identifiers submitted to the batch status endpoint in the last call. */
    private $lastrequested = 0;

    /** @var int Identifiers Credentium recognised in the last batch status call. */
    private $lastreturned = 0;

    /** @var string|null Classification of the most recent failure (a FAIL_* constant). */
    private $lastfailurekind = null;

    /** @var string[] Identifiers from the last batch whose chunk never got an answer. */
    private $lastunanswered = [];

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
     * Override the HTTP timeout for this instance.
     *
     * Interactive callers (a page-load refresh) need a much tighter bound than the
     * default used by cron: a learner's page must not hang for 30 seconds because
     * the Credentium API is slow to answer.
     *
     * @param int $seconds Timeout in seconds (minimum 1).
     * @return void
     */
    public function set_timeout(int $seconds): void {
        $this->timeout = max(1, $seconds);
    }

    /**
     * Override how many times one request is attempted before it is treated as failed.
     *
     * Retrying is right for cron, which has time to ride out a transient fault, and
     * wrong on an interactive page, where a second attempt spends a learner's page
     * load on an API that has already failed once. Callers on that path pass 1.
     *
     * @param int $attempts Attempts including the first (minimum 1; 1 disables retrying).
     * @return void
     */
    public function set_max_attempts(int $attempts): void {
        $this->maxattempts = max(1, $attempts);
    }

    /**
     * Give this client a wall-clock deadline for everything it does.
     *
     * Attempt counts and per-call timeouts bound one HTTP call, not the total: a
     * batch splits into chunks, and every chunk brings its own retry ladder, so a
     * caller working to a time limit cannot derive its worst case from those alone
     * without re-deriving it whenever a constant changes. With a deadline the bound
     * is structural: no attempt is started that the remaining time cannot finish,
     * no retry is waited out that would cross it, and chunks left over are reported
     * as unanswered rather than quietly missing.
     *
     * @param float|null $deadline microtime(true) value, or null for no limit.
     * @return void
     */
    public function set_deadline(?float $deadline): void {
        $this->deadline = $deadline;
    }

    /**
     * Seconds left before the deadline, or null when there is no deadline.
     *
     * @return float|null
     */
    private function seconds_left(): ?float {
        return $this->deadline === null ? null : $this->deadline - microtime(true);
    }

    /**
     * The timeout for the next attempt, never longer than the deadline allows.
     *
     * @return int Seconds (at least 1: a zero timeout means "no limit" to curl).
     */
    private function effective_timeout(): int {
        $left = $this->seconds_left();
        if ($left === null) {
            return $this->timeout;
        }
        return (int) max(1, min($this->timeout, ceil($left)));
    }

    /**
     * Whether there is time to wait $delay seconds and still complete another attempt.
     *
     * @param int $delay Backoff the client would wait first.
     * @return bool
     */
    private function has_time_to_retry(int $delay): bool {
        $left = $this->seconds_left();
        return $left === null || $left > ($delay + $this->effective_timeout());
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
     * Whether the most recent failure was an authentication/authorisation refusal.
     *
     * Lets callers tell "this key may not read statuses" (actionable: widen the key's
     * scope) apart from "the API was unreachable", which needs entirely different advice.
     *
     * @return bool
     */
    public function last_error_was_auth(): bool {
        // Paired with $lasterror rather than with the last response, so a later
        // successful batch chunk cannot mask an earlier 401.
        return $this->get_last_failure_kind() === self::FAIL_AUTH;
    }

    /**
     * What kind of failure the most recent error was, for advice the admin can act on.
     *
     * Each kind calls for a different response — widen the key's scope, update the
     * plugin, lengthen the check interval, wait for Credentium to recover, or open the
     * firewall — so a report that only prints "HTTP 500" leaves an admin guessing which
     * one applies.
     *
     * @return string|null One of the FAIL_* constants, or null when nothing has failed.
     */
    public function get_last_failure_kind(): ?string {
        return $this->lasterror !== null ? $this->lastfailurekind : null;
    }

    /**
     * Identifiers from the last batch whose chunk never received an answer.
     *
     * Distinguishes "Credentium replied and did not recognise this id" (an id/key
     * mismatch worth warning about) from "the call failed, so nothing is known about
     * this id" (a transient outage). Both leave the id missing from the result map,
     * and conflating them told admins to check their API key during a service outage.
     *
     * @return string[] Identifiers with no answer, in submission order.
     */
    public function get_last_unanswered_ids(): array {
        return $this->lastunanswered;
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
        $this->lasthttpstatus = null;
        $this->lastfailurekind = null;
        $this->lastunanswered = [];
        $this->lastrequested = count($ids);
        $this->lastreturned = 0;
        foreach (array_chunk($ids, self::BATCH_MAX) as $index => $chunk) {
            if ($index > 0 && ($this->seconds_left() ?? 1.0) <= 0) {
                // Out of time part-way through a multi-chunk batch. Reported rather
                // than dropped, so the caller can say these are still pending instead
                // of implying Credentium had nothing to say about them. The first
                // chunk always runs: the caller decided there was time to start.
                $this->lasterror = $this->lasterror
                    ?? 'Ran out of time before finishing ' . self::PATH_STATUS;
                $this->lastfailurekind = $this->lastfailurekind ?? self::FAIL_BUSY;
                array_push($this->lastunanswered, ...$chunk);
                continue;
            }
            try {
                $response = $this->request('POST', self::PATH_STATUS, [], ['issueRequestIds' => $chunk]);
            } catch (\moodle_exception $e) {
                // Isolate the failure: keep results already gathered and poll the remaining chunks.
                // The reason is retained so the admin report can explain a sync that did nothing,
                // and the chunk's ids are remembered so callers do not mistake "no answer" for
                // "Credentium does not recognise these".
                require_once(__DIR__ . '/../../lib.php');
                local_credentiumclaim_log('Batch status chunk failed', [
                    'size' => count($chunk),
                    'error' => (string) $this->lasterror,
                ]);
                array_push($this->lastunanswered, ...$chunk);
                continue;
            }
            if (!is_object($response) || !isset($response->results) || !is_array($response->results)) {
                // A 2xx that does not carry a results array tells us nothing about this
                // chunk. Treated exactly like a failed call rather than silently dropped,
                // which is what used to leave credentials looking stuck with no reason shown.
                $this->lasterror = $this->lasterror ?? 'Unexpected response shape from ' . self::PATH_STATUS;
                $this->lastfailurekind = $this->lastfailurekind ?? self::FAIL_SERVER;
                array_push($this->lastunanswered, ...$chunk);
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
     * Transient failures — a refused connection, a read timeout, 408/429, or any 5xx —
     * are retried until {@see self::$maxattempts} attempts have been made, backing off
     * exponentially between them.
     * Without this a single blip on the Credentium side discarded a whole sync cycle:
     * every tracked credential stayed stale until the next scheduled run, and the
     * report blamed the identifiers. Deterministic refusals (4xx) are never retried;
     * repeating them would only multiply the load and delay the real answer.
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
            $this->lastfailurekind = self::FAIL_CLIENT;
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

        $path = parse_url($url, PHP_URL_PATH);

        require_once(__DIR__ . '/../../lib.php');
        local_credentiumclaim_log('API request: ' . $method . ' ' . $path);

        $bodyjson = ($data !== null) ? json_encode($data) : null;

        for ($attempt = 1;; $attempt++) {
            [$httpcode, $responsebody, $info] = $this->raw_request($method, $url, $headers, $bodyjson);

            if ($httpcode >= 200 && $httpcode < 300) {
                return $this->decode_success($responsebody, $httpcode, $path);
            }

            $delay = self::retry_delay($attempt, $info);
            $exhausted = $attempt >= $this->maxattempts;
            if (!self::is_retryable($httpcode) || $exhausted || !$this->has_time_to_retry($delay)) {
                throw $this->record_failure($method, $path, $httpcode, $responsebody, $info, $attempt);
            }

            local_credentiumclaim_log('API request retrying', [
                'path' => $path,
                'http_code' => $httpcode,
                'attempt' => $attempt,
            ]);
            $this->backoff_sleep($delay);
        }
    }

    /**
     * Decode the body of a successful response.
     *
     * @param mixed $responsebody Raw response body.
     * @param int $httpcode HTTP status that carried it.
     * @param string $path Request path, for the error message.
     * @return mixed Decoded response, or null for an empty body.
     */
    private function decode_success($responsebody, int $httpcode, string $path) {
        if ($responsebody === null || $responsebody === false || $responsebody === '') {
            return null;
        }
        $decoded = json_decode($responsebody);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // A 2xx that is not JSON breaks the documented contract, so it is the
            // service's fault rather than this site's configuration.
            $this->lasterror = 'Invalid JSON response from ' . $path;
            $this->lasthttpstatus = $httpcode;
            $this->lastfailurekind = self::FAIL_SERVER;
            throw new \moodle_exception('error:invalidjsonresponse', 'local_credentiumclaim');
        }
        return $decoded;
    }

    /**
     * Record a final (non-retryable, or retried-out) failure and build the exception for it.
     *
     * Returns rather than throws so the caller's `throw` keeps the control flow visible
     * at the call site.
     *
     * @param string $method HTTP method.
     * @param string $path Request path.
     * @param int $httpcode HTTP status (0 when the request never completed).
     * @param mixed $responsebody Raw response body.
     * @param array $info Transport info from {@see self::raw_request()}.
     * @param int $attempts How many attempts were made in total.
     * @return \moodle_exception The exception the caller must throw.
     */
    private function record_failure(
        string $method,
        string $path,
        int $httpcode,
        $responsebody,
        array $info,
        int $attempts
    ): \moodle_exception {
        $this->lasthttpstatus = $httpcode;
        $this->lastfailurekind = self::classify($httpcode);

        if ($httpcode === 0) {
            // Nothing answered, so there is no status to quote: "HTTP 0 from POST /path"
            // told an admin nothing about a DNS, TLS, proxy or timeout problem.
            $transport = isset($info['transport_error']) ? trim((string) $info['transport_error']) : '';
            $this->lasterror = 'Could not reach ' . $method . ' ' . $path
                . ($transport !== '' ? ': ' . $this->sanitize_for_log($transport) : '');
        } else {
            $this->lasterror = 'HTTP ' . $httpcode . ' from ' . $method . ' ' . $path;
            $reason = $this->extract_error_reason($responsebody);
            if ($reason !== null) {
                // Every documented error shape has a plain-text reason, and a 401 caused by
                // scope may name the missing scope (e.g. "required_scope": "credentials:read").
                // Neither field is documented as ever carrying a secret, but it is still run
                // through the same redaction as everything else logged or shown to admins.
                $this->lasterror .= ': ' . $this->sanitize_for_log($reason);
            }
        }
        if ($attempts > 1) {
            // Tells the admin the plugin already gave the service a fair chance, so a
            // persistent error is worth escalating rather than waiting out.
            $this->lasterror .= ' (after ' . $attempts . ' attempts)';
        }

        // Log a sanitised summary; the exception itself never carries secrets.
        local_credentiumclaim_log('API request failed', [
            'path' => $path,
            'method' => $method,
            'http_code' => $httpcode,
            'attempts' => $attempts,
            'body' => $this->sanitize_for_log((string) $responsebody),
        ]);
        $debuginfo = 'HTTP ' . $httpcode . ' for ' . $path;
        return new \moodle_exception('apierror', 'local_credentiumclaim', '', null, $debuginfo);
    }

    /**
     * Whether a failed response is worth attempting again.
     *
     * @param int $httpcode HTTP status (0 when the request never completed).
     * @return bool
     */
    private static function is_retryable(int $httpcode): bool {
        // 0 means the request never completed (DNS, TLS, connect or read timeout);
        // 408/429 and every 5xx are, by their own definition, "ask again" answers.
        return $httpcode === 0 || $httpcode === 408 || $httpcode === 429 || $httpcode >= 500;
    }

    /**
     * Which of the FAIL_* buckets a status code belongs to.
     *
     * @param int $httpcode HTTP status (0 when the request never completed).
     * @return string A FAIL_* constant.
     */
    private static function classify(int $httpcode): string {
        if ($httpcode === 0) {
            return self::FAIL_NETWORK;
        }
        if ($httpcode === 401 || $httpcode === 403) {
            // The documented API only uses 401, but both mean "this key may not do that".
            return self::FAIL_AUTH;
        }
        if ($httpcode === 408 || $httpcode === 429) {
            // Kept apart from the other 4xx: nothing is wrong with the request, so
            // "look for a plugin update" would be the wrong advice for a rate limit.
            return self::FAIL_BUSY;
        }
        if ($httpcode >= 500) {
            return self::FAIL_SERVER;
        }
        return self::FAIL_CLIENT;
    }

    /**
     * How long to wait before the next attempt.
     *
     * Honours a `Retry-After` header when the service sends one (a 429 usually does),
     * and otherwise doubles the wait per attempt. No jitter is added: a Moodle site
     * runs one sync at a time under the cron lock, so there is no fleet of clients to
     * de-synchronise, and a deterministic delay keeps the worst case predictable.
     *
     * @param int $attempt The attempt that just failed (1-based).
     * @param array $info Transport info from {@see self::raw_request()}.
     * @return int Seconds to wait, at least 1 and at most self::BACKOFF_MAX_SECONDS.
     */
    private static function retry_delay(int $attempt, array $info): int {
        $advertised = self::retry_after_seconds($info);
        if ($advertised !== null) {
            return max(1, min($advertised, self::BACKOFF_MAX_SECONDS));
        }
        $seconds = self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempt - 1));
        return max(1, min((int) $seconds, self::BACKOFF_MAX_SECONDS));
    }

    /**
     * The `Retry-After` delay a response asked for, in seconds.
     *
     * Accepts both documented forms (delay-seconds and an HTTP-date) and both shapes
     * Moodle's curl wrapper can hand back (a name-keyed map, or raw header lines).
     *
     * @param array $info Transport info from {@see self::raw_request()}.
     * @return int|null Null when the header is absent or unparseable.
     */
    private static function retry_after_seconds(array $info): ?int {
        $headers = $info['response_headers'] ?? null;
        if (!is_array($headers)) {
            return null;
        }

        $value = null;
        foreach ($headers as $name => $raw) {
            if (is_array($raw)) {
                // A repeated header is collapsed into an array by getResponse(); the
                // first value is the one curl saw first.
                $raw = reset($raw);
            }
            if (!is_string($raw)) {
                continue;
            }
            if (is_string($name) && strcasecmp($name, 'Retry-After') === 0) {
                $value = $raw;
                break;
            }
            if (stripos($raw, 'retry-after:') === 0) {
                $value = substr($raw, strlen('retry-after:'));
                break;
            }
        }
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return max(0, $timestamp - time());
    }

    /**
     * Wait between two attempts. Overridden in unit tests so they do not really sleep.
     *
     * @param int $seconds Seconds to wait.
     * @return void
     */
    protected function backoff_sleep(int $seconds): void {
        sleep($seconds);
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
     *               The info array carries two extra keys this class relies on:
     *               'transport_error' (curl's own message when nothing was received) and
     *               'response_headers' (used to honour Retry-After). Both are optional,
     *               so a test double may keep returning a plain [code, body, []].
     */
    protected function raw_request(string $method, string $url, array $headers, ?string $body): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl(['debug' => false]);
        $curl->setopt([
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_TIMEOUT' => $this->effective_timeout(),
        ]);

        if ($method === 'POST') {
            $responsebody = $curl->post($url, $body ?? '');
        } else {
            $responsebody = $curl->get($url);
        }

        $info = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);
        if ($httpcode === 0 && is_string($curl->error) && $curl->error !== '') {
            // cURL gave up before any response arrived; its message is the only clue
            // an admin has about why (name resolution, TLS, proxy, timeout).
            $info['transport_error'] = (string) $curl->error;
        }
        $info['response_headers'] = $curl->getResponse();
        return [$httpcode, $responsebody, $info];
    }
}
