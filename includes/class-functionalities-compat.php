<?php
/** Functionalities output for the standalone builder's preview document. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_functionalities_compat {
	public static function init() {
		add_filter( 'gt_page_blocks_builder_preview_injection', array( __CLASS__, 'preview_injection' ), 5, 2 );
		add_filter( 'gt_page_blocks_builder_preview_requires_server', array( __CLASS__, 'requires_server' ), 10, 2 );
		add_filter( 'gt_page_blocks_builder_preview_html', array( __CLASS__, 'filter_html' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_shell' ), 11 );
	}

	/** Include opted-in editor helpers such as Prism in the frontend editor. */
	public static function enqueue_shell() {
		$builder = $GLOBALS['gt_page_blocks_builder'] ?? null;
		if ( ! $builder || ! $builder->is_builder_request() ) {
			return;
		}
		$post_id = $builder->get_builder_post_id();
		$nonce = isset( $_GET['pb_nonce'] ) && is_string( $_GET['pb_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['pb_nonce'] ) ) : '';
		if ( self::enabled( $post_id ) && $builder->can_access_builder( $post_id, $nonce ) ) {
			self::provider_hook( 'admin_enqueue_scripts', array( 'gt_page_blocks_builder' ) );
		}
	}

	public static function enabled( $post_id ) {
		/**
		 * Bridge enabled Functionalities features into the builder preview.
		 * Does not enable modules or change their options or filters.
		 *
		 * @param bool $enabled Whether Functionalities is installed and active.
		 * @param int  $post_id Post being edited.
		 */
		return defined( 'FUNCTIONALITIES_DIR' ) && (bool) apply_filters(
			'gt_page_blocks_builder_functionalities_compatibility', true, (int) $post_id
		);
	}

	public static function requires_server( $required, $post_id ) {
		return $required || self::enabled( $post_id );
	}

	/** Give conditional tags and snippets the edited post, not the homepage. */
	public static function with_post( $post_id, $callback ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return call_user_func( $callback );
		}
		$keys = array( 'wp_query', 'wp_the_query', 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
		$saved = array();
		foreach ( $keys as $key ) {
			$saved[ $key ] = array( array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null );
		}
		try {
			$query = new WP_Query( array(
				'post_type' => $post->post_type, 'post_status' => $post->post_status,
				'page' === $post->post_type ? 'page_id' : 'p' => $post->ID,
				'no_found_rows' => true,
			) );
			$GLOBALS['wp_query'] = $query;
			$GLOBALS['wp_the_query'] = $query;
			$GLOBALS['post'] = $post;
			$query->in_the_loop = true;
			setup_postdata( $post );
			return call_user_func( $callback );
		} finally {
			foreach ( $saved as $key => $value ) {
				if ( $value[0] ) {
					$GLOBALS[ $key ] = $value[1];
				} else {
					unset( $GLOBALS[ $key ] );
				}
			}
		}
	}

	/** Select registered callbacks by their source file, including closures. */
	private static function owns_callback( $callback ) {
		try {
			if ( is_array( $callback ) ) {
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_string( $callback ) && strpos( $callback, '::' ) !== false ) {
				$reflection = new ReflectionMethod( ...explode( '::', $callback, 2 ) );
			} elseif ( is_string( $callback ) || $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
			} else {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			}
			$file = $reflection->getFileName();
			$root = realpath( (string) constant( 'FUNCTIONALITIES_DIR' ) );
			return $file && $root && str_starts_with( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $root ) ) );
		} catch ( ReflectionException $error ) {
			return false;
		}
	}

	/** Run only this provider's existing hooks; leave the site's hooks intact. */
	private static function provider_hook( $name, $args = array(), $filter = false, $editor_styles = false ) {
		global $wp_filter;
		$original = $wp_filter[ $name ] ?? null;
		$action_count = $GLOBALS['wp_actions'][ $name ] ?? null;
		$filter_stack = $GLOBALS['wp_current_filter'] ?? array();
		$selected = new WP_Hook();
		foreach ( $original ? $original->callbacks : array() as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( ! self::owns_callback( $callback ) ) {
					continue;
				}
				// These modules provide a complete, file-independent canvas CSS
				// path. Avoid printing a second copy through their frontend hook.
				if ( $editor_styles && is_array( $callback ) && in_array( $callback[1], array( 'print_fonts_css', 'print_footer_link' ), true ) ) {
					continue;
				}
				$selected->add_filter( $name, $callback, $priority, $entry['accepted_args'] );
			}
		}
		$wp_filter[ $name ] = $selected;
		try {
			if ( $filter ) {
				return apply_filters( $name, ...$args );
			}
			do_action( $name, ...$args );
			return null;
		} finally {
			$GLOBALS['wp_current_filter'] = $filter_stack;
			if ( null === $action_count ) {
				unset( $GLOBALS['wp_actions'][ $name ] );
			} else {
				$GLOBALS['wp_actions'][ $name ] = $action_count;
			}
			if ( $original ) {
				$wp_filter[ $name ] = $original;
			} else {
				unset( $wp_filter[ $name ] );
			}
		}
	}

	/** A separate asset queue preserves dependencies and inline/localized data. */
	private static function fresh_assets( $assets ) {
		$copy = clone $assets;
		foreach ( $copy->registered as $handle => $dependency ) {
			$copy->registered[ $handle ] = clone $dependency;
		}
		$copy->queue = array();
		$copy->done = array();
		$copy->to_do = array();
		if ( $copy instanceof WP_Scripts ) {
			$copy->in_footer = array();
		}
		return $copy;
	}

	private static function capture( $callback ) {
		ob_start();
		try {
			call_user_func( $callback );
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	public static function preview_injection( $injection, $post_id ) {
		if ( ! is_array( $injection ) || ! self::enabled( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $injection;
		}
		return self::with_post( $post_id, static function() use ( $injection, $post_id ) {
			$styles = wp_styles();
			$scripts = wp_scripts();
			$GLOBALS['wp_styles'] = self::fresh_assets( $styles );
			$GLOBALS['wp_scripts'] = self::fresh_assets( $scripts );
			try {
				$settings = self::provider_hook( 'block_editor_settings_all', array( array( 'styles' => array() ), new WP_Block_Editor_Context( array( 'post' => get_post( $post_id ) ) ) ), true );
				$css = '';
				foreach ( $settings['styles'] ?? array() as $style ) {
					$css .= (string) ( $style['css'] ?? '' ) . "\n";
				}
				$has_editor_styles = '' !== trim( $css );
				$head = self::capture( static function() use ( $has_editor_styles ) {
					self::provider_hook( 'wp_enqueue_scripts' );
					self::provider_hook( 'admin_enqueue_scripts', array( 'gt_page_blocks_builder' ) );
					self::provider_hook( 'wp_head', array(), false, $has_editor_styles );
					wp_styles()->do_items();
					wp_scripts()->do_head_items();
				} );
				$body = self::capture( static function() { self::provider_hook( 'wp_body_open' ); } );
				$footer = self::capture( static function() use ( $has_editor_styles ) {
					self::provider_hook( 'wp_footer', array(), false, $has_editor_styles );
					wp_styles()->do_items();
					wp_scripts()->do_footer_items();
				} );
				$injection['headHtml'] = $head . ( $injection['headHtml'] ?? '' );
				$injection['bodyStartHtml'] = $body . ( $injection['bodyStartHtml'] ?? '' );
				$injection['bodyEndHtml'] = $footer . ( $injection['bodyEndHtml'] ?? '' );
				$injection['css'] = $css . ( $injection['css'] ?? '' );
				return $injection;
			} finally {
				$GLOBALS['wp_styles'] = $styles;
				$GLOBALS['wp_scripts'] = $scripts;
			}
		} );
	}

	public static function filter_html( $html, $post_id ) {
		if ( ! self::enabled( $post_id ) ) {
			return $html;
		}
		return self::with_post( $post_id, static function() use ( $html ) {
			return (string) self::provider_hook( 'the_content', array( $html ), true );
		} );
	}
}
