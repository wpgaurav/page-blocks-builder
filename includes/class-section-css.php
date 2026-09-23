<?php
/** One generated stylesheet per opted-in page section. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_section_css {
	const META_KEY = '_gt_pb_section_css_files';
	private static $printed = array();
	private static $manifests = array();
	private static $loader_printed = false;

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 15, 2 );
		add_action( 'delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 99 );
		add_action( 'clean_post_cache', array( __CLASS__, 'invalidate' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'metadata_changed' ), 10, 3 );
		}
	}

	private static function cache_key( $post_id ) {
		return get_current_blog_id() . ':' . (int) $post_id;
	}

	public static function invalidate( $post_id ) {
		unset( self::$manifests[ self::cache_key( $post_id ) ] );
	}

	public static function metadata_changed( $meta_id, $post_id, $meta_key ) {
		if ( self::META_KEY === $meta_key ) {
			self::invalidate( $post_id );
		}
	}

	private static function directory() {
		$uploads = wp_upload_dir();
		return array( 'path' => $uploads['basedir'] . '/gt-page-blocks', 'url' => $uploads['baseurl'] . '/gt-page-blocks' );
	}

	private static function sections( $post ) {
		$sections = array();
		foreach ( GT_Page_Blocks_Builder::find_page_blocks( parse_blocks( $post->post_content ) ) as $index => $block ) {
			$attrs = $block['attrs'];
			if ( 'file' !== ( $attrs['cssOutput'] ?? '' ) || ! empty( $attrs['blockId'] ) || ! empty( $attrs['blockSlug'] ) || empty( $attrs['css'] ) ) {
				continue;
			}
			$sections[ $index ] = array( 'css' => (string) $attrs['css'], 'defer' => ! empty( $attrs['cssDefer'] ) );
		}
		return $sections;
	}

	/** Save rotates every filename, even when only the page title changed. */
	public static function on_save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, gt_page_blocks_builder_post_types(), true ) ) {
			return;
		}
		self::generate( $post, true );
	}

	private static function valid_name( $name, $post_id ) {
		return is_string( $name ) && 1 === preg_match( '/^page-' . (int) $post_id . '-[a-z0-9]{5}\.css$/', $name );
	}

	/** Failed writes leave the section available for inline fallback. */
	private static function generate( $post, $rotate = false ) {
		$directory = self::directory();
		$key = self::cache_key( $post->ID );
		$cached = self::$manifests[ $key ] ?? null;
		if ( ! $rotate && $cached && $cached['content'] === $post->post_content && $cached['directory'] === $directory ) {
			return $cached['assets'];
		}
		$previous = get_post_meta( $post->ID, self::META_KEY, true );
		$previous = is_array( $previous ) ? $previous : array();
		$assets = array();
		foreach ( self::sections( $post ) as $index => $section ) {
			$css = $section['css'];
			$hash = hash( 'sha256', $css );
			$old = $previous[ $index ] ?? array();
			$name = $old['file'] ?? '';
			if ( $rotate || ( $old['hash'] ?? '' ) !== $hash || ! self::valid_name( $name, $post->ID ) ) {
				do {
					$name = 'page-' . $post->ID . '-' . strtolower( wp_generate_password( 5, false, false ) ) . '.css';
				} while ( file_exists( $directory['path'] . '/' . $name ) || in_array( $name, array_column( $previous, 'file' ), true ) || in_array( $name, array_column( $assets, 'file' ), true ) );
			}
			$path = $directory['path'] . '/' . $name;
			if ( ! file_exists( $path ) ) {
				if ( ! wp_mkdir_p( $directory['path'] ) ) {
					continue;
				}
				$minified = GT_Page_Blocks_Builder::minify_css( GT_Page_Blocks_Builder::sanitize_css( $css ) );
				if ( ! function_exists( 'wp_tempnam' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$temp = wp_tempnam( $name, $directory['path'] );
				if ( ! $temp ) {
					continue;
				}
				$written = @file_put_contents( $temp, $minified, LOCK_EX );
				$ready = false !== $written && $written === strlen( $minified ) && @rename( $temp, $path );
				if ( ! $ready ) {
					wp_delete_file( $temp );
					continue;
				}
				@chmod( $path, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			}
			$assets[ $index ] = array( 'hash' => $hash, 'file' => $name, 'defer' => $section['defer'] );
		}
		if ( $assets !== $previous ) {
			update_post_meta( $post->ID, self::META_KEY, $assets );
		}
		if ( $rotate ) {
			// Keep old URLs valid for cached HTML for seven days. Prune only
			// this post's generated files, never unrelated uploaded assets.
			foreach ( (array) glob( $directory['path'] . '/page-' . $post->ID . '-*.css' ) as $path ) {
				if ( self::valid_name( basename( $path ), $post->ID ) && ! in_array( basename( $path ), array_column( $assets, 'file' ), true ) && filemtime( $path ) < time() - WEEK_IN_SECONDS ) {
					wp_delete_file( $path );
				}
			}
		}
		$lookup = array();
		foreach ( $assets as $asset ) {
			$css_key = $asset['hash'] . ':' . (int) $asset['defer'];
			if ( ! isset( $lookup[ $css_key ] ) ) {
				$lookup[ $css_key ] = $asset;
			}
		}
		self::$manifests[ $key ] = array( 'content' => $post->post_content, 'directory' => $directory, 'assets' => $assets, 'lookup' => $lookup );
		return $assets;
	}

	private static function tag( $file, $defer = false ) {
		$directory = self::directory();
		$url = esc_url( $directory['url'] . '/' . $file );
		if ( isset( self::$printed[ $url ] ) ) {
			return '';
		}
		self::$printed[ $url ] = true;
		$stylesheet = '<link rel="stylesheet" href="' . $url . '" media="all">';
		if ( $defer ) {
			// Non-matching media downloads without blocking screen rendering.
			// Keep the link in place so the authored cascade order is retained.
			$loader = '';
			if ( ! self::$loader_printed ) {
				self::$loader_printed = true;
				// The WordPress attributes filter lets nonce-based CSPs authorize
				// this file. No inline event handler or unsafe-inline is required.
				$loader = wp_get_script_tag( array(
					'id' => 'gt-pb-deferred-css',
					'src' => GT_PB_BUILDER_URL . 'assets/js/deferred-css.js?ver=' . filemtime( GT_PB_BUILDER_DIR . 'assets/js/deferred-css.js' ),
					'defer' => true,
				) );
			}
			return '<link rel="stylesheet" href="' . $url . '" media="print" data-gt-pb-deferred>' . "\n"
				. '<noscript>' . $stylesheet . '</noscript>' . "\n" . $loader;
		}
		return $stylesheet . "\n";
	}

	public static function print_head() {
		if ( ! is_singular() || ! empty( $_GET['build'] ) || ! empty( $_GET['gt_pb_preview'] ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$assets = self::generate( $post );
		$directory = self::directory();
		foreach ( $assets as $asset ) {
			clearstatcache( true, $directory['path'] . '/' . $asset['file'] );
			if ( ! is_file( $directory['path'] . '/' . $asset['file'] ) ) {
				self::invalidate( $post->ID );
				$assets = self::generate( $post );
				break;
			}
		}
		foreach ( $assets as $asset ) {
			echo self::tag( $asset['file'], $asset['defer'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Covers rendering outside the main loop and unavailable upload storage. */
	public static function render( $css, $post_id, $defer = false ) {
		$post = get_post( $post_id );
		if ( $post ) {
			self::generate( $post );
			$key = self::cache_key( $post_id );
			$css_key = hash( 'sha256', $css ) . ':' . (int) (bool) $defer;
			$asset = self::$manifests[ $key ]['lookup'][ $css_key ] ?? null;
			if ( $asset ) {
				$path = self::directory()['path'] . '/' . $asset['file'];
				clearstatcache( true, $path );
				if ( ! is_file( $path ) ) {
					self::invalidate( $post_id );
					self::generate( $post );
					$asset = self::$manifests[ $key ]['lookup'][ $css_key ] ?? null;
				}
				if ( $asset ) return self::tag( $asset['file'], $asset['defer'] );
			}
		}
		return '<style>' . GT_Page_Blocks_Builder::minify_css( GT_Page_Blocks_Builder::sanitize_css( $css ) ) . '</style>' . "\n";
	}

	public static function on_delete( $post_id ) {
		self::invalidate( $post_id );
		$directory = self::directory();
		foreach ( (array) glob( $directory['path'] . '/page-' . (int) $post_id . '-*.css' ) as $path ) {
			if ( self::valid_name( basename( $path ), $post_id ) ) {
				wp_delete_file( $path );
			}
		}
	}
}
