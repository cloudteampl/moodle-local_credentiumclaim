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
 * Unit tests for the Credentium API client.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

/**
 * Tests for {@see \local_credentiumclaim\api\client}.
 *
 * @covers \local_credentiumclaim\api\client
 */
final class client_test extends \advanced_testcase {
    /**
     * Build a client whose transport is captured in memory instead of hitting the network.
     *
     * @param string $apikey API key to configure.
     * @return \local_credentiumclaim\api\client Anonymous subclass exposing $requests and $handler.
     */
    private function make_client(string $apikey = 'pub.secretkey') {
        return new class ('https://api.example.com', $apikey) extends \local_credentiumclaim\api\client {
            /** @var array[] Captured requests. */
            public array $requests = [];
            /** @var callable|null Optional responder taking method, url and body. */
            public $handler = null;
            /** @var int[] Backoff waits the client asked for, in order. */
            public array $waits = [];

            /**
             * Capture the request and return a canned or handler-provided response.
             *
             * @param string $method HTTP method.
             * @param string $url Request URL.
             * @param string[] $headers Request headers.
             * @param string|null $body Request body.
             * @return array [http_code, response_body, curl_info]
             */
            protected function raw_request(string $method, string $url, array $headers, ?string $body): array {
                $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                if ($this->handler !== null) {
                    return ($this->handler)($method, $url, $body);
                }
                return [200, json_encode(['results' => []]), []];
            }

            /**
             * Record the wait instead of performing it, so retry tests stay instant.
             *
             * @param int $seconds Seconds the client wanted to wait.
             * @return void
             */
            protected function backoff_sleep(int $seconds): void {
                $this->waits[] = $seconds;
            }
        };
    }

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_status_batch_shapes_request_and_maps_by_id(): void {
        $client = $this->make_client();
        $client->handler = function ($method, $url, $body) {
            $ids = json_decode($body)->issueRequestIds;
            $results = [];
            foreach ($ids as $id) {
                $results[] = ['issueRequestId' => $id, 'status' => 'claimed', 'credentialId' => 'cred-' . $id];
            }
            return [200, json_encode(['results' => $results]), []];
        };

        $map = $client->get_status_batch(['a', 'b']);

        $req = $client->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertStringEndsWith('/api/credential-issue-requests/statuses', $req['url']);
        $this->assertSame(['a', 'b'], json_decode($req['body'])->issueRequestIds);
        $this->assertSame('claimed', $map['a']->status);
        $this->assertSame('cred-a', $map['a']->credentialid);
        $this->assertSame('claimed', $map['b']->status);
    }

    public function test_status_batch_splits_over_500_ids(): void {
        $client = $this->make_client();
        $client->handler = function ($method, $url, $body) {
            $ids = json_decode($body)->issueRequestIds;
            $results = array_map(fn($id) => ['issueRequestId' => $id, 'status' => 'issued'], $ids);
            return [200, json_encode(['results' => $results]), []];
        };

        $ids = [];
        for ($i = 0; $i < 501; $i++) {
            $ids[] = 'id' . $i;
        }
        $map = $client->get_status_batch($ids);

        $this->assertCount(2, $client->requests, 'Expected 501 ids to split into two batches (500 + 1).');
        $this->assertCount(501, $map);
    }

    public function test_status_batch_empty_makes_no_request(): void {
        $client = $this->make_client();
        $map = $client->get_status_batch([]);
        $this->assertSame([], $map);
        $this->assertCount(0, $client->requests);
    }

    public function test_claim_link_request_and_mapping(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [200, json_encode([
            'actionType' => 'login_and_claim',
            'claimUrl' => 'https://issuer.example/claim?code=SECRET123',
            'expiresAt' => '2026-01-01T00:00:00Z',
        ]), []];

        $res = $client->get_claim_link('req-1', 'pl');

        $req = $client->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertStringEndsWith('/api/credential-issue-requests/req-1/claim-link', $req['url']);
        $this->assertSame('pl', json_decode($req['body'])->locale);
        $this->assertSame(\local_credentiumclaim\api\client::ACTION_LOGIN, $res->actiontype);
        $this->assertSame('https://issuer.example/claim?code=SECRET123', $res->claimurl);
        $this->assertSame('2026-01-01T00:00:00Z', $res->expiresat);
    }

    public function test_claim_link_locale_falls_back_to_en(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [200, json_encode(['actionType' => 'already_claimed']), []];

        $res = $client->get_claim_link('req-2', 'de');

        $this->assertSame('en', json_decode($client->requests[0]['body'])->locale);
        $this->assertSame(\local_credentiumclaim\api\client::ACTION_ALREADY, $res->actiontype);
        $this->assertNull($res->claimurl);
    }

    public function test_unknown_actiontype_is_normalised(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [200, json_encode(['actionType' => 'something_new']), []];

        $res = $client->get_claim_link('req-3', 'en');

        $this->assertSame(\local_credentiumclaim\api\client::ACTION_UNKNOWN, $res->actiontype);
    }

    public function test_sanitize_for_log_redacts_claimurl_and_key(): void {
        $client = $this->make_client('pub.secretkey');
        $dirty = json_encode([
            'claimUrl' => 'https://issuer.example/claim?code=abc123',
            'echoedkey' => 'pub.secretkey',
        ]);

        $clean = $client->sanitize_for_log($dirty);

        $this->assertStringNotContainsString('code=abc123', $clean);
        $this->assertStringNotContainsString('secretkey', $clean);
        $this->assertStringContainsString('[REDACTED]', $clean);
    }

    public function test_credentials_are_inherited_from_the_connector_plugin(): void {
        set_config('apiurl', 'https://issuer.example.com/api', 'local_credentium');
        set_config('apikey', 'pub.secretkey', 'local_credentium');

        $client = new \local_credentiumclaim\api\client();

        $this->assertTrue(
            $client->is_configured(),
            'The plugin must reuse the connector credentials instead of asking for them again.'
        );
    }

    public function test_client_is_unconfigured_when_the_connector_has_no_credentials(): void {
        set_config('apiurl', '', 'local_credentium');
        set_config('apikey', '', 'local_credentium');

        $client = new \local_credentiumclaim\api\client();

        $this->assertFalse($client->is_configured());
    }

    public function test_batch_stats_expose_unrecognised_identifiers(): void {
        $client = $this->make_client();
        $client->handler = function ($method, $url, $body) {
            $ids = json_decode($body)->issueRequestIds;
            // Credentium only recognises the first id.
            return [200, json_encode(['results' => [
                ['issueRequestId' => $ids[0], 'status' => 'issued'],
            ]]), []];
        };

        $client->get_status_batch(['known', 'foreign']);

        $this->assertSame(['requested' => 2, 'returned' => 1], $client->get_last_batch_stats());
        $this->assertNull($client->get_last_error());
    }

    public function test_failed_batch_records_a_diagnosable_error(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [404, 'Not Found', []];

        $map = $client->get_status_batch(['a']);

        $this->assertSame([], $map, 'A failed chunk must not fabricate statuses.');
        $this->assertNotNull(
            $client->get_last_error(),
            'A silently swallowed failure is what made credentials look stuck.'
        );
        $this->assertStringContainsString('404', $client->get_last_error());
    }

    public function test_failed_batch_surfaces_missing_scope(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [401, json_encode([
            'error' => 'Invalid API key or insufficient permissions',
            'required_scope' => 'credentials:read',
        ]), []];

        $client->get_status_batch(['a']);

        // A key inherited from a plugin that only issues (not reads) credentials
        // must be diagnosable from the report, not just "HTTP 401".
        $this->assertStringContainsString('credentials:read', $client->get_last_error());
        $this->assertStringContainsString('insufficient permissions', $client->get_last_error());
        $this->assertTrue($client->last_error_was_auth());
    }

    public function test_non_auth_failure_is_not_reported_as_an_auth_problem(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [500, 'Internal Server Error', []];

        $client->get_status_batch(['a']);

        // Advice to widen the API key's scope must not be given for a server outage.
        $this->assertNotNull($client->get_last_error());
        $this->assertFalse($client->last_error_was_auth());
    }

    public function test_a_later_successful_chunk_does_not_mask_an_earlier_auth_failure(): void {
        $client = $this->make_client();
        $calls = 0;
        $client->handler = function ($method, $url, $body) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return [401, json_encode(['error' => 'Invalid API key or insufficient permissions']), []];
            }
            return [200, json_encode(['results' => []]), []];
        };

        // 501 ids split into two chunks: the first is refused, the second succeeds.
        $ids = [];
        for ($i = 0; $i < 501; $i++) {
            $ids[] = 'id' . $i;
        }
        $client->get_status_batch($ids);

        $this->assertTrue(
            $client->last_error_was_auth(),
            'The status must belong to the failing response, not to whatever came last.'
        );
    }

    public function test_a_transient_server_error_is_retried_and_then_succeeds(): void {
        $client = $this->make_client();
        $calls = 0;
        $client->handler = function ($method, $url, $body) use (&$calls) {
            $calls++;
            if ($calls < 3) {
                return [500, '', []];
            }
            $ids = json_decode($body)->issueRequestIds;
            return [200, json_encode(['results' => [
                ['issueRequestId' => $ids[0], 'status' => 'issued'],
            ]]), []];
        };

        $map = $client->get_status_batch(['a']);

        // A blip on the Credentium side used to discard the whole sync cycle: every
        // tracked credential stayed stale until the next scheduled run.
        $this->assertSame('issued', $map['a']->status);
        $this->assertCount(3, $client->requests);
        $this->assertSame([1, 2], $client->waits, 'Backoff must grow between attempts.');
        $this->assertNull($client->get_last_error());
    }

    public function test_a_client_error_is_not_retried(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [404, json_encode(['error' => 'Not found']), []];

        $client->get_status_batch(['a']);

        // Repeating a deterministic refusal only multiplies load on the API.
        $this->assertCount(1, $client->requests);
        $this->assertSame([], $client->waits);
    }

    public function test_a_persistent_server_error_gives_up_and_says_how_hard_it_tried(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [500, '', []];

        $client->get_status_batch(['a']);

        $this->assertCount(3, $client->requests, 'Retrying must be bounded.');
        $this->assertStringContainsString('after 3 attempts', $client->get_last_error());
        $this->assertSame(\local_credentiumclaim\api\client::FAIL_SERVER, $client->get_last_failure_kind());
    }

    public function test_retrying_can_be_switched_off_for_interactive_callers(): void {
        $client = $this->make_client();
        $client->set_max_attempts(1);
        $client->handler = fn($m, $u, $b) => [500, '', []];

        $client->get_status_batch(['a']);

        // A learner's page load must not be spent on an API that already failed once.
        $this->assertCount(1, $client->requests);
        $this->assertStringNotContainsString('attempts', (string) $client->get_last_error());
    }

    public function test_retry_after_header_is_honoured_over_the_backoff(): void {
        $client = $this->make_client();
        $calls = 0;
        $client->handler = function ($method, $url, $body) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return [429, '', ['response_headers' => ['Retry-After' => '5']]];
            }
            return [200, json_encode(['results' => []]), []];
        };

        $client->get_status_batch(['a']);

        $this->assertSame([5], $client->waits, 'A service that says when to come back must be obeyed.');
    }

    public function test_retry_after_is_capped_so_one_header_cannot_stall_cron(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [503, '', ['response_headers' => ['retry-after: 3600']]];

        $client->get_status_batch(['a']);

        $this->assertSame([8, 8], $client->waits, 'An hour-long Retry-After must not hold up the whole run.');
    }

    public function test_an_unreachable_api_is_not_reported_as_http_0(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [0, false, ['transport_error' => 'Could not resolve host: api.example.com']];

        $client->get_status_batch(['a']);

        // "HTTP 0 from POST /api/..." told an admin nothing about a DNS or proxy problem.
        $error = (string) $client->get_last_error();
        $this->assertStringNotContainsString('HTTP 0', $error);
        $this->assertStringContainsString('Could not resolve host', $error);
        $this->assertSame(\local_credentiumclaim\api\client::FAIL_NETWORK, $client->get_last_failure_kind());
        $this->assertCount(3, $client->requests, 'A dropped connection is worth another try.');
    }

    /**
     * Each failure kind calls for entirely different admin advice.
     *
     * @dataProvider failure_kind_provider
     * @param int $httpcode Status the API answered with (0 = never answered).
     * @param string $expected The FAIL_* constant it must be classified as.
     */
    public function test_failures_are_classified_for_actionable_advice(int $httpcode, string $expected): void {
        $client = $this->make_client();
        $client->set_max_attempts(1);
        $client->handler = fn($m, $u, $b) => [$httpcode, '', []];

        $client->get_status_batch(['a']);

        $this->assertSame($expected, $client->get_last_failure_kind());
    }

    /**
     * Status codes and the failure kind each must map onto.
     *
     * @return array[] Rows of [http code, expected FAIL_* constant].
     */
    public static function failure_kind_provider(): array {
        return [
            'unreachable' => [0, \local_credentiumclaim\api\client::FAIL_NETWORK],
            'unauthorised' => [401, \local_credentiumclaim\api\client::FAIL_AUTH],
            'forbidden' => [403, \local_credentiumclaim\api\client::FAIL_AUTH],
            'not found' => [404, \local_credentiumclaim\api\client::FAIL_CLIENT],
            'server error' => [500, \local_credentiumclaim\api\client::FAIL_SERVER],
            'gateway timeout' => [504, \local_credentiumclaim\api\client::FAIL_SERVER],
        ];
    }

    public function test_no_answer_is_distinguished_from_an_unrecognised_identifier(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [500, '', []];

        $client->get_status_batch(['a', 'b']);

        // Both cases leave the ids missing from the map, but only one of them is a
        // reason to go and check the API key.
        $this->assertSame(['a', 'b'], $client->get_last_unanswered_ids());
    }

    public function test_an_answered_but_unrecognised_identifier_is_not_reported_as_unanswered(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [200, json_encode(['results' => []]), []];

        $client->get_status_batch(['a']);

        $this->assertSame([], $client->get_last_unanswered_ids());
        $this->assertNull($client->get_last_error());
    }

    public function test_a_2xx_without_results_is_reported_rather_than_silently_dropped(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [200, json_encode(['unexpected' => true]), []];

        $map = $client->get_status_batch(['a']);

        $this->assertSame([], $map);
        $this->assertNotNull($client->get_last_error(), 'A nonsense response must not look like a clean run.');
        $this->assertSame(['a'], $client->get_last_unanswered_ids());
    }

    public function test_non_2xx_throws_apierror_without_leaking_secret(): void {
        $client = $this->make_client();
        $client->handler = fn($m, $u, $b) => [500, json_encode([
            'claimUrl' => 'https://issuer.example/claim?code=TOPSECRET',
            'message' => 'boom',
        ]), []];

        try {
            $client->get_claim_link('req-9', 'en');
            $this->fail('Expected a moodle_exception for a 500 response.');
        } catch (\moodle_exception $e) {
            $this->assertSame('apierror', $e->errorcode);
            $this->assertStringNotContainsString('TOPSECRET', (string)($e->debuginfo ?? ''));
        }
    }
}
