<?php
/**
 * Smart CSS Loader
 *
 * - Reset CSS: optionally inlined in <head> when setting is enabled.
 * - Utilities CSS: parses page content for class attributes, inlines ONLY
 *   the utility rules actually used. Builder loads the full file.
 *
 * @since 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class gt_pb_css_loader {

	/**
	 * Parsed utility rules cache: class_name => rule_text
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $rules_cache = null;

	/**
	 * Hook frontend output.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'output_inline_css' ), 5 );
	}

	/**
	 * Output reset + utility CSS inline in <head>.
	 */
	public static function output_inline_css(): void {
		// Skip in admin and feeds
		if ( is_admin() || is_feed() ) {
			return;
		}

		$reset_enabled      = (bool) get_option( 'gt_pb_load_reset', false );
		$typography_enabled = (bool) get_option( 'gt_pb_load_typography', false );
		$utilities_enabled  = (bool) get_option( 'gt_pb_load_utilities', false );

		if ( ! $reset_enabled && ! $typography_enabled && ! $utilities_enabled ) {
			return;
		}

		$out = '';

		if ( $reset_enabled ) {
			$reset_path = GT_PB_BUILDER_DIR . 'assets/css/reset.min.css';
			if ( file_exists( $reset_path ) ) {
				$out .= file_get_contents( $reset_path );
			}
		}

		if ( $typography_enabled ) {
			$typo_path = GT_PB_BUILDER_DIR . 'assets/css/typography.min.css';
			if ( file_exists( $typo_path ) ) {
				$out .= file_get_contents( $typo_path );
			}
		}

		if ( $utilities_enabled ) {
			$used_classes = self::collect_used_classes();
			if ( ! empty( $used_classes ) ) {
				$utility_css = self::extract_used_utilities( $used_classes );
				if ( ! empty( $utility_css ) ) {
					$out .= $utility_css;
				}
			}
		}

		if ( ! empty( $out ) ) {
			echo "\n<style id=\"gt-pb-utilities\">{$out}</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Collect all class names used on the current page.
	 *
	 * Scans: post_content (current post), widgets, optional filter for extras.
	 *
	 * @return array<string>
	 */
	private static function collect_used_classes(): array {
		$content = '';

		// Singular: post content
		if ( is_singular() ) {
			global $post;
			if ( $post && ! empty( $post->post_content ) ) {
				$content .= $post->post_content . ' ';
			}
		}

		// Archive: collect from current loop
		if ( ( is_home() || is_archive() || is_search() ) ) {
			global $wp_query;
			if ( ! empty( $wp_query->posts ) ) {
				foreach ( $wp_query->posts as $p ) {
					if ( ! empty( $p->post_content ) ) {
						$content .= $p->post_content . ' ';
					}
				}
			}
		}

		// Allow themes/plugins to add extra class sources
		$content = apply_filters( 'gt_pb_class_scan_content', $content );

		if ( empty( $content ) ) {
			return array();
		}

		// Extract all class="..." values
		$classes = array();
		if ( preg_match_all( '/class\s*=\s*["\']([^"\']+)["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $class_attr ) {
				foreach ( preg_split( '/\s+/', trim( $class_attr ) ) as $cls ) {
					if ( $cls !== '' ) {
						$classes[ $cls ] = true;
					}
				}
			}
		}

		return array_keys( $classes );
	}

	/**
	 * Parse utilities.css and build a class_name => rule map.
	 *
	 * @return array<string,string>
	 */
	private static function get_utility_rules(): array {
		if ( self::$rules_cache !== null ) {
			return self::$rules_cache;
		}

		$cache_key = 'gt_pb_util_rules_v' . GT_PB_BUILDER_VERSION;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			self::$rules_cache = $cached;
			return $cached;
		}

		$path = GT_PB_BUILDER_DIR . 'assets/css/utilities.css';
		if ( ! file_exists( $path ) ) {
			self::$rules_cache = array();
			return array();
		}

		$css   = file_get_contents( $path );
		$rules = array();

		// First, extract media query blocks separately
		$media_blocks = array();
		$css_no_media = preg_replace_callback(
			'/@media[^{]+\{((?:[^{}]+|\{[^{}]*\})*)\}/i',
			function ( $m ) use ( &$media_blocks ) {
				$placeholder           = '/*MEDIA_BLOCK_' . count( $media_blocks ) . '*/';
				$media_blocks[]        = $m[0];
				return $placeholder;
			},
			$css
		);

		// Parse top-level rules: .selector { ... }
		// Match individual selectors followed by { ... }
		if ( preg_match_all( '/(\.[\w\\\\\/:-]+)\s*\{([^}]*)\}/', $css_no_media, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$selector = $match[1];
				$body     = trim( $match[2] );
				// Remove leading dot to get class name; un-escape backslashes
				$class_name           = str_replace( '\\', '', substr( $selector, 1 ) );
				$rules[ $class_name ] = $selector . '{' . $body . '}';
			}
		}

		// Parse rules inside media blocks
		foreach ( $media_blocks as $block ) {
			if ( preg_match( '/(@media[^{]+\{)(.*)\}\s*$/s', $block, $bm ) ) {
				$media_open = trim( $bm[1] );
				$inner      = $bm[2];
				if ( preg_match_all( '/(\.[\w\\\\\/:-]+)\s*\{([^}]*)\}/', $inner, $inner_matches, PREG_SET_ORDER ) ) {
					foreach ( $inner_matches as $im ) {
						$selector            = $im[1];
						$body                = trim( $im[2] );
						$class_name          = str_replace( '\\', '', substr( $selector, 1 ) );
						$rule                = $media_open . $selector . '{' . $body . '}}';
						// Multiple rules per class can exist (different breakpoints);
						// concatenate them.
						$rules[ $class_name ] = isset( $rules[ $class_name ] )
							? $rules[ $class_name ] . $rule
							: $rule;
					}
				}
			}
		}

		set_transient( $cache_key, $rules, DAY_IN_SECONDS );
		self::$rules_cache = $rules;

		return $rules;
	}

	/**
	 * Extract utility CSS rules for the given list of used classes.
	 *
	 * @param array<string> $used_classes Class names used on the page.
	 * @return string Concatenated CSS rules.
	 */
	private static function extract_used_utilities( array $used_classes ): string {
		$rules = self::get_utility_rules();
		if ( empty( $rules ) ) {
			return '';
		}

		$out = '';
		foreach ( $used_classes as $class ) {
			if ( isset( $rules[ $class ] ) ) {
				$out .= $rules[ $class ];
			}
		}

		// Minify final output (whitespace only)
		$out = preg_replace( '/\s+/', ' ', $out );
		$out = preg_replace( '/\s*([:;{},])\s*/', '$1', $out );

		return (string) $out;
	}

	/**
	 * Clear the rules cache (call after utilities.css changes).
	 */
	public static function flush_cache(): void {
		self::$rules_cache = null;
		delete_transient( 'gt_pb_util_rules_v' . GT_PB_BUILDER_VERSION );
	}
}
