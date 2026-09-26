<?php
/**
 * Tests for CookieNotice's Clientify/Brevo tracking snippet auto-detection.
 *
 * @package FrontConsent
 */

use FrontConsent\Frontend\CookieNotice;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class CookieNoticeTrackingSnippetTest extends TestCase {

	/**
	 * GTM's container ID can be pasted on its own, without any surrounding
	 * snippet — this is how the generic tracking field is documented to work
	 * for the two native Google types.
	 */
	public function test_detects_bare_gtm_container_id() {
		$detected = CookieNotice::detect_tracking_snippet( 'gtm-abc1234' );

		$this->assertSame( 'gtm', $detected['type'] );
		$this->assertSame( 'GTM-ABC1234', $detected['id'] );
	}

	/**
	 * A full GTM loader snippet (e.g. copied from Google Tag Manager's own
	 * install instructions) also carries the container ID in its src URL.
	 */
	public function test_detects_gtm_container_id_from_a_full_snippet() {
		$snippet = <<<'HTML'
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-ABC1234');</script>
HTML;

		$detected = CookieNotice::detect_tracking_snippet( $snippet );

		$this->assertSame( 'gtm', $detected['type'] );
		$this->assertSame( 'GTM-ABC1234', $detected['id'] );
	}

	/**
	 * GA4's measurement ID can also be pasted on its own.
	 */
	public function test_detects_bare_ga4_measurement_id() {
		$detected = CookieNotice::detect_tracking_snippet( 'g-abc1234567' );

		$this->assertSame( 'ga4', $detected['type'] );
		$this->assertSame( 'G-ABC1234567', $detected['id'] );
	}

	/**
	 * A full gtag.js loader snippet also carries the measurement ID in its
	 * src URL.
	 */
	public function test_detects_ga4_measurement_id_from_a_full_snippet() {
		$snippet = <<<'HTML'
<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234567"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', 'G-ABC1234567');
</script>
HTML;

		$detected = CookieNotice::detect_tracking_snippet( $snippet );

		$this->assertSame( 'ga4', $detected['type'] );
		$this->assertSame( 'G-ABC1234567', $detected['id'] );
	}

	/**
	 * The Clientify Analytics Plus snippet is just a single pixel <script>
	 * tag — the id is the last path segment of its src URL.
	 */
	public function test_detects_clientify_analytics_plus_pixel() {
		$snippet = <<<'HTML'
<!--Clientify Tracking Begins-->
<script defer src="https://analyticsplusdev.clientify.net/analytics_plus/pixel/TestPixelId123"></script>
<!--Clientify Tracking Ends-->
HTML;

		$detected = CookieNotice::detect_tracking_snippet( $snippet );

		$this->assertSame( 'clientify_analytics_plus', $detected['type'] );
		$this->assertSame( 'TestPixelId123', $detected['id'] );
	}

	/**
	 * The classic Clientify Analytics snippet loads a different domain
	 * (tracker.js, not the Analytics Plus pixel host) and carries its code
	 * inside a setTrackingCode(...) call rather than in a URL.
	 */
	public function test_detects_clientify_analytics_classic_tracking_code() {
		$snippet = <<<'HTML'
<!--Clientify Tracking Begins-->
<script type="text/javascript">
if (typeof trackerCode ==='undefined'){
    (function (d, w, u, o) {
        w[o] = w[o] || function () {
            (w[o].q = w[o].q || []).push(arguments)
        };
        a = d.createElement('script'),
            m = d.getElementsByTagName('script')[0];
        a.async = 1; a.src = u;
        m.parentNode.insertBefore(a, m)
    })(document, window, 'https://analytics.clientify.net/tracker.js', 'ana');
    ana('setTrackerUrl', 'https://analytics.clientify.net');
    ana('setTrackingCode', 'CF-00000-00000-TEST');
    ana('trackPageview');
}</script>
<!--Clientify Tracking Ends-->
HTML;

		$detected = CookieNotice::detect_tracking_snippet( $snippet );

		$this->assertSame( 'clientify_analytics_classic', $detected['type'] );
		$this->assertSame( 'CF-00000-00000-TEST', $detected['id'] );
	}

	/**
	 * Brevo's SDK loader + init() snippet carries its id as a client_key.
	 */
	public function test_detects_brevo_client_key() {
		$snippet = <<<'HTML'
<script src="https://cdn.brevo.com/js/sdk-loader.js" async></script>
<script>
    window.Brevo = window.Brevo || [];
    Brevo.push([
        "init",
        {
        client_key: "testclientkey1234567890",
        }
    ]);
</script>
HTML;

		$detected = CookieNotice::detect_tracking_snippet( $snippet );

		$this->assertSame( 'brevo', $detected['type'] );
		$this->assertSame( 'testclientkey1234567890', $detected['id'] );
	}

	/**
	 * ChatGPT Ads can be configured from the vendor-provided oaiq snippet.
	 */
	public function test_detects_chatgpt_ads_snippet() {
		$snippet = <<<'HTML'
<script>!function(w,d,s,u){if(w.oaiq)return;var q=function(){q.q.push(arguments)};q.q=[];w.oaiq=q;var j=d.createElement(s);j.async=1;j.src=u;var f=d.getElementsByTagName(s)[0];f.parentNode.insertBefore(j,f)}(window,document,"script","https://bzrcdn.openai.com/sdk/oaiq.min.js");oaiq("init",{pixelId:"TestChatGPTPixelId1234",debug:true});</script>
HTML;

		$detected = CookieNotice::detect_tracking_snippet( $snippet );

		$this->assertSame( 'openai_chatgpt_ads', $detected['type'] );
		$this->assertSame( 'TestChatGPTPixelId1234', $detected['id'] );
	}

	/**
	 * ChatGPT Ads can also be configured by pasting only its Pixel ID.
	 */
	public function test_detects_chatgpt_ads_pixel_id() {
		$detected = CookieNotice::detect_tracking_snippet( 'TestChatGPTPixelId1234' );

		$this->assertSame( 'openai_chatgpt_ads', $detected['type'] );
		$this->assertSame( 'TestChatGPTPixelId1234', $detected['id'] );
	}

	/**
	 * A generic provider code must not be treated as a ChatGPT Ads Pixel ID.
	 */
	public function test_does_not_mistake_a_hyphenated_tracking_code_for_a_chatgpt_ads_pixel_id() {
		$this->assertNull( CookieNotice::detect_tracking_snippet( 'CF-00000-00000-TEST' ) );
		$this->assertNull( CookieNotice::detect_tracking_snippet( 'testclientkey1234567890' ) );
	}

	/**
	 * A snippet from an unsupported tool (or plain garbage) must not be
	 * mistaken for one of the three supported patterns.
	 */
	public function test_unrecognized_snippet_returns_null() {
		$this->assertNull( CookieNotice::detect_tracking_snippet( '<script src="https://example.com/some-other-tracker.js"></script>' ) );
	}

	/**
	 * Add-ons can register their own detector and type without adding a
	 * provider-specific pattern to the free plugin.
	 */
	public function test_add_on_can_register_a_tracking_detector() {
		$types_callback = function ( $types ) {
			$types[] = 'example_add_on';
			return $types;
		};
		$detector_callback = function ( $detected, $raw ) {
			return 'example-id' === $raw ? array( 'type' => 'example_add_on', 'id' => 'ExampleId123' ) : $detected;
		};
		add_filter( 'frcn_cookie_notice_tracking_types', $types_callback );
		add_filter( 'frcn_cookie_notice_detect_tracking_snippet', $detector_callback, 10, 2 );

		$this->assertSame(
			array( 'type' => 'example_add_on', 'id' => 'ExampleId123' ),
			CookieNotice::detect_tracking_snippet( 'example-id' )
		);

		remove_filter( 'frcn_cookie_notice_tracking_types', $types_callback );
		remove_filter( 'frcn_cookie_notice_detect_tracking_snippet', $detector_callback, 10 );
	}

	/**
	 * An empty/blank field is "no snippet configured", not an invalid one.
	 */
	public function test_blank_snippet_returns_null() {
		$this->assertNull( CookieNotice::detect_tracking_snippet( '' ) );
		$this->assertNull( CookieNotice::detect_tracking_snippet( "   \n\t" ) );
	}

	/**
	 * Stored integration records are normalized and invalid records are ignored.
	 */
	public function test_tracking_integrations_are_normalized_without_raw_code() {
		$integrations = CookieNotice::get_tracking_integrations(
			array(
				'cookie_notice_tracking_integrations' => array(
					array( 'type' => 'brevo', 'id' => 'first-key' ),
					array( 'type' => 'invalid', 'id' => 'discard-me' ),
					array( 'type' => 'brevo', 'id' => 'latest-key' ),
				),
			)
		);

		$this->assertSame( array( array( 'type' => 'brevo', 'id' => 'latest-key' ) ), $integrations );
	}

	/**
	 * Settings saves preserve integrations from an inactive companion plugin.
	 */
	public function test_tracking_integrations_can_preserve_unknown_add_on_records() {
		$this->assertSame(
			array( array( 'type' => 'inactive_add_on', 'id' => 'saved-id' ) ),
			CookieNotice::get_tracking_integrations(
				array(
					'cookie_notice_tracking_integrations' => array(
						array( 'type' => 'inactive_add_on', 'id' => 'saved-id' ),
					),
				),
				true
			)
		);
	}

	/**
	 * Existing sites using the former single type/id settings retain tracking
	 * until their next settings save migrates them to the integrations list.
	 */
	public function test_legacy_tracking_options_are_read_as_an_integration() {
		$this->assertSame(
			array( array( 'type' => 'brevo', 'id' => 'legacy-key' ) ),
			CookieNotice::get_tracking_integrations(
				array(
					'cookie_notice_tracking_type' => 'brevo',
					'cookie_notice_tracking_id'   => 'legacy-key',
				)
			)
		);
	}

	/**
	 * 'gtm' and 'ga4' are FrontConsent's own native types, registered by default
	 * alongside the additional tools detectable from a pasted snippet.
	 */
	public function test_gtm_and_ga4_are_registered_tracking_types() {
		$types = CookieNotice::get_tracking_types();

		$this->assertContains( 'gtm', $types );
		$this->assertContains( 'ga4', $types );
	}

	/**
	 * A stored 'gtm'/'ga4' record round-trips through get_tracking_integrations()
	 * the same way any other supported type does.
	 */
	public function test_gtm_and_ga4_records_are_normalized_like_other_integrations() {
		$integrations = CookieNotice::get_tracking_integrations(
			array(
				'cookie_notice_tracking_integrations' => array(
					array( 'type' => 'gtm', 'id' => 'GTM-ABC1234' ),
					array( 'type' => 'ga4', 'id' => 'G-ABC1234567' ),
				),
			)
		);

		$this->assertSame(
			array(
				array( 'type' => 'gtm', 'id' => 'GTM-ABC1234' ),
				array( 'type' => 'ga4', 'id' => 'G-ABC1234567' ),
			),
			$integrations
		);
	}

	/**
	 * GTM/GA4 default to the "analytics" category, while every Clientify/Brevo
	 * variant defaults to "marketing" — this is what FrontConsent PRO's
	 * per-category consent gating keys off of.
	 */
	public function test_integration_default_categories() {
		$this->assertSame( 'analytics', CookieNotice::get_integration_default_category( 'gtm' ) );
		$this->assertSame( 'analytics', CookieNotice::get_integration_default_category( 'ga4' ) );
		$this->assertSame( 'marketing', CookieNotice::get_integration_default_category( 'clientify_analytics_plus' ) );
		$this->assertSame( 'marketing', CookieNotice::get_integration_default_category( 'clientify_analytics_classic' ) );
		$this->assertSame( 'marketing', CookieNotice::get_integration_default_category( 'brevo' ) );
		$this->assertSame( 'marketing', CookieNotice::get_integration_default_category( 'openai_chatgpt_ads' ) );
	}

	/**
	 * An unknown integration type must still resolve to a sane default
	 * ("marketing") instead of an empty/invalid category.
	 */
	public function test_unknown_integration_type_defaults_to_marketing_category() {
		$this->assertSame( 'marketing', CookieNotice::get_integration_default_category( 'some-future-tool' ) );
	}

	/**
	 * The category default must be filterable, so FrontConsent PRO (or any
	 * add-on) can override it without forking this method.
	 */
	public function test_integration_default_category_is_filterable() {
		$callback = static function ( $category, $type ) {
			return 'brevo' === $type ? 'custom-category' : $category;
		};

		add_filter( 'frcn_cookie_notice_integration_category', $callback, 10, 2 );

		$this->assertSame( 'custom-category', CookieNotice::get_integration_default_category( 'brevo' ) );
		$this->assertSame( 'analytics', CookieNotice::get_integration_default_category( 'ga4' ) );

		remove_filter( 'frcn_cookie_notice_integration_category', $callback, 10 );
	}
}
