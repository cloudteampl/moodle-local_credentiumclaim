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
