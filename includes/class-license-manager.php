<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_PB_License_Manager {

	const LICENSE_SERVER  = 'https://gauravtiwari.org/';
	const ITEM_ID        = 1152523;
	const OPTION_KEY     = 'gt_pb_builder_license';
	const LAST_CHECK_KEY = 'gt_pb_builder_license_last_check';
	const UPDATE_TRANSIENT = 'gt_pb_builder_update_info';
	const PAGE_SLUG        = 'gt-pb-builder-license';
	const NOTICE_DISMISSED_KEY = 'gt_pb_license_notice_dismissed';
	const SECURITY_TRANSIENT   = 'gt_pb_security_manifest';

	/**
	 * @var string
	 */
	private $plugin_basename;

	public function __construct( $plugin_file ) {
		// Only the basename is used; the full path was stored and never read.
		$this->plugin_basename = plugin_basename( $plugin_file );
	}

	public function hook() {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ), 99 );
		add_action( 'admin_init', array( $this, 'handle_license_actions' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_ajax_gt_pb_dismiss_license_notice', array( $this, 'ajax_dismiss_notice' ) );
		add_action( 'in_plugin_update_message-' . $this->plugin_basename, array( $this, 'update_message' ) );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_update_transient' ) );

		add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'plugin_action_links' ) );

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );

		if ( ! wp_next_scheduled( 'gt_pb_builder_verify_license' ) ) {
			wp_schedule_event( time(), 'weekly', 'gt_pb_builder_verify_license' );
		}
		add_action( 'gt_pb_builder_verify_license', array( $this, 'verify_remote_license' ) );
	}

	public function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'page-blocks-builder' ),
			);
		}

		return $schedules;
	}

	/**
	 * URL of the licence screen.
	 *
	 * It is registered with add_submenu_page() under the 'gt_page_blocks'
	 * parent, so it resolves under admin.php. One accessor, because the two
	 * callers that link to it had drifted to options-general.php and pointed
	 * at a screen that does not exist.
	 *
	 * @return string
	 */
	public static function license_page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	public function add_submenu_page() {
		add_submenu_page(
			'gt_page_blocks',
			__( 'License', 'page-blocks-builder' ),
			__( 'License', 'page-blocks-builder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_license_page' )
		);
	}

	public function handle_license_actions() {
		if ( ! isset( $_POST['gt_pb_license_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'gt_pb_license_nonce', 'gt_pb_license_nonce' );

		$action = sanitize_text_field( $_POST['gt_pb_license_action'] );

		if ( 'activate' === $action ) {
			$key = sanitize_text_field( trim( $_POST['license_key'] ?? '' ) );
			if ( empty( $key ) ) {
				add_settings_error( 'gt_pb_license', 'empty_key', __( 'Please enter a license key.', 'page-blocks-builder' ), 'error' );
				return;
			}
			$result = $this->activate_license( $key );
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'gt_pb_license', 'activation_error', $result->get_error_message(), 'error' );
			} else {
				add_settings_error( 'gt_pb_license', 'activated', __( 'License activated successfully.', 'page-blocks-builder' ), 'success' );
				$this->force_update_check();
			}
		} elseif ( 'deactivate' === $action ) {
			$result = $this->deactivate_license();
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'gt_pb_license', 'deactivation_error', $result->get_error_message(), 'error' );
			} else {
				add_settings_error( 'gt_pb_license', 'deactivated', __( 'License deactivated successfully.', 'page-blocks-builder' ), 'success' );
			}
		}
	}

	public function activate_license( $key ) {
		$response = $this->api_request( 'activate_license', array(
			'license_key' => $key,
			'item_id'     => self::ITEM_ID,
			'site_url'    => home_url(),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['success'] ) || empty( $response['status'] ) || 'valid' !== $response['status'] ) {
			$message = $response['message'] ?? __( 'License activation failed. Please check your key and try again.', 'page-blocks-builder' );
			return new WP_Error( 'activation_failed', $message );
		}

		$license_data = array(
			'license_key'     => $key,
			'status'          => 'valid',
			'activation_hash' => $response['activation_hash'] ?? '',
			'expiration_date' => $response['expiration_date'] ?? 'lifetime',
			'product_title'   => $response['product_title'] ?? 'GT Page Blocks Builder',
			'activated_at'    => current_time( 'mysql' ),
		);

		update_option( self::OPTION_KEY, $license_data );
		update_option( self::LAST_CHECK_KEY, time() );
		delete_transient( self::UPDATE_TRANSIENT );
		delete_site_transient( 'update_plugins' );

		return $license_data;
	}

	public function deactivate_license() {
		$license = $this->get_license_data();

		if ( empty( $license['license_key'] ) ) {
			return new WP_Error( 'no_license', __( 'No license key found.', 'page-blocks-builder' ) );
		}

		$this->api_request( 'deactivate_license', array(
			'license_key' => $license['license_key'],
			'item_id'     => self::ITEM_ID,
			'site_url'    => home_url(),
		) );

		$default_data = array(
			'license_key'     => '',
			'status'          => 'inactive',
			'activation_hash' => '',
			'expiration_date' => '',
			'product_title'   => '',
			'activated_at'    => '',
		);

		update_option( self::OPTION_KEY, $default_data );
		delete_option( self::LAST_CHECK_KEY );
		delete_transient( self::UPDATE_TRANSIENT );
		delete_site_transient( 'update_plugins' );

		return $default_data;
	}

	public function verify_remote_license() {
		$license = $this->get_license_data();

		if ( empty( $license['license_key'] ) || 'valid' !== ( $license['status'] ?? '' ) ) {
			return;
		}

		$params = array(
			'item_id'  => self::ITEM_ID,
			'site_url' => home_url(),
		);

		if ( ! empty( $license['activation_hash'] ) ) {
			$params['activation_hash'] = $license['activation_hash'];
		} else {
			$params['license_key'] = $license['license_key'];
		}

		$response = $this->api_request( 'check_license', $params );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$remote_status = $response['status'] ?? 'invalid';

		if ( 'valid' !== $remote_status ) {
			$license['status'] = $remote_status;
			update_option( self::OPTION_KEY, $license );
			delete_transient( self::UPDATE_TRANSIENT );
			delete_site_transient( 'update_plugins' );
		}

		update_option( self::LAST_CHECK_KEY, time() );
	}

	/**
	 * Offer an update only to a licensed site.
	 *
	 * This early return is the licensing model, not a bug. GT Page Blocks
	 * Builder is free to use: nothing in the plugin is gated on a licence, and
	 * is_valid() has no caller outside the licence screen, so an unlicensed
	 * install runs every feature. What a licence buys is hosted automatic
	 * updates and support.
	 *
	 * Do not remove the status check to "fix" updates for unlicensed sites.
	 * Security releases reach them through gt_pb_security_manifest(), which
	 * deliberately bypasses this gate.
	 *
	 * @param object $transient_data update_plugins transient.
	 * @return object
	 */
	public function check_for_update( $transient_data ) {
		if ( ! is_object( $transient_data ) ) {
			$transient_data = new stdClass();
		}

		if ( ! empty( $transient_data->response[ $this->plugin_basename ] ) ) {
			return $transient_data;
		}

		$license = $this->get_license_data();
		if ( empty( $license['license_key'] ) || 'valid' !== ( $license['status'] ?? '' ) ) {
			// Unlicensed sites still get security releases. Under this model
			// they would otherwise never receive one, and both remote-execution
			// paths found in the 2026 audit would have reached only paying
			// customers. Core's auto-update machinery reads this same
			// transient, so there is no WordPress-native fallback to defer to.
			return $this->maybe_security_update( $transient_data );
		}

		$update_info = get_transient( self::UPDATE_TRANSIENT );

		if ( false === $update_info ) {
			$params = array(
				'item_id'  => self::ITEM_ID,
				'site_url' => home_url(),
			);

			if ( ! empty( $license['activation_hash'] ) ) {
				$params['activation_hash'] = $license['activation_hash'];
			} else {
				$params['license_key'] = $license['license_key'];
			}

			$update_info = $this->api_request( 'get_license_version', $params );

			if ( ! is_wp_error( $update_info ) ) {
				set_transient( self::UPDATE_TRANSIENT, $update_info, 12 * HOUR_IN_SECONDS );
			}
		}

		if ( is_wp_error( $update_info ) || empty( $update_info['new_version'] ) ) {
			return $transient_data;
		}

		$current_version = defined( 'GT_PB_BUILDER_VERSION' ) ? GT_PB_BUILDER_VERSION : '0.0.0';

		if ( version_compare( $update_info['new_version'], $current_version, '>' ) ) {
			$plugin_data = (object) array(
				'id'            => $this->plugin_basename,
				'slug'          => 'page-blocks-builder',
				'plugin'        => $this->plugin_basename,
				'new_version'   => $update_info['new_version'],
				'url'           => $this->trusted_url( $update_info['url'] ?? '' ) ?: self::LICENSE_SERVER . 'product/gt-page-blocks-builder/',
				'package'       => $this->trusted_url( $update_info['package'] ?? '' ),
				'icons'         => $update_info['icons'] ?? array(),
				'banners'       => $update_info['banners'] ?? array(),
				'tested'        => $update_info['tested'] ?? '',
				'requires_php'  => $update_info['requires_php'] ?? ( defined( 'GT_PB_MIN_PHP' ) ? GT_PB_MIN_PHP : '8.1' ),
				'compatibility' => new stdClass(),
			);

			$transient_data->response[ $this->plugin_basename ] = $plugin_data;
		}

		return $transient_data;
	}

	/**
	 * Offer a security release to a site with no valid licence.
	 *
	 * Reads a small static JSON manifest and injects an update only when the
	 * installed version is below its min_safe_version. Gated strictly on that,
	 * so this path can never be used to push a feature release around the
	 * licence, and the package URL goes through the same host allowlist as the
	 * licensed one.
	 *
	 * Opt out with GT_PB_DISABLE_SECURITY_CHANNEL. The request sends nothing
	 * but the plugin version, and that disclosure belongs in readme.txt.
	 *
	 * @since 3.0.0
	 * @param object $transient_data update_plugins transient.
	 * @return object
	 */
	private function maybe_security_update( $transient_data ) {
		if ( defined( 'GT_PB_DISABLE_SECURITY_CHANNEL' ) && GT_PB_DISABLE_SECURITY_CHANNEL ) {
			return $transient_data;
		}

		$manifest = get_transient( self::SECURITY_TRANSIENT );

		if ( false === $manifest ) {
			$response = wp_safe_remote_get(
				self::LICENSE_SERVER . 'security/page-blocks-builder.json',
				array( 'timeout' => 8 )
			);

			$manifest = is_wp_error( $response )
				? array()
				: (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );

			// Cached either way, so an outage does not mean a request per page
			// load on every unlicensed site.
			set_transient( self::SECURITY_TRANSIENT, $manifest, 12 * HOUR_IN_SECONDS );
		}

		$min_safe = (string) ( $manifest['min_safe_version'] ?? '' );
		$new      = (string) ( $manifest['new_version'] ?? '' );
		$current  = defined( 'GT_PB_BUILDER_VERSION' ) ? GT_PB_BUILDER_VERSION : '0.0.0';

		if ( '' === $min_safe || '' === $new ) {
			return $transient_data;
		}

		// Strictly below the minimum safe version, and the offer never exceeds
		// what the manifest names as the fix.
		if ( ! version_compare( $current, $min_safe, '<' ) || ! version_compare( $new, $current, '>' ) ) {
			return $transient_data;
		}

		$package = $this->trusted_url( $manifest['package'] ?? '' );

		if ( '' === $package ) {
			return $transient_data;
		}

		$transient_data->response[ $this->plugin_basename ] = (object) array(
			'id'            => $this->plugin_basename,
			'slug'          => 'page-blocks-builder',
			'plugin'        => $this->plugin_basename,
			'new_version'   => $new,
			'url'           => $this->trusted_url( $manifest['advisory_url'] ?? '' ) ?: self::LICENSE_SERVER,
			'package'       => $package,
			'tested'        => (string) ( $manifest['tested'] ?? '' ),
			'requires_php'  => (string) ( $manifest['requires_php'] ?? ( defined( 'GT_PB_MIN_PHP' ) ? GT_PB_MIN_PHP : '8.1' ) ),
			'compatibility' => new stdClass(),
		);

		return $transient_data;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'page-blocks-builder' !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$update_info = get_transient( self::UPDATE_TRANSIENT );
		if ( empty( $update_info ) || is_wp_error( $update_info ) ) {
			return $result;
		}

		return (object) array(
			'name'          => $update_info['name'] ?? 'GT Page Blocks Builder',
			'slug'          => 'page-blocks-builder',
			'version'       => $update_info['new_version'] ?? '',
			'author'        => '<a href="https://gauravtiwari.org">Gaurav Tiwari</a>',
			'homepage'      => $this->trusted_url( $update_info['homepage'] ?? '' ) ?: self::LICENSE_SERVER . 'product/gt-page-blocks-builder/',
			'download_link' => $this->trusted_url( $update_info['package'] ?? '' ),
			'trunk'         => $update_info['trunk'] ?? '',
			'last_updated'  => $update_info['last_updated'] ?? '',
			'sections'      => array_map( 'wp_kses_post', (array) ( $update_info['sections'] ?? array() ) ),
			'banners'       => $update_info['banners'] ?? array(),
			'icons'         => $update_info['icons'] ?? array(),
			'requires'      => $update_info['requires'] ?? '6.0',
			'requires_php'  => $update_info['requires_php'] ?? ( defined( 'GT_PB_MIN_PHP' ) ? GT_PB_MIN_PHP : '8.1' ),
			'tested'        => $update_info['tested'] ?? '',
		);
	}

	public function clear_update_transient() {
		delete_transient( self::UPDATE_TRANSIENT );
	}

	private function force_update_check() {
		delete_transient( self::UPDATE_TRANSIENT );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
	}

	public function plugin_action_links( $links ) {
		$license_link = sprintf(
			'<a href="%s">%s</a>',
			self::license_page_url(),
			__( 'License', 'page-blocks-builder' )
		);
		array_unshift( $links, $license_link );
		return $links;
	}

	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( ! empty( $_GET['page'] ) && 'gt-pb-builder-license' === $_GET['page'] ) {
			return;
		}

		// Only the plugin's own screens. This used to include 'post' and
		// 'post-new', so it appeared on every post someone edited, and its
		// page-slug test named 'gt-page-blocks-builder', which the plugin has
		// never registered - so it never fired where it was actually useful.
		$page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$is_relevant = in_array( $page, array( 'gt_page_blocks', 'gt_pb_edit', self::PAGE_SLUG, 'gt_pb_settings' ), true )
			|| 'plugins' === $screen->base;

		if ( ! $is_relevant ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_KEY, true ) ) {
			return;
		}

		$license = $this->get_license_data();
		$status  = $license['status'] ?? 'inactive';

		if ( 'valid' === $status ) {
			return;
		}

		$license_url = self::license_page_url();

		if ( 'expired' === $status ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Your GT Page Blocks Builder license has expired. Renew to continue receiving updates and support.', 'page-blocks-builder' ),
				esc_url( $license_url ),
				esc_html__( 'Manage License', 'page-blocks-builder' )
			);
		} else {
			printf(
				'<div class="notice notice-info is-dismissible" data-gt-pb-notice="license"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'GT Page Blocks Builder is free to use, and every feature is available without a license. A license adds automatic updates and support.', 'page-blocks-builder' ),
				esc_url( $license_url ),
				esc_html__( 'Activate a license', 'page-blocks-builder' )
			);
			$this->print_notice_dismiss_script();
		}
	}

	/**
	 * Persist notice dismissal.
	 *
	 * WordPress' is-dismissible only hides the notice for that page view, so
	 * the nag returned on every load forever.
	 */
	private function print_notice_dismiss_script(): void {
		$nonce = wp_create_nonce( 'gt_pb_dismiss_license_notice' );
		printf(
			'<script>document.addEventListener("click",function(e){var b=e.target.closest(\'[data-gt-pb-notice="license"] .notice-dismiss\');if(!b)return;var d=new FormData();d.append("action","gt_pb_dismiss_license_notice");d.append("nonce","%s");fetch(ajaxurl,{method:"POST",body:d,credentials:"same-origin"});});</script>',
			esc_js( $nonce )
		);
	}

	/**
	 * AJAX: remember that this user dismissed the licence notice.
	 */
	public function ajax_dismiss_notice(): void {
		if ( ! is_user_logged_in() || ! check_ajax_referer( 'gt_pb_dismiss_license_notice', 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_KEY, 1 );
		wp_send_json_success();
	}

	/**
	 * Explain on the Plugins screen why an unlicensed site sees no update.
	 *
	 * Without this, an unlicensed install is simply never offered one, which
	 * is indistinguishable from a broken update pipe.
	 */
	public function update_message(): void {
		if ( $this->is_valid() ) {
			return;
		}
		printf(
			' <strong>%s</strong> <a href="%s">%s</a>',
			esc_html__( 'Automatic updates need a license. The plugin itself stays free.', 'page-blocks-builder' ),
			esc_url( self::license_page_url() ),
			esc_html__( 'Activate a license', 'page-blocks-builder' )
		);
	}

	public function render_license_page() {
		$license = $this->get_license_data();
		$status  = $license['status'] ?? 'inactive';
		$key     = $license['license_key'] ?? '';
		$expires = $license['expiration_date'] ?? '';

		settings_errors( 'gt_pb_license' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GT Page Blocks Builder License', 'page-blocks-builder' ); ?></h1>

			<div class="card" style="max-width: 600px; margin-top: 20px;">
				<h2 style="margin-top: 0;"><?php esc_html_e( 'License Status', 'page-blocks-builder' ); ?></h2>

				<?php if ( 'valid' === $status ) : ?>
					<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 12px 16px; border-radius: 4px; margin-bottom: 16px;">
						<strong style="color: #155724;">&#10003; <?php esc_html_e( 'License Active', 'page-blocks-builder' ); ?></strong>
						<?php if ( $expires && 'lifetime' !== $expires ) : ?>
							<?php /* translators: %s: expiry date */ ?>
							<br><small><?php printf( esc_html__( 'Expires: %s', 'page-blocks-builder' ), esc_html( $expires ) ); ?></small>
						<?php elseif ( 'lifetime' === $expires ) : ?>
							<br><small><?php esc_html_e( 'Lifetime license', 'page-blocks-builder' ); ?></small>
						<?php endif; ?>
					</div>

					<form method="post">
						<?php wp_nonce_field( 'gt_pb_license_nonce', 'gt_pb_license_nonce' ); ?>
						<input type="hidden" name="gt_pb_license_action" value="deactivate">
						<p>
							<code style="font-size: 14px; padding: 4px 8px;"><?php echo esc_html( $this->mask_key( $key ) ); ?></code>
						</p>
						<p>
							<input type="submit" class="button" value="<?php esc_attr_e( 'Deactivate License', 'page-blocks-builder' ); ?>">
						</p>
					</form>

				<?php elseif ( 'expired' === $status ) : ?>
					<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px 16px; border-radius: 4px; margin-bottom: 16px;">
						<strong style="color: #856404;">&#9888; <?php esc_html_e( 'License Expired', 'page-blocks-builder' ); ?></strong>
						<?php if ( $expires ) : ?>
							<?php /* translators: %s: expiry date */ ?>
							<br><small><?php printf( esc_html__( 'Expired: %s', 'page-blocks-builder' ), esc_html( $expires ) ); ?></small>
						<?php endif; ?>
					</div>

					<p><?php esc_html_e( 'Your license has expired. Renew it to continue receiving updates and support.', 'page-blocks-builder' ); ?></p>
					<p>
						<a href="https://gauravtiwari.org/product/page-blocks-builder/" class="button button-primary" target="_blank">
							<?php esc_html_e( 'Renew License', 'page-blocks-builder' ); ?>
						</a>
					</p>

					<hr>
					<form method="post">
						<?php wp_nonce_field( 'gt_pb_license_nonce', 'gt_pb_license_nonce' ); ?>
						<input type="hidden" name="gt_pb_license_action" value="activate">
						<p>
							<label for="license_key"><strong><?php esc_html_e( 'Or enter a new license key:', 'page-blocks-builder' ); ?></strong></label><br>
							<input type="text" id="license_key" name="license_key" class="regular-text" placeholder="<?php esc_attr_e( 'Enter license key...', 'page-blocks-builder' ); ?>" style="margin-top: 4px;">
						</p>
						<p>
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Activate License', 'page-blocks-builder' ); ?>">
						</p>
					</form>

				<?php else : ?>
					<p><?php esc_html_e( 'Enter your license key to enable automatic updates and support.', 'page-blocks-builder' ); ?></p>

					<form method="post">
						<?php wp_nonce_field( 'gt_pb_license_nonce', 'gt_pb_license_nonce' ); ?>
						<input type="hidden" name="gt_pb_license_action" value="activate">
						<p>
							<label for="license_key"><strong><?php esc_html_e( 'License Key', 'page-blocks-builder' ); ?></strong></label><br>
							<input type="text" id="license_key" name="license_key" class="regular-text" placeholder="<?php esc_attr_e( 'Enter license key...', 'page-blocks-builder' ); ?>" style="margin-top: 4px;">
						</p>
						<p>
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Activate License', 'page-blocks-builder' ); ?>">
						</p>
					</form>

					<hr>
					<p>
						<small>
							<?php printf(
								/* translators: 1: opening link tag, 2: closing link tag */
								esc_html__( 'Don\'t have a license? %1$sGet one here%2$s.', 'page-blocks-builder' ),
								'<a href="https://gauravtiwari.org/product/page-blocks-builder/" target="_blank">',
								'</a>'
							); ?>
						</small>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function get_license_data() {
		$defaults = array(
			'license_key'     => '',
			'status'          => 'inactive',
			'activation_hash' => '',
			'expiration_date' => '',
			'product_title'   => '',
			'activated_at'    => '',
		);

		$data = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $data ) ) {
			return $defaults;
		}

		return wp_parse_args( $data, $defaults );
	}

	public function is_valid() {
		$license = $this->get_license_data();
		return 'valid' === ( $license['status'] ?? '' );
	}

	/**
	 * Accept a URL from the licence server only if it points at the licence
	 * server's own host.
	 *
	 * The update payload's `package` becomes the archive WordPress downloads
	 * and installs. Taking that value on trust lets anything able to answer as
	 * the licence server install arbitrary code, so an off-host URL is dropped
	 * rather than followed.
	 *
	 * @param mixed $url Candidate URL from the API response.
	 * @return string Empty string when the URL is missing or off-host.
	 */
	private function trusted_url( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		// https only. Pinning the host while accepting http:// leaves the
		// download open to exactly the machine-in-the-middle the sslverify fix
		// closed, on the one URL WordPress installs a plugin from.
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host || strtolower( $host ) !== strtolower( (string) wp_parse_url( self::LICENSE_SERVER, PHP_URL_HOST ) ) ) {
			return '';
		}

		return $url;
	}

	private function api_request( $action, $params = array() ) {
		$url = add_query_arg( 'fluent-cart', $action, self::LICENSE_SERVER );

		$params['current_version'] = defined( 'GT_PB_BUILDER_VERSION' ) ? GT_PB_BUILDER_VERSION : '1.0.0';

		// Certificate verification stays on. A host with a genuinely broken CA
		// bundle can opt out with GT_PB_LICENSE_INSECURE in wp-config.php, which
		// is a deliberate, per-site decision rather than the default for everyone:
		// this channel hands WordPress the URL it installs a plugin from, so an
		// unverified connection here is a remote-code-execution path.
		$insecure = defined( 'GT_PB_LICENSE_INSECURE' ) && GT_PB_LICENSE_INSECURE;

		$response = wp_safe_remote_post( $url, array(
			'timeout'   => 15,
			'sslverify' => ! $insecure,
			'body'      => $params,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_error',
				__( 'Could not connect to the license server. Please try again later.', 'page-blocks-builder' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || empty( $body ) ) {
			$message = $body['message'] ?? __( 'License server returned an error.', 'page-blocks-builder' );
			return new WP_Error( 'api_error', $message );
		}

		return $body;
	}

	private function mask_key( $key ) {
		if ( strlen( $key ) <= 8 ) {
			return $key;
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
	}
}
