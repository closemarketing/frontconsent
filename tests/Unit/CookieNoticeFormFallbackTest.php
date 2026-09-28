<?php
/**
 * Tests for the no-JS <form> fallback's submission handling
 * (CookieNotice::process_consent_form_submission()) — this is what lets a
 * visitor without JavaScript actually accept/reject, since the banner's
 * buttons alone (type="submit" with a JS-only click handler that calls
 * preventDefault()) do nothing without it.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeFormFallbackTest extends TestCase {

	/**
	 * @var CookieNotice
	 */
	private $cookie_notice;

	public function set_up() {
		parent::set_up();
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => true ) );
		$this->cookie_notice = new CookieNotice();
	}

	public function tear_down() {
		delete_option( 'frontconsent_settings' );
		unset( $_POST['frcn_decision'], $_POST['frcn_redirect'] );
		parent::tear_down();
	}

	public function test_valid_acceptance_returns_the_requested_redirect() {
		$_POST['frcn_decision'] = 'accepted';
		$_POST['frcn_redirect'] = home_url( '/some-page/' );

		$redirect = $this->cookie_notice->process_consent_form_submission();

		$this->assertSame( home_url( '/some-page/' ), $redirect );
	}

	/**
	 * Deliberately unauthenticated (see process_consent_form_submission()'s
	 * own docblock): a no-JS visitor has no way to fetch a fresh nonce
	 * before submitting, and the banner it's embedded in is cache-neutral
	 * HTML, so any nonce baked into it would go stale under a full-page
	 * cache. No nonce field is even sent by the form.
	 */
	public function test_submission_is_processed_without_any_nonce() {
		$_POST['frcn_decision'] = 'accepted';
		$_POST['frcn_redirect'] = home_url( '/some-page/' );

		$redirect = $this->cookie_notice->process_consent_form_submission();

		$this->assertSame( home_url( '/some-page/' ), $redirect );
	}

	/**
	 * Cookie Notice disabled must skip processing the decision, but must
	 * still return a redirect target.
	 */
	public function test_disabled_cookie_notice_still_returns_a_redirect() {
		update_option( 'frontconsent_settings', array( 'enable_cookie_notice' => false ) );

		$_POST['frcn_decision'] = 'accepted';
		$_POST['frcn_redirect'] = home_url( '/some-page/' );

		$redirect = $this->cookie_notice->process_consent_form_submission();

		$this->assertSame( home_url( '/some-page/' ), $redirect );
	}

	/**
	 * An off-site redirect target must fall back to the home URL —
	 * wp_validate_redirect() is what makes the visitor-controlled
	 * frcn_redirect POST field safe to redirect to at all.
	 */
	public function test_redirect_falls_back_to_home_when_target_is_off_site() {
		$_POST['frcn_decision'] = 'accepted';
		$_POST['frcn_redirect'] = 'https://evil.example.com/';

		$redirect = $this->cookie_notice->process_consent_form_submission();

		$this->assertStringStartsWith( home_url( '/' ), $redirect );
		$this->assertStringNotContainsString( 'evil.example.com', $redirect );
	}

	/**
	 * An invalid decision value must not be processed, but must still
	 * return a redirect target.
	 */
	public function test_invalid_decision_is_ignored_but_still_redirects() {
		$_POST['frcn_decision'] = 'not-a-real-decision';
		$_POST['frcn_redirect'] = home_url( '/some-page/' );

		$redirect = $this->cookie_notice->process_consent_form_submission();

		$this->assertSame( home_url( '/some-page/' ), $redirect );
	}

	/**
	 * The Set-Cookie header value must contain the decision, path=/ (not
	 * COOKIEPATH — see the matching PHP-side comment) and SameSite=Lax —
	 * this is what makes the decision stick at all on PHP 7.0-7.2, where
	 * setcookie()'s options-array form (and its SameSite support) doesn't
	 * exist yet, since header() is used instead specifically to reach every
	 * supported PHP version with the same cookie attributes.
	 */
	public function test_cookie_header_value_contains_the_decision_and_expected_attributes() {
		$header = $this->cookie_notice->build_consent_cookie_header_value( 'accepted', time() + DAY_IN_SECONDS );

		$this->assertStringContainsString( 'frcn_cookie_consent=accepted', $header );
		$this->assertStringContainsString( 'path=/', $header );
		$this->assertStringContainsString( 'SameSite=Lax', $header );
	}
}
