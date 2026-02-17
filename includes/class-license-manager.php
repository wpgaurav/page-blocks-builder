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

	/**
	 * @var string
	 */
	private $plugin_file;

	/**
	 * @var string
	 */
	private $plugin_basename;

	public function __construct( $plugin_file ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
	}

	public function hook() {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ), 99 );
		add_action( 'admin_init', array( $this, 'handle_license_actions' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

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

	public function add_submenu_page() {
		add_submenu_page(
			'options-general.php',
			__( 'Page Blocks License', 'page-blocks-builder' ),
			__( 'Page Blocks License', 'page-blocks-builder' ),
			'manage_options',
			'gt-pb-builder-license',
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

	public function check_for_update( $transient_data ) {
		if ( ! is_object( $transient_data ) ) {
			$transient_data = new stdClass();
		}

		if ( ! empty( $transient_data->response[ $this->plugin_basename ] ) ) {
			return $transient_data;
		}

		$license = $this->get_license_data();
		if ( empty( $license['license_key'] ) || 'valid' !== ( $license['status'] ?? '' ) ) {
			return $transient_data;
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
				'url'           => $update_info['url'] ?? 'https://gauravtiwari.org/plugins/page-blocks-builder',
				'package'       => $update_info['package'] ?? '',
				'icons'         => $update_info['icons'] ?? array(),
				'banners'       => $update_info['banners'] ?? array(),
				'tested'        => $update_info['tested'] ?? '',
				'requires_php'  => $update_info['requires_php'] ?? '7.4',
				'compatibility' => new stdClass(),
			);

			$transient_data->response[ $this->plugin_basename ] = $plugin_data;
		}

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
			'homepage'      => $update_info['homepage'] ?? 'https://gauravtiwari.org/plugins/page-blocks-builder',
			'download_link' => $update_info['package'] ?? '',
			'trunk'         => $update_info['trunk'] ?? '',
			'last_updated'  => $update_info['last_updated'] ?? '',
			'sections'      => $update_info['sections'] ?? array(),
			'banners'       => $update_info['banners'] ?? array(),
			'icons'         => $update_info['icons'] ?? array(),
			'requires'      => $update_info['requires'] ?? '6.0',
			'requires_php'  => $update_info['requires_php'] ?? '7.4',
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
			admin_url( 'options-general.php?page=gt-pb-builder-license' ),
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

		$is_relevant = in_array( $screen->base, array( 'post', 'post-new' ), true )
			|| ( ! empty( $_GET['page'] ) && 'gt-page-blocks-builder' === $_GET['page'] );

		if ( ! $is_relevant ) {
			return;
		}

		$license = $this->get_license_data();
		$status  = $license['status'] ?? 'inactive';

		if ( 'valid' === $status ) {
			return;
		}

		$license_url = admin_url( 'options-general.php?page=gt-pb-builder-license' );

		if ( 'expired' === $status ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Your GT Page Blocks Builder license has expired. Renew to continue receiving updates and support.', 'page-blocks-builder' ),
				esc_url( $license_url ),
				esc_html__( 'Manage License', 'page-blocks-builder' )
			);
		} else {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Activate your GT Page Blocks Builder license to receive automatic updates and support.', 'page-blocks-builder' ),
				esc_url( $license_url ),
				esc_html__( 'Activate License', 'page-blocks-builder' )
			);
		}
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
								esc_html__( 'Don\'t have a license? %sGet one here%s.', 'page-blocks-builder' ),
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

	private function api_request( $action, $params = array() ) {
		$url = add_query_arg( 'fluent-cart', $action, self::LICENSE_SERVER );

		$params['current_version'] = defined( 'GT_PB_BUILDER_VERSION' ) ? GT_PB_BUILDER_VERSION : '1.0.0';

		$response = wp_remote_post( $url, array(
			'timeout'   => 15,
			'sslverify' => false,
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
