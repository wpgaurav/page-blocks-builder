<?php
/** One generated stylesheet per opted-in page section. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_section_css {
	const META_KEY = '_gt_pb_section_css_files';
	private static $printed = array();

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 15, 2 );
		add_action( 'delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 99 );
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
			$sections[ $index ] = (string) $attrs['css'];
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
		$previous = get_post_meta( $post->ID, self::META_KEY, true );
		$previous = is_array( $previous ) ? $previous : array();
		$assets = array();
		foreach ( self::sections( $post ) as $index => $css ) {
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
			$assets[ $index ] = array( 'hash' => $hash, 'file' => $name );
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
		return $assets;
	}

	private static function tag( $file ) {
		if ( isset( self::$printed[ $file ] ) ) {
			return '';
		}
		self::$printed[ $file ] = true;
		$directory = self::directory();
		return '<link rel="stylesheet" href="' . esc_url( $directory['url'] . '/' . $file ) . '" media="all">' . "\n";
	}

	public static function print_head() {
		if ( ! is_singular() || ! empty( $_GET['build'] ) || ! empty( $_GET['gt_pb_preview'] ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		foreach ( self::generate( $post ) as $asset ) {
			echo self::tag( $asset['file'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Covers rendering outside the main loop and unavailable upload storage. */
	public static function render( $css, $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			foreach ( self::generate( $post ) as $asset ) {
				if ( hash( 'sha256', $css ) === $asset['hash'] ) {
					return self::tag( $asset['file'] );
				}
			}
		}
		return '<style>' . GT_Page_Blocks_Builder::minify_css( GT_Page_Blocks_Builder::sanitize_css( $css ) ) . '</style>' . "\n";
	}

	public static function on_delete( $post_id ) {
		$directory = self::directory();
		foreach ( (array) glob( $directory['path'] . '/page-' . (int) $post_id . '-*.css' ) as $path ) {
			if ( self::valid_name( basename( $path ), $post_id ) ) {
				wp_delete_file( $path );
			}
		}
	}
}
