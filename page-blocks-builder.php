<?php
/**
 * Plugin Name: GT Page Blocks Builder
 * Description: Standalone visual Page Blocks builder with HTML/CSS/JS sections synced to Gutenberg block content.
 * Version: 1.1.2
 * Author: Gaurav Tiwari
 * Text Domain: page-blocks-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GT_PB_BUILDER_VERSION' ) ) {
	define( 'GT_PB_BUILDER_VERSION', '1.1.2' );
}

if ( ! defined( 'GT_PB_BUILDER_FILE' ) ) {
	define( 'GT_PB_BUILDER_FILE', __FILE__ );
}

if ( ! defined( 'GT_PB_BUILDER_DIR' ) ) {
	define( 'GT_PB_BUILDER_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GT_PB_BUILDER_URL' ) ) {
	define( 'GT_PB_BUILDER_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'GT_PB_BUILDER_OPTION_POST_TYPES' ) ) {
	define( 'GT_PB_BUILDER_OPTION_POST_TYPES', 'gt_pb_builder_post_types' );
}

if ( ! function_exists( 'md_page_blocks_builder_post_types' ) ) {
	/**
	 * Get allowed post types for builder mode.
	 *
	 * @return array
	 */
	function md_page_blocks_builder_post_types() {
		$defaults   = array( 'post', 'page' );
		$post_types = get_option( GT_PB_BUILDER_OPTION_POST_TYPES, array() );

		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			$post_types = $defaults;
		}

		$string_keys = array_filter( array_keys( $post_types ), 'is_string' );
		if ( ! empty( $string_keys ) ) {
			$keyed_values = array();
			foreach ( $post_types as $post_type => $enabled ) {
				if ( ! empty( $enabled ) ) {
					$keyed_values[] = $post_type;
				}
			}
			if ( ! empty( $keyed_values ) ) {
				$post_types = $keyed_values;
			}
		}

		$post_types = apply_filters( 'md_page_blocks_builder_post_types', $post_types );
		if ( ! is_array( $post_types ) ) {
			return $defaults;
		}

		$post_types = array_map( 'sanitize_key', $post_types );
		$post_types = array_filter( $post_types );
		$post_types = array_values( array_unique( $post_types ) );

		return ! empty( $post_types ) ? $post_types : $defaults;
	}
}

if ( ! function_exists( 'md_page_blocks_builder_nonce_action' ) ) {
	/**
	 * Builder nonce action.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function md_page_blocks_builder_nonce_action( $post_id ) {
		return 'md_page_blocks_builder_' . absint( $post_id );
	}
}

if ( ! function_exists( 'md_page_blocks_preview_nonce_action' ) ) {
	/**
	 * Preview nonce action.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function md_page_blocks_preview_nonce_action( $post_id ) {
		return 'md_page_blocks_preview_' . absint( $post_id );
	}
}

if ( ! function_exists( 'md_page_blocks_builder_url' ) ) {
	/**
	 * Build frontend visual builder URL.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $nonce   Nonce.
	 * @return string
	 */
	function md_page_blocks_builder_url( $post_id, $nonce = '' ) {
		$args = array(
			'build'   => 'page-blocks',
			'post_id' => absint( $post_id ),
		);

		if ( ! empty( $nonce ) ) {
			$args['pb_nonce'] = $nonce;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}
}

class GT_Page_Blocks_Builder {
	const BLOCK_NAME = 'marketers-delight/page-block';

	/**
	 * Footer JS queue.
	 *
	 * @var array<string, string>
	 */
	private $footer_scripts = array();

	/**
	 * Theme class cache.
	 *
	 * @var array|null
	 */
	private $theme_class_suggestions = null;

	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		add_filter( 'template_include', array( $this, 'builder_template_include' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_builder_assets' ), 1 );
		add_action( 'admin_bar_menu', array( $this, 'add_builder_admin_bar_link' ), 80 );

		add_action( 'wp_ajax_md_page_blocks_builder_apply', array( $this, 'ajax_builder_apply' ) );
		add_action( 'wp_ajax_md_page_blocks_builder_preview', array( $this, 'ajax_builder_preview' ) );

		add_action( 'wp_footer', array( $this, 'output_footer_scripts' ), 99 );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );

		add_action( 'admin_footer', array( $this, 'output_rankmath_integration' ) );

		add_filter( 'theme_page_templates', array( $this, 'register_page_templates' ) );
		add_filter( 'template_include', array( $this, 'load_page_template' ) );
		add_action( 'wp_head', array( $this, 'output_template_styles' ) );

		if ( ! is_admin() && $this->is_builder_request() ) {
			add_filter( 'show_admin_bar', '__return_false' );
		}
	}

	/**
	 * Register block category if missing.
	 *
	 * @param array $categories Existing categories.
	 * @return array
	 */
	public function register_block_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		foreach ( $categories as $category ) {
			if ( ! empty( $category['slug'] ) && $category['slug'] === 'marketers-delight' ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => 'marketers-delight',
			'title' => __( 'Marketers Delight', 'page-blocks-builder' ),
		);

		return $categories;
	}

	/**
	 * Register block type.
	 */
	public function register_block() {
		register_block_type(
			self::BLOCK_NAME,
			array(
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'content'    => array( 'type' => 'string', 'default' => '' ),
					'css'        => array( 'type' => 'string', 'default' => '' ),
					'js'         => array( 'type' => 'string', 'default' => '' ),
					'jsLocation' => array( 'type' => 'string', 'default' => 'footer' ),
					'format'     => array( 'type' => 'boolean', 'default' => false ),
					'phpExec'    => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
	public function enqueue_block_editor_assets() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_id       = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$preview_nonce = $post_id > 0 ? wp_create_nonce( md_page_blocks_preview_nonce_action( $post_id ) ) : '';

		$editor_settings = array(
			'html' => wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) ),
			'css'  => wp_enqueue_code_editor( array( 'type' => 'text/css' ) ),
			'js'   => wp_enqueue_code_editor( array( 'type' => 'application/javascript' ) ),
		);

		$script_path = GT_PB_BUILDER_DIR . 'assets/js/block-editor.js';
		$style_path  = GT_PB_BUILDER_DIR . 'assets/css/block-editor.css';

		if ( file_exists( $script_path ) ) {
			wp_enqueue_script(
				'gt-page-block-editor',
				GT_PB_BUILDER_URL . 'assets/js/block-editor.js',
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'code-editor', 'wp-codemirror' ),
				filemtime( $script_path ),
				true
			);

			$preview_styles = array( get_stylesheet_uri() );
		if ( is_child_theme() ) {
			$preview_styles[] = get_template_directory_uri() . '/style.css';
		}

		wp_localize_script(
				'gt-page-block-editor',
				'mdPageBlockEditor',
				array(
					'codeEditorSettings' => $editor_settings,
					'classSuggestions'   => $this->get_theme_class_suggestions(),
					'postId'             => $post_id,
					'previewEndpoint'    => admin_url( 'admin-ajax.php' ),
					'previewAction'      => 'md_page_blocks_builder_preview',
					'previewNonce'       => $preview_nonce,
					'previewStyles'      => $preview_styles,
				)
			);
		}

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'gt-page-block-editor',
				GT_PB_BUILDER_URL . 'assets/css/block-editor.css',
				array( 'wp-edit-blocks' ),
				filemtime( $style_path )
			);
		}
	}

	/**
	 * Render block frontend output.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$content    = isset( $attributes['content'] ) ? (string) $attributes['content'] : '';
		$css        = isset( $attributes['css'] ) ? (string) $attributes['css'] : '';
		$js         = isset( $attributes['js'] ) ? (string) $attributes['js'] : '';
		$js_loc     = isset( $attributes['jsLocation'] ) && $attributes['jsLocation'] === 'inline' ? 'inline' : 'footer';
		$format     = ! empty( $attributes['format'] );
		$php_exec   = ! empty( $attributes['phpExec'] );
		$output     = '';

		if ( $css !== '' ) {
			$css    = self::sanitize_css( $css );
			$output .= '<style>' . self::minify_css( $css ) . '</style>' . "\n";
		}

		if ( $content !== '' ) {
			if ( $php_exec ) {
				$content = $this->execute_php( $content );
			}

			if ( $format ) {
				$content = apply_filters( 'the_content', $content );
			} else {
				$content = do_shortcode( $content );
			}

			$output .= self::minify_html( (string) $content );
		}

		if ( $js !== '' ) {
			$js      = self::minify_js( $js );
			$block_id = 'pb-' . substr( md5( $js ), 0, 8 );

			if ( $js_loc === 'inline' ) {
				$output .= '<script id="page-block-js-' . esc_attr( $block_id ) . '">' . $js . '</script>' . "\n";
			} else {
				$this->footer_scripts[ $block_id ] = $js;
			}
		}

		return $output;
	}

	/**
	 * Print queued footer scripts.
	 */
	public function output_footer_scripts() {
		if ( empty( $this->footer_scripts ) || ! is_array( $this->footer_scripts ) ) {
			return;
		}

		foreach ( $this->footer_scripts as $id => $js ) {
			echo '<script id="page-block-js-' . esc_attr( $id ) . '">' . $js . '</script>' . "\n";
		}

		$this->footer_scripts = array();
	}

	/**
	 * Builder route check.
	 *
	 * @return bool
	 */
	public function is_builder_request() {
		return ! is_admin() && isset( $_GET['build'] ) && sanitize_key( wp_unslash( $_GET['build'] ) ) === 'page-blocks';
	}

	/**
	 * Get target post ID.
	 *
	 * @return int
	 */
	public function get_builder_post_id() {
		return isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	}

	/**
	 * Determine if requested post type is enabled.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_builder_post_type_allowed( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( empty( $post_type ) ) {
			return false;
		}

		return in_array( $post_type, md_page_blocks_builder_post_types(), true );
	}

	/**
	 * Validate builder access.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $nonce   Nonce.
	 * @return bool
	 */
	public function can_access_builder( $post_id, $nonce ) {
		if ( $post_id <= 0 || ! is_user_logged_in() || ! get_post( $post_id ) ) {
			return false;
		}

		if ( ! $this->is_builder_post_type_allowed( $post_id ) ) {
			return false;
		}

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, md_page_blocks_builder_nonce_action( $post_id ) ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Switch to standalone builder shell template.
	 *
	 * @param string $template Current template.
	 * @return string
	 */
	public function builder_template_include( $template ) {
		if ( ! $this->is_builder_request() ) {
			return $template;
		}

		$post_id = $this->get_builder_post_id();
		$nonce   = isset( $_GET['pb_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['pb_nonce'] ) ) : '';

		if ( ! $this->can_access_builder( $post_id, $nonce ) ) {
			status_header( 403 );
			nocache_headers();
			wp_die(
				esc_html__( 'You do not have permission to access the Page Blocks Builder.', 'page-blocks-builder' ),
				esc_html__( 'Forbidden', 'page-blocks-builder' ),
				array( 'response' => 403 )
			);
		}

		$this->maybe_set_builder_template( $post_id );

		$builder_template = GT_PB_BUILDER_DIR . 'templates/builder-shell.php';
		return file_exists( $builder_template ) ? $builder_template : $template;
	}

	/**
	 * Auto-set the post template to Page Blocks Builder if not already using a builder template.
	 *
	 * @param int $post_id Post ID.
	 */
	private function maybe_set_builder_template( $post_id ) {
		$current = get_page_template_slug( $post_id );
		$builder_templates = array( 'page-blocks-builder.php', 'page-blocks-full-builder.php' );

		if ( in_array( $current, $builder_templates, true ) ) {
			return;
		}

		update_post_meta( $post_id, '_wp_page_template', 'page-blocks-builder.php' );
	}

	/**
	 * Enqueue frontend builder assets.
	 */
	public function enqueue_builder_assets() {
		if ( ! $this->is_builder_request() ) {
			return;
		}

		$post_id = $this->get_builder_post_id();
		$nonce   = isset( $_GET['pb_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['pb_nonce'] ) ) : '';
		if ( ! $this->can_access_builder( $post_id, $nonce ) ) {
			return;
		}

		$editor_settings = array(
			'html' => wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) ),
			'css'  => wp_enqueue_code_editor( array( 'type' => 'text/css' ) ),
			'js'   => wp_enqueue_code_editor( array( 'type' => 'application/javascript' ) ),
		);

		$css_path = GT_PB_BUILDER_DIR . 'assets/css/builder-shell.css';
		$js_path  = GT_PB_BUILDER_DIR . 'assets/js/builder-shell.js';

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'gt-page-block-builder-shell',
				GT_PB_BUILDER_URL . 'assets/css/builder-shell.css',
				array( 'code-editor' ),
				filemtime( $css_path )
			);
		}

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'gt-page-block-builder-shell',
				GT_PB_BUILDER_URL . 'assets/js/builder-shell.js',
				array( 'code-editor', 'wp-codemirror' ),
				filemtime( $js_path ),
				true
			);
		}

		wp_localize_script(
			'gt-page-block-builder-shell',
			'mdPageBlocksBuilderShell',
			array(
				'postId'             => $post_id,
				'blockName'          => self::BLOCK_NAME,
				'applyEndpoint'      => admin_url( 'admin-ajax.php' ),
				'applyAction'        => 'md_page_blocks_builder_apply',
				'applyNonce'         => $nonce,
				'previewEndpoint'    => admin_url( 'admin-ajax.php' ),
				'previewAction'      => 'md_page_blocks_builder_preview',
				'previewNonce'       => wp_create_nonce( md_page_blocks_preview_nonce_action( $post_id ) ),
				'editPostUrl'        => get_edit_post_link( $post_id, 'raw' ) ?: '',
				'initialSections'    => $this->get_builder_sections_from_post( $post_id ),
				'postTemplate'       => $this->get_builder_post_template_slug( $post_id ),
				'previewInjection'   => $this->get_builder_preview_injection( $post_id ),
				'codeEditorSettings' => $editor_settings,
				'themeStyleUrls'     => $this->get_theme_style_urls(),
				'themeBaseUrls'      => array_values(
					array_unique(
						array_filter(
							array(
								trailingslashit( get_stylesheet_directory_uri() ),
								trailingslashit( get_template_directory_uri() ),
							)
						)
					)
				),
			)
		);
	}

	/**
	 * Get explicit child + parent theme stylesheet URLs for preview iframe.
	 *
	 * @return array
	 */
	private function get_theme_style_urls() {
		$urls = array();

		$stylesheet_uri = get_stylesheet_uri();
		if ( is_string( $stylesheet_uri ) && $stylesheet_uri !== '' ) {
			$urls[] = esc_url_raw( $stylesheet_uri );
		}

		$template_uri = trailingslashit( get_template_directory_uri() ) . 'style.css';
		if ( is_string( $template_uri ) && $template_uri !== '' ) {
			$urls[] = esc_url_raw( $template_uri );
		}

		$dir_to_uri = array(
			wp_normalize_path( get_stylesheet_directory() ) => trailingslashit( get_stylesheet_directory_uri() ),
			wp_normalize_path( get_template_directory() )   => trailingslashit( get_template_directory_uri() ),
		);

		foreach ( $this->get_theme_style_files() as $file ) {
			$file_path = wp_normalize_path( $file );
			foreach ( $dir_to_uri as $dir_path => $dir_uri ) {
				if ( strpos( $file_path, $dir_path ) !== 0 ) {
					continue;
				}

				$relative = ltrim( substr( $file_path, strlen( $dir_path ) ), '/' );
				if ( $relative === '' ) {
					continue;
				}

				$urls[] = esc_url_raw( $dir_uri . str_replace( DIRECTORY_SEPARATOR, '/', $relative ) );
				break;
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		return $urls;
	}

	/**
	 * Normalize template slug for preview width behavior.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_builder_post_template_slug( $post_id ) {
		$template = get_page_template_slug( $post_id );
		if ( empty( $template ) ) {
			$template = get_post_meta( $post_id, '_wp_page_template', true );
		}

		$template = is_string( $template ) ? sanitize_file_name( $template ) : '';

		if ( empty( $template ) || $template === 'default' ) {
			return 'default-template';
		}

		return $template;
	}

	/**
	 * Preview iframe custom injection data.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	private function get_builder_preview_injection( $post_id ) {
		$defaults = array(
			'headHtml'      => '',
			'bodyStartHtml' => '',
			'bodyEndHtml'   => '',
			'css'           => '',
			'jsHead'        => '',
			'jsFooter'      => '',
		);

		$injection = apply_filters( 'md_page_blocks_builder_preview_injection', $defaults, $post_id );
		if ( ! is_array( $injection ) ) {
			return $defaults;
		}

		$normalized = $defaults;
		foreach ( $defaults as $key => $value ) {
			$normalized[ $key ] = isset( $injection[ $key ] ) ? (string) $injection[ $key ] : $value;
		}

		return $normalized;
	}

	/**
	 * Check preview access.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $nonce   Nonce.
	 * @return bool
	 */
	private function can_access_preview( $post_id, $nonce ) {
		if ( $post_id <= 0 || ! is_user_logged_in() || ! get_post( $post_id ) ) {
			return false;
		}

		if ( empty( $nonce ) ) {
			return false;
		}

		$valid_nonce = wp_verify_nonce( $nonce, md_page_blocks_preview_nonce_action( $post_id ) )
			|| wp_verify_nonce( $nonce, md_page_blocks_builder_nonce_action( $post_id ) );

		if ( ! $valid_nonce ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Normalize section payload.
	 *
	 * @param array $section Raw section.
	 * @return array
	 */
	private function normalize_builder_section( $section ) {
		$section     = is_array( $section ) ? $section : array();
		$js_location = isset( $section['jsLocation'] ) && $section['jsLocation'] === 'inline' ? 'inline' : 'footer';
		$content     = isset( $section['content'] ) ? (string) $section['content'] : '';
		$css         = isset( $section['css'] ) ? (string) $section['css'] : '';
		$js          = isset( $section['js'] ) ? (string) $section['js'] : '';

		return array(
			'content'    => $this->decode_builder_unicode_sequences( $content ),
			'css'        => $this->decode_builder_unicode_sequences( $css ),
			'js'         => $this->decode_builder_unicode_sequences( $js ),
			'jsLocation' => $js_location,
			'format'     => ! empty( $section['format'] ),
			'phpExec'    => ! empty( $section['phpExec'] ),
		);
	}

	/**
	 * Decode escaped unicode sequences for old saved content.
	 *
	 * @param string $value Raw content.
	 * @return string
	 */
	private function decode_builder_unicode_sequences( $value ) {
		$value = (string) $value;

		if ( $value === '' || stripos( $value, 'u00' ) === false ) {
			return $value;
		}

		$decode_callback = static function( $matches ) {
			$decoded = json_decode( '"\\u' . strtolower( $matches[1] ) . '"', true );
			return is_string( $decoded ) ? $decoded : $matches[0];
		};

		$decoded = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', $decode_callback, $value );
		if ( ! is_string( $decoded ) ) {
			$decoded = $value;
		}

		if ( strpos( $decoded, '<' ) === false && preg_match( '/(^|[^a-z0-9])u00[0-9a-fA-F]{2}/i', $decoded ) ) {
			$decoded_without_slashes = preg_replace_callback(
				'/(?<![a-z0-9])u([0-9a-fA-F]{4})/i',
				$decode_callback,
				$decoded
			);
			if ( is_string( $decoded_without_slashes ) ) {
				$decoded = $decoded_without_slashes;
			}
		}

		return $decoded;
	}

	/**
	 * Get Page Block sections from post content.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_builder_sections_from_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return array();
		}

		$sections     = array();
		$blocks       = parse_blocks( (string) $post->post_content );
		$page_blocks  = self::find_page_blocks( $blocks );

		foreach ( $page_blocks as $block ) {
			$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$sections[] = $this->normalize_builder_section( $attrs );
		}

		return $sections;
	}

	/**
	 * Recursively find all page-block blocks, including those inside containers.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Flat list of page-block blocks.
	 */
	public static function find_page_blocks( array $blocks ) {
		$found = array();

		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === self::BLOCK_NAME ) {
				$found[] = $block;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::find_page_blocks( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}

	/**
	 * Build preview payload.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	private function build_preview_payload( $sections ) {
		$html_output      = array();
		$css_output       = array();
		$js_inline_output = array();
		$js_footer_output = array();

		foreach ( (array) $sections as $section ) {
			$section     = is_array( $section ) ? $section : array();
			$content     = isset( $section['content'] ) ? (string) $section['content'] : '';
			$css         = isset( $section['css'] ) ? (string) $section['css'] : '';
			$js          = isset( $section['js'] ) ? (string) $section['js'] : '';
			$format      = ! empty( $section['format'] );
			$php_exec    = ! empty( $section['phpExec'] );
			$js_location = isset( $section['jsLocation'] ) && $section['jsLocation'] === 'inline' ? 'inline' : 'footer';

			if ( $content !== '' ) {
				if ( $php_exec ) {
					$content = $this->execute_php( $content );
				}

				$content = $format ? apply_filters( 'the_content', $content ) : do_shortcode( $content );
				$html_output[] = self::minify_html( (string) $content );
			}

			if ( $css !== '' ) {
				$css_output[] = self::minify_css( $css );
			}

			if ( $js !== '' ) {
				$js_minified = self::minify_js( $js );
				if ( $js_location === 'inline' ) {
					$js_inline_output[] = $js_minified;
				} else {
					$js_footer_output[] = $js_minified;
				}
			}
		}

		return array(
			'html'     => implode( "\n", $html_output ),
			'css'      => implode( "\n", $css_output ),
			'jsInline' => implode( ";\n", $js_inline_output ),
			'jsFooter' => implode( ";\n", $js_footer_output ),
		);
	}

	/**
	 * AJAX: save sections into block content.
	 */
	public function ajax_builder_apply() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Authentication required.', 'page-blocks-builder' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$nonce   = isset( $_POST['pb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pb_nonce'] ) ) : '';

		if ( ! $this->can_access_builder( $post_id, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to save Page Blocks.', 'page-blocks-builder' ) ), 403 );
		}

		$raw_sections = isset( $_POST['sections'] ) ? wp_unslash( $_POST['sections'] ) : '';
		$decoded      = json_decode( (string) $raw_sections, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid builder payload.', 'page-blocks-builder' ) ), 400 );
		}

		$sections = array();
		foreach ( $decoded as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$sections[] = $this->normalize_builder_section( $section );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post no longer exists.', 'page-blocks-builder' ) ), 404 );
		}

		$blocks                 = parse_blocks( (string) $post->post_content );
		$filtered               = array();
		$first_page_block_index = -1;

		foreach ( $blocks as $block ) {
			$is_page_block = is_array( $block ) && ( ( $block['blockName'] ?? '' ) === self::BLOCK_NAME );
			if ( $is_page_block ) {
				if ( $first_page_block_index === -1 ) {
					$first_page_block_index = count( $filtered );
				}
				continue;
			}
			$filtered[] = $block;
		}

		if ( $first_page_block_index === -1 ) {
			$first_page_block_index = count( $filtered );
		}

		$replacement_blocks = array();
		foreach ( $sections as $section ) {
			$replacement_blocks[] = array(
				'blockName'    => self::BLOCK_NAME,
				'attrs'        => $section,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}

		$next_blocks = array_merge(
			array_slice( $filtered, 0, $first_page_block_index ),
			$replacement_blocks,
			array_slice( $filtered, $first_page_block_index )
		);

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( serialize_blocks( $next_blocks ) ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ), 500 );
		}

		$this->maybe_set_builder_template( $post_id );

		wp_send_json_success(
			array(
				'message'     => __( 'Page Blocks saved.', 'page-blocks-builder' ),
				'postId'      => $post_id,
				'sections'    => $sections,
				'editPostUrl' => get_edit_post_link( $post_id, 'raw' ) ?: '',
			)
		);
	}

	/**
	 * AJAX: render preview payload.
	 */
	public function ajax_builder_preview() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Authentication required.', 'page-blocks-builder' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$nonce   = isset( $_POST['pb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pb_nonce'] ) ) : '';

		if ( ! $this->can_access_preview( $post_id, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to preview Page Blocks.', 'page-blocks-builder' ) ), 403 );
		}

		$raw_sections = isset( $_POST['sections'] ) ? wp_unslash( $_POST['sections'] ) : '';
		$decoded      = json_decode( (string) $raw_sections, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid preview payload.', 'page-blocks-builder' ) ), 400 );
		}

		$sections = array();
		foreach ( $decoded as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$sections[] = $this->normalize_builder_section( $section );
		}

		wp_send_json_success( $this->build_preview_payload( $sections ) );
	}

	/**
	 * Add frontend admin-bar launch link.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar object.
	 */
	public function add_builder_admin_bar_link( $admin_bar ) {
		if ( ! is_admin_bar_showing() || $this->is_builder_request() || is_admin() ) {
			return;
		}

		$post_id = is_singular() ? get_queried_object_id() : 0;
		if ( $post_id <= 0 || ! $this->is_builder_post_type_allowed( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$builder_url = md_page_blocks_builder_url( $post_id, wp_create_nonce( md_page_blocks_builder_nonce_action( $post_id ) ) );

		$node = array(
			'id'    => 'gt-page-blocks-builder',
			'title' => __( 'Page Blocks Builder', 'page-blocks-builder' ),
			'href'  => esc_url( $builder_url ),
			'meta'  => array(
				'title' => __( 'Open Page Blocks visual builder', 'page-blocks-builder' ),
			),
		);

		if ( $admin_bar->get_node( 'edit' ) ) {
			$node['parent'] = 'edit';
		}

		$admin_bar->add_node( $node );
	}

	/**
	 * Output Rank Math SEO integration script for Gutenberg editor.
	 */
	public function output_rankmath_integration() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return;
		}

		if ( ! in_array( $screen->post_type, md_page_blocks_builder_post_types(), true ) ) {
			return;
		}

		if ( ! class_exists( 'RankMath' ) ) {
			return;
		}
		?>
		<script>
		(function() {
			'use strict';

			function initPageBlocksRankMath() {
				if (typeof wp === 'undefined' || typeof wp.hooks === 'undefined' || typeof wp.data === 'undefined') {
					return;
				}

				function getPageBlocksContent() {
					var blocks = wp.data.select('core/block-editor').getBlocks();
					var content = '';

					function extractFromBlocks(blockList) {
						if (!blockList || !blockList.length) return;
						for (var i = 0; i < blockList.length; i++) {
							var block = blockList[i];
							if (block.name === '<?php echo esc_js( self::BLOCK_NAME ); ?>') {
								var text = (block.attributes.content || '')
									.replace(/<\?php[\s\S]*?\?>/gi, ' ')
									.replace(/<[^>]*>/g, ' ')
									.replace(/\s+/g, ' ')
									.trim();
								if (text) {
									content += ' ' + text;
								}
							}
							if (block.innerBlocks && block.innerBlocks.length) {
								extractFromBlocks(block.innerBlocks);
							}
						}
					}

					extractFromBlocks(blocks);
					return content;
				}

				wp.hooks.addFilter('rank_math_content', 'gt-page-blocks', function(existingContent) {
					var pageBlocksContent = getPageBlocksContent();
					if (typeof existingContent !== 'string') {
						existingContent = '';
					}
					return existingContent + pageBlocksContent;
				});

				var refreshTimer;
				wp.data.subscribe(function() {
					clearTimeout(refreshTimer);
					refreshTimer = setTimeout(function() {
						if (typeof rankMathEditor !== 'undefined') {
							rankMathEditor.refresh('content');
						}
					}, 2000);
				});
			}

			if (document.readyState === 'complete') {
				setTimeout(initPageBlocksRankMath, 1000);
			} else {
				window.addEventListener('load', function() {
					setTimeout(initPageBlocksRankMath, 1000);
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Output CSS for page-blocks templates.
	 */
	public function output_template_styles() {
		if ( ! is_singular() ) {
			return;
		}

		$slug = get_page_template_slug();

		if ( $slug === 'page-blocks-builder.php' ) {
			echo '<style id="gt-pb-builder-template">'
				. '.page-blocks-main{max-width:none;padding:0;margin:0;}'
				. '.entry-title,.page-title,.post-title{display:none;}'
				. '.site-content,.content-area,.entry-content{max-width:none;padding:0;margin:0;width:100%;}'
				. '</style>' . "\n";
		}

		if ( $slug === 'page-blocks-full-builder.php' ) {
			echo '<style id="gt-pb-full-builder-template">'
				. 'body.page-blocks-full-builder{margin:0;padding:0;}'
				. '.page-blocks-main{max-width:none;padding:0;margin:0;}'
				. '</style>' . "\n";
		}
	}

	/**
	 * Register page templates for Page Blocks Builder.
	 *
	 * @param array $templates Existing templates.
	 * @return array
	 */
	public function register_page_templates( $templates ) {
		$templates['page-blocks-builder.php']     = __( 'Page Blocks Builder', 'page-blocks-builder' );
		$templates['page-blocks-full-builder.php'] = __( 'Full Page Builder', 'page-blocks-builder' );
		return $templates;
	}

	/**
	 * Load plugin-provided page templates on the frontend.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_page_template( $template ) {
		if ( is_singular() ) {
			$slug = get_page_template_slug();

			if ( $slug === 'page-blocks-builder.php' ) {
				$file = GT_PB_BUILDER_DIR . 'templates/page-blocks-builder.php';
				if ( file_exists( $file ) ) {
					return $file;
				}
			}

			if ( $slug === 'page-blocks-full-builder.php' ) {
				$file = GT_PB_BUILDER_DIR . 'templates/page-blocks-full-builder.php';
				if ( file_exists( $file ) ) {
					return $file;
				}
			}
		}

		return $template;
	}

	/**
	 * Register settings option.
	 */
	public function register_settings() {
		register_setting(
			'gt_page_blocks_builder_settings',
			GT_PB_BUILDER_OPTION_POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
				'default'           => array( 'post', 'page' ),
			)
		);
	}

	/**
	 * Sanitize post-type settings.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function sanitize_post_types( $value ) {
		if ( ! is_array( $value ) ) {
			return array( 'post', 'page' );
		}

		$post_types = array();
		foreach ( $value as $post_type => $enabled ) {
			if ( is_numeric( $post_type ) ) {
				$post_type = $enabled;
				$enabled   = 1;
			}

			if ( empty( $enabled ) ) {
				continue;
			}

			$post_types[] = sanitize_key( $post_type );
		}

		$post_types = array_values( array_unique( array_filter( $post_types ) ) );
		return ! empty( $post_types ) ? $post_types : array( 'post', 'page' );
	}

	/**
	 * Add settings page.
	 */
	public function register_settings_page() {
		add_options_page(
			__( 'Page Blocks Builder', 'page-blocks-builder' ),
			__( 'Page Blocks Builder', 'page-blocks-builder' ),
			'manage_options',
			'gt-page-blocks-builder',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		$enabled = md_page_blocks_builder_post_types();
		include GT_PB_BUILDER_DIR . 'templates/settings-page.php';
	}

	/**
	 * Extract class suggestions from active + parent theme files.
	 *
	 * @return array
	 */
	private function get_theme_class_suggestions() {
		if ( $this->theme_class_suggestions !== null ) {
			return $this->theme_class_suggestions;
		}

		$files = $this->get_theme_style_files();
		$map   = array();

		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );
			if ( $contents === false ) {
				continue;
			}

			if ( preg_match_all( '/(?<![A-Za-z0-9_-])\.([A-Za-z_-][A-Za-z0-9_-]*)/', $contents, $matches ) && ! empty( $matches[1] ) ) {
				foreach ( $matches[1] as $class_name ) {
					if ( strlen( $class_name ) < 2 ) {
						continue;
					}
					$map[ $class_name ] = true;
					if ( count( $map ) >= 2000 ) {
						break 2;
					}
				}
			}
		}

		$classes = array_keys( $map );
		sort( $classes, SORT_NATURAL | SORT_FLAG_CASE );

		$this->theme_class_suggestions = $classes;
		return $this->theme_class_suggestions;
	}

	/**
	 * Gather theme style files from child and parent themes.
	 *
	 * @return array
	 */
	private function get_theme_style_files() {
		$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$files = array();

		foreach ( $dirs as $dir ) {
			if ( ! $dir || ! is_dir( $dir ) ) {
				continue;
			}

			$style_file = trailingslashit( $dir ) . 'style.css';
			if ( file_exists( $style_file ) && is_readable( $style_file ) ) {
				$files[] = $style_file;
			}

			$css_dir = trailingslashit( $dir ) . 'css';
			if ( is_dir( $css_dir ) ) {
				$css_files = $this->collect_css_files_recursive( $css_dir );
				$files     = array_merge( $files, $css_files );
			}

			$assets_css_dir = trailingslashit( $dir ) . 'assets/css';
			if ( is_dir( $assets_css_dir ) ) {
				$css_files = $this->collect_css_files_recursive( $assets_css_dir );
				$files     = array_merge( $files, $css_files );
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Recursively collect readable CSS files from a directory.
	 *
	 * @param string $base_dir Directory path.
	 * @return array
	 */
	private function collect_css_files_recursive( $base_dir ) {
		if ( ! is_dir( $base_dir ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof SplFileInfo ) {
				continue;
			}

			if ( $file_info->isFile() && strtolower( $file_info->getExtension() ) === 'css' ) {
				$path = $file_info->getPathname();
				if ( is_readable( $path ) ) {
					$files[] = $path;
				}
			}
		}

		return $files;
	}

	/**
	 * Execute PHP in block content.
	 *
	 * @param string $content Raw content.
	 * @return string
	 */
	private function execute_php( $content ) {
		if ( strpos( $content, '<?php' ) === false && strpos( $content, '<?=' ) === false ) {
			return $content;
		}

		$is_frontend = ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		$can_execute = (bool) apply_filters(
			'gt_page_blocks_can_execute_php',
			current_user_can( 'manage_options' ) || $is_frontend,
			$content
		);

		if ( ! $can_execute ) {
			return preg_replace( '/<\?(?:php|=).*?\?>/is', '', $content );
		}

		$temp_file = tempnam( sys_get_temp_dir(), 'gt_page_block_' );
		if ( ! $temp_file ) {
			return $content;
		}

		file_put_contents( $temp_file, $content );

		ob_start();
		try {
			include $temp_file;
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				echo '<!-- Page Block PHP Error: ' . esc_html( $e->getMessage() ) . ' -->';
			}
		} finally {
			@unlink( $temp_file );
		}

		return (string) ob_get_clean();
	}

	/**
	 * Sanitize CSS to strip XSS vectors.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	public static function sanitize_css( $css ) {
		$css = wp_strip_all_tags( (string) $css );
		$css = str_replace( array( 'javascript:', 'expression(', '-moz-binding:', 'behavior:' ), '', $css );
		$css = preg_replace( '/@import\s+url\s*\(\s*["\']?\s*(?:javascript|data)\s*:/i', '@import url(blocked:', $css );
		$css = preg_replace( '/url\s*\(\s*["\']?\s*data\s*:\s*text\/html/i', 'url(blocked:', $css );
		return $css;
	}

	/**
	 * Minify CSS.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function minify_css( $css ) {
		$css = (string) $css;
		$css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );
		$css = str_replace( array( "\r\n", "\r", "\n", "\t" ), '', $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([\{\};:,>~+])\s*/', '$1', $css );
		$css = preg_replace( '/;}/', '}', $css );
		return trim( (string) $css );
	}

	/**
	 * Minify JS.
	 *
	 * @param string $js JS.
	 * @return string
	 */
	public static function minify_js( $js ) {
		$js = (string) $js;
		$js = preg_replace( '#/\*(?!!).*?\*/#s', '', $js );
		$js = preg_replace( '#(?<=[\s;{}(,=])//(?!/)[^\n]*#', '', $js );
		$js = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', $js );
		$js = preg_replace( '/\s+/', ' ', $js );
		$js = preg_replace( '/\s*([{};,])\s*/', '$1', $js );
		return trim( (string) $js );
	}

	/**
	 * Minify HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function minify_html( $html ) {
		$html      = (string) $html;
		$preserved = array();

		$html = preg_replace_callback(
			'#(<(?:pre|code|script|style|textarea)\\b[^>]*>)(.*?)(</(?:pre|code|script|style|textarea)>)#si',
			function ( $matches ) use ( &$preserved ) {
				$key             = '<!--PRESERVED_' . count( $preserved ) . '-->';
				$preserved[ $key ] = $matches[0];
				return $key;
			},
			$html
		);

		$html = preg_replace( '/<!--(?!\\[if\\s).*?-->/s', '', $html );
		$html = preg_replace( '/>\\s+</', '> <', $html );
		$html = preg_replace( '/\\s+/', ' ', $html );
		$html = str_replace( array_keys( $preserved ), array_values( $preserved ), $html );

		return trim( (string) $html );
	}
}

$GLOBALS['gt_page_blocks_builder'] = new GT_Page_Blocks_Builder();

require_once GT_PB_BUILDER_DIR . 'includes/class-license-manager.php';
$gt_pb_license_manager = new GT_PB_License_Manager( GT_PB_BUILDER_FILE );
$gt_pb_license_manager->hook();
