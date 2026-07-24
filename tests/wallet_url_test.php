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
 * Unit tests for resolving the Credentium Wallet address.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_credentiumclaim;

/**
 * Tests for the wallet-address helpers in lib.php.
 *
 * @covers ::local_credentiumclaim_wallet_base_url
 * @covers ::local_credentiumclaim_remember_wallet_base
 * @covers ::local_credentiumclaim_wallet_credential_url
 */
final class wallet_url_test extends \advanced_testcase {
    public function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/local/credentiumclaim/lib.php');
    }

    public function test_the_address_is_learned_from_a_claim_url(): void {
        local_credentiumclaim_remember_wallet_base(
            'https://wallet.example.com/account/login?returnUrl=%2Fcredentials%2Fabc'
        );

        // The API states this address nowhere else, so learning it is what spares an
        // administrator from configuring a value the system already knows.
        $this->assertSame('https://wallet.example.com', local_credentiumclaim_wallet_base_url());
    }

    public function test_only_the_origin_of_a_claim_url_is_ever_stored(): void {
        local_credentiumclaim_remember_wallet_base(
            'https://wallet.example.com/account/create?code=TOPSECRET&culture=pl'
        );

        // A claim URL is a single-use, bearer-equivalent secret. Keeping only the
        // origin is precisely what makes remembering it safe.
        $stored = (string) get_config('local_credentiumclaim', 'walletbaselearned');
        $this->assertSame('https://wallet.example.com', $stored);
        $this->assertStringNotContainsString('TOPSECRET', $stored);
        $this->assertStringNotContainsString('code=', $stored);
    }

    public function test_a_port_is_kept_so_a_test_wallet_still_resolves(): void {
        local_credentiumclaim_remember_wallet_base('http://localhost:8080/account/login?returnUrl=%2Fx');

        $this->assertSame('http://localhost:8080', local_credentiumclaim_wallet_base_url());
    }

    /**
     * Nothing usable must ever be inferred from a URL that is not one.
     *
     * @dataProvider unusable_claim_url_provider
     * @param string $claimurl Value handed to the learner.
     */
    public function test_an_unusable_claim_url_teaches_nothing(string $claimurl): void {
        local_credentiumclaim_remember_wallet_base($claimurl);

        $this->assertNull(local_credentiumclaim_wallet_base_url());
    }

    /**
     * Claim-URL shapes that must not be learned from.
     *
     * @return array[] Rows of [claim url].
     */
    public static function unusable_claim_url_provider(): array {
        return [
            'empty' => [''],
            'not a url' => ['nonsense'],
            'no host' => ['/account/login?returnUrl=%2Fcredentials%2Fabc'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
        ];
    }

    public function test_an_administrator_override_wins_over_what_was_learned(): void {
        local_credentiumclaim_remember_wallet_base('https://wallet.learned.example/account/login');
        set_config('walleturl', 'https://wallet.chosen.example', 'local_credentiumclaim');

        // The setting exists to correct a wrong or stale guess, so it has to outrank it.
        $this->assertSame('https://wallet.chosen.example', local_credentiumclaim_wallet_base_url());
    }

    public function test_a_trailing_slash_in_the_setting_does_not_double_up(): void {
        set_config('walleturl', 'https://wallet.example.com/', 'local_credentiumclaim');

        $url = local_credentiumclaim_wallet_credential_url('cred-1');

        $this->assertStringStartsWith('https://wallet.example.com/account/login', $url->out(false));
    }

    public function test_the_credential_link_matches_what_the_api_itself_builds(): void {
        set_config('walleturl', 'https://wallet.example.com', 'local_credentiumclaim');

        $url = local_credentiumclaim_wallet_credential_url('8b1b7c3a');

        // Same shape as ClaimLinkBuilder.BuildLoginUrl on the issuer: the wallet
        // enforces authentication on the credential page, so this carries no secret.
        $this->assertSame(
            'https://wallet.example.com/account/login?returnUrl=%2Fcredentials%2F8b1b7c3a',
            $url->out(false)
        );
    }

    public function test_no_link_is_offered_when_something_is_missing(): void {
        // No wallet address known yet.
        $this->assertNull(local_credentiumclaim_wallet_credential_url('cred-1'));

        set_config('walleturl', 'https://wallet.example.com', 'local_credentiumclaim');

        // Address known, but the credential has no id (still processing, or tracked
        // before the plugin started storing it). A button leading nowhere is worse
        // than none, so the page falls back to plain "Claimed".
        $this->assertNull(local_credentiumclaim_wallet_credential_url(null));
        $this->assertNull(local_credentiumclaim_wallet_credential_url(''));
    }
}
