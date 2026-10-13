<?php
/**
 * CAPTCHA verification for public-facing forms.
 *
 * Supports Google reCAPTCHA v3 and Cloudflare Turnstile.
 * Both are invisible (no user interaction required).
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Captcha {

	/**
	 * Check if CAPTCHA is enabled and configured.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$provider = get_option( 'bhec_captcha_provider', 'none' );
		if ( 'none' === $provider ) {
			return false;
		}
		$site_key   = get_option( 'bhec_captcha_site_key', '' );
		$secret_key = get_option( 'bhec_captcha_secret_key', '' );
		return ! empty( $site_key ) && ! empty( $secret_key );
	}

	/**
	 * Get the current provider.
	 *
	 * @return string none|recaptcha_v3|turnstile
	 */
	public static function get_provider() {
		return get_option( 'bhec_captcha_provider', 'none' );
	}

	/**
	 * Render the CAPTCHA script and hidden input for a form.
	 *
	 * Call this inside the form to inject the necessary JS and token field.
	 *
	 * @param string $form_id A unique ID for this form instance.
	 */
	public static function render_field( $form_id = 'bhec_signup' ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$provider = self::get_provider();
		$site_key = (string) get_option( 'bhec_captcha_site_key', '' );
		// A JavaScript-safe name for this form's callback.
		$callback = 'bhecTurnstileCallback_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $form_id );
		$selector = wp_json_encode( '#' . $form_id . ', .bhec-signup-form' );

		// The service's own script, loaded from the service (see readme.txt, External Services).
		if ( 'recaptcha_v3' === $provider ) {
			echo '<input type="hidden" name="bhec_captcha_token" class="bhec-captcha-token" value="">';
			wp_enqueue_script( 'bhec-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the service's script, versioned by the service.
			wp_add_inline_script(
				'bhec-recaptcha',
				'(function () {
					var form = document.querySelector(' . $selector . ');
					if (!form) return;
					form.addEventListener("submit", function (e) {
						e.preventDefault();
						grecaptcha.ready(function () {
							grecaptcha.execute(' . wp_json_encode( $site_key ) . ', { action: "bhec_signup" }).then(function (token) {
								form.querySelector(".bhec-captcha-token").value = token;
								// Submit again, for the form\'s own script.
								var evt = new Event("submit", { bubbles: true, cancelable: true });
								evt._captchaResolved = true;
								form.dispatchEvent(evt);
							});
						});
					});
				})();'
			);
		} elseif ( 'turnstile' === $provider ) {
			printf(
				'<div class="cf-turnstile" data-sitekey="%s" data-callback="%s"></div><input type="hidden" name="bhec_captcha_token" class="bhec-captcha-token" value="">',
				esc_attr( $site_key ),
				esc_attr( $callback )
			);
			wp_enqueue_script( 'bhec-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the service's script, versioned by the service.
			wp_add_inline_script(
				'bhec-turnstile',
				'function ' . $callback . '(token) {
					var form = document.querySelector(' . $selector . ');
					if (form) form.querySelector(".bhec-captcha-token").value = token;
				}',
				'before'
			);
		}
	}

	/**
	 * Verify the CAPTCHA token from a form submission.
	 *
	 * @return bool True if CAPTCHA passes (or is disabled).
	 */
	public static function verify() {
		if ( ! self::is_enabled() ) {
			return true; // CAPTCHA not configured, allow through.
		}

		// Called by the form handlers after their own nonce check.
		$token = isset( $_POST['bhec_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bhec_captcha_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $token ) ) {
			return false;
		}

		$provider   = self::get_provider();
		$secret_key = get_option( 'bhec_captcha_secret_key', '' );

		if ( 'recaptcha_v3' === $provider ) {
			return self::verify_recaptcha( $token, $secret_key );
		} elseif ( 'turnstile' === $provider ) {
			return self::verify_turnstile( $token, $secret_key );
		}

		return false;
	}

	/**
	 * Verify Google reCAPTCHA v3 token.
	 *
	 * @param string $token      CAPTCHA token.
	 * @param string $secret_key Secret key.
	 * @return bool
	 */
	private static function verify_recaptcha( $token, $secret_key ) {
		$response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
			'body' => array(
				'secret'   => $secret_key,
				'response' => $token,
				'remoteip' => BHEC_Tracker::client_ip(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// reCAPTCHA v3 returns a score (0.0 = bot, 1.0 = human). Threshold: 0.5.
		return ! empty( $body['success'] ) && isset( $body['score'] ) && $body['score'] >= 0.5;
	}

	/**
	 * Verify Cloudflare Turnstile token.
	 *
	 * @param string $token      CAPTCHA token.
	 * @param string $secret_key Secret key.
	 * @return bool
	 */
	private static function verify_turnstile( $token, $secret_key ) {
		$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
			'body' => array(
				'secret'   => $secret_key,
				'response' => $token,
				'remoteip' => BHEC_Tracker::client_ip(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return ! empty( $body['success'] );
	}
}
