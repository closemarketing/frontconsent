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
		unset( $_POST['frcn_nonce'], $_POST['frcn_decision'], $_POST['frcn_redirect'] );
		parent::tear_down();
	}

	public function test_valid_acceptance_returns_the_requested_redirect() {
		$_POST['frcn_nonce']    = wp_create_nonce( CookieNotice::NONCE_ACTION );
		$_POST['frcn_decision'] = 'accepted';
		$_POST['frcn_redirect'] = home_url( '/some-page/' );

		$redirect = $this->cookie_notice->process_consent_form_submission();

		$this->assertSame( home_url( '/some-page/' ), $redirect );
	}

	/**
	 * A missing/invalid nonce must still return a redirect target (so the
	 * visitor isn't left on a dead admin-post.php response) but must not
	 * process the decision.
	 */
	public function test_missing_nonce_still_returns_a_redirect() {
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
		$_POST['frcn_nonce']    = wp_create_nonce( CookieNotice::NONCE_ACTION );
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
		$_POST['frcn_nonce']    = wp_create_nonce( CookieNotice::NONCE_ACTION );
		$_POST['frcn_decision'] = 'not-a-real-decision';
		$_POST['frcn_redirect'] = home_url( '/some-page/' );

		$redirect = $this->cookie_notice->process_consent_form_submission();

		$this->assertSame( home_url( '/some-page/' ), $redirect );
	}
}
