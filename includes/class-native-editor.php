<?php
/** WordPress owns the visual editor, block state, and persistence. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GT_PB_Native_Editor {
	public static function init() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'canvas_assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'canvas_body_class' ) );
	}

	public static function is_visual_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; the calling builder verifies its nonce and post capability.
		return isset( $_GET['pb_mode'] ) && 'visual' === $_GET['pb_mode'];
	}

	private static function is_canvas() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor chrome, with post access checked below.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This flag only changes editor chrome, never saved data.
		return isset( $_GET['gt_pb_canvas'] ) && '1' === $_GET['gt_pb_canvas'] && $post_id && current_user_can( 'edit_post', $post_id );
	}

	public static function canvas_body_class( $classes ) {
		return self::is_canvas() ? $classes . ' gt-pb-native-canvas' : $classes;
	}

	public static function canvas_assets() {
		if ( self::is_canvas() ) {
			wp_enqueue_style( 'gt-pb-native-canvas', GT_PB_BUILDER_URL . 'assets/css/native-editor-canvas.css', array(), filemtime( GT_PB_BUILDER_DIR . 'assets/css/native-editor-canvas.css' ) );
			wp_enqueue_script( 'gt-pb-native-editor-api', GT_PB_BUILDER_URL . 'assets/js/native-editor.js', array(), filemtime( GT_PB_BUILDER_DIR . 'assets/js/native-editor.js' ), true );
			wp_enqueue_script( 'gt-pb-native-bridge', GT_PB_BUILDER_URL . 'assets/js/native-editor-bridge.js', array( 'gt-pb-native-editor-api', 'wp-editor', 'wp-data', 'wp-dom-ready' ), filemtime( GT_PB_BUILDER_DIR . 'assets/js/native-editor-bridge.js' ), true );
			wp_localize_script(
				'gt-pb-native-bridge',
				'gtPbNativeBridge',
				array(
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The read-only canvas check above verified access to this post.
					'postId'    => absint( $_GET['post'] ),
					'parentUrl' => home_url( '/' ),
				)
			);
		}
	}

	public static function icons() {
		$icons = array();
		foreach ( array( 'layout', 'code', 'external-link', 'eye', 'layout-sidebar', 'settings', 'x' ) as $name ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled local SVG; never a remote URL.
			$icons[ $name ] = str_replace( '<svg', '<svg aria-hidden="true" focusable="false"', (string) file_get_contents( GT_PB_BUILDER_DIR . 'assets/icons/tabler/' . $name . '.svg' ) );
		}
		return $icons;
	}

	public static function enqueue( $post_id, $nonce ) {
		wp_enqueue_style( 'gt-pb-native-editor', GT_PB_BUILDER_URL . 'assets/css/native-editor.css', array(), filemtime( GT_PB_BUILDER_DIR . 'assets/css/native-editor.css' ) );
		wp_enqueue_script( 'gt-pb-native-editor', GT_PB_BUILDER_URL . 'assets/js/native-editor.js', array(), filemtime( GT_PB_BUILDER_DIR . 'assets/js/native-editor.js' ), true );
		wp_localize_script(
			'gt-pb-native-editor',
			'gtPbNativeEditor',
			array(
				'postId'    => $post_id,
				'title'     => get_the_title( $post_id ),
				'editorUrl' => add_query_arg( 'gt_pb_canvas', '1', get_edit_post_link( $post_id, 'raw' ) ),
				'editUrl'   => get_edit_post_link( $post_id, 'raw' ),
				'codeUrl'   => add_query_arg( 'pb_mode', 'code', gt_page_blocks_builder_url( $post_id, $nonce ) ),
				'viewUrl'   => get_permalink( $post_id ),
				'icons'     => self::icons(),
			)
		);
	}
}
