<?php
/**
 * Newsletter signup form shortcode and AJAX handler.
 *
 * @package BackHAUSEmailCampaigns
 */

defined( 'ABSPATH' ) || exit;

class BHEC_Signup_Form {

	/**
	 * Initialize shortcode and AJAX hooks.
	 */
	public static function init() {
		add_shortcode( 'bhec_signup', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_ajax_bhec_public_signup', array( __CLASS__, 'handle_signup' ) );
		add_action( 'wp_ajax_nopriv_bhec_public_signup', array( __CLASS__, 'handle_signup' ) );
	}

	/**
	 * Render the signup form shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'title'       => __( 'Subscribe to our newsletter', 'backhaus-email-campaigns' ),
			'button'      => __( 'Subscribe', 'backhaus-email-campaigns' ),
			'placeholder' => __( 'Your email address', 'backhaus-email-campaigns' ),
			'success'     => __( 'Thank you for subscribing!', 'backhaus-email-campaigns' ),
		), $atts );

		self::enqueue_assets();

		ob_start();
		?>
		<div class="bhec-signup-form-wrap">
			<form class="bhec-signup-form" data-success="<?php echo esc_attr( $atts['success'] ); ?>">
				<?php if ( $atts['title'] ) : ?>
					<h3><?php echo esc_html( $atts['title'] ); ?></h3>
				<?php endif; ?>
				<div class="bhec-signup-fields">
					<input type="email" name="email" required
						placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
						class="bhec-signup-email">
					<button type="submit" class="bhec-signup-btn">
						<?php echo esc_html( $atts['button'] ); ?>
					</button>
				</div>
				<div class="bhec-signup-message" style="display:none;"></div>
				<?php if ( class_exists( 'BHEC_Captcha' ) ) { BHEC_Captcha::render_field( 'bhec_signup_' . wp_unique_id() ); } ?>
			<?php wp_nonce_field( 'bhec_public_signup', 'bhec_signup_nonce' ); ?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The form's style and script, added once per page however many forms
	 * it has.
	 */
	private static function enqueue_assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		// "div." outranks a block theme's content width, which prints after this.
		$css = 'div.bhec-signup-form-wrap { max-width: 500px; }
.bhec-signup-fields { display: flex; gap: 8px; }
.bhec-signup-email { flex: 1; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
.bhec-signup-btn { padding: 10px 20px; background: #0073aa; color: #fff; border: none; border-radius: 4px; font-size: 14px; cursor: pointer; }
.bhec-signup-btn:hover { background: #005a87; }
.bhec-signup-message { margin-top: 8px; padding: 8px 12px; border-radius: 4px; font-size: 14px; }
.bhec-signup-message.success { background: #d4edda; color: #155724; }
.bhec-signup-message.error { background: #f8d7da; color: #721c24; }';

		$js = '(function () {
	var ajaxUrl = ' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ';
	var failed = ' . wp_json_encode( __( 'An error occurred.', 'backhaus-email-campaigns' ) ) . ';
	document.querySelectorAll(".bhec-signup-form").forEach(function (form) {
		form.addEventListener("submit", function (e) {
			e.preventDefault();
			var email = form.querySelector(".bhec-signup-email").value;
			var nonce = form.querySelector("[name=\"bhec_signup_nonce\"]").value;
			var msg = form.querySelector(".bhec-signup-message");
			var btn = form.querySelector(".bhec-signup-btn");
			var label = btn.textContent;
			btn.disabled = true;
			btn.textContent = "...";

			var xhr = new XMLHttpRequest();
			xhr.open("POST", ajaxUrl);
			xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
			xhr.onload = function () {
				btn.disabled = false;
				btn.textContent = label;
				msg.style.display = "block";
				try {
					var res = JSON.parse(xhr.responseText);
					if (res.success) {
						msg.className = "bhec-signup-message success";
						msg.textContent = form.dataset.success;
						form.querySelector(".bhec-signup-email").value = "";
					} else {
						msg.className = "bhec-signup-message error";
						msg.textContent = res.data || failed;
					}
				} catch (err) {
					msg.className = "bhec-signup-message error";
					msg.textContent = failed;
				}
			};
			var captchaToken = form.querySelector(".bhec-captcha-token");
			var params = "action=bhec_public_signup&email=" + encodeURIComponent(email) + "&bhec_signup_nonce=" + encodeURIComponent(nonce);
			if (captchaToken && captchaToken.value) {
				params += "&bhec_captcha_token=" + encodeURIComponent(captchaToken.value);
			}
			xhr.send(params);
		});
	});
})();';

		wp_register_style( 'bhec-signup', false, array(), BHEC_VERSION );
		wp_enqueue_style( 'bhec-signup' );
		wp_add_inline_style( 'bhec-signup', $css );

		wp_register_script( 'bhec-signup', false, array(), BHEC_VERSION, true );
		wp_enqueue_script( 'bhec-signup' );
		wp_add_inline_script( 'bhec-signup', $js );
	}

	/**
	 * Handle the AJAX signup request.
	 */
	public static function handle_signup() {
		if ( ! isset( $_POST['bhec_signup_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bhec_signup_nonce'] ) ), 'bhec_public_signup' ) ) {
			wp_send_json_error( __( 'Invalid request.', 'backhaus-email-campaigns' ) );
		}

		// SECURITY: Rate limit signups per IP to prevent abuse.
		$ip         = BHEC_Tracker::client_ip();
		$rate_key   = 'bhec_signup_rate_' . md5( $ip );
		$rate_count = (int) get_transient( $rate_key );
		if ( $rate_count >= 5 ) {
			// Max 5 signup attempts per IP per hour.
			wp_send_json_success(); // Silently succeed to not reveal rate limiting.
		}
		set_transient( $rate_key, $rate_count + 1, HOUR_IN_SECONDS );

		// CAPTCHA verification (Pro only).
		if ( class_exists( 'BHEC_Captcha' ) && ! BHEC_Captcha::verify() ) {
			wp_send_json_error( __( 'Verification failed. Please try again.', 'backhaus-email-campaigns' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'Please enter a valid email address.', 'backhaus-email-campaigns' ) );
		}

		// Check if already subscribed or pending.
		$existing = BHEC_Subscription::get_status( $email );
		if ( $existing ) {
			// Already has a record — don't re-subscribe or reveal status.
			wp_send_json_success();
		}

		// For public signups, ALWAYS use double opt-in regardless of the global setting.
		// This prevents abuse where an attacker subscribes arbitrary third-party emails.
		$customer_id = 0;
		$user        = get_user_by( 'email', $email );
		if ( $user ) {
			$customer_id = $user->ID;
		}

		// Force pending status + confirmation email for public signups.
		global $wpdb;
		$table = $wpdb->prefix . 'bhec_subscriptions';
		$token = wp_generate_password( 64, false );

		$wpdb->insert(
			$table,
			array(
				'email'       => $email,
				'customer_id' => $customer_id,
				'status'      => 'pending',
				'source'      => 'signup_form',
				'token'        => $token,
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		// Always send confirmation email for public signups (Pro only).
		if ( class_exists( 'BHEC_Double_Optin' ) ) {
			BHEC_Double_Optin::send_confirmation( $email );
		}

		wp_send_json_success();
	}
}
