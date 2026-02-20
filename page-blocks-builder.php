<?php
/**
 * Plugin Name: GT Page Blocks Builder
 * Plugin URI: https://gauravtiwari.org/product/gt-page-blocks-builder/
 * Description: Standalone visual Page Blocks builder with HTML/CSS/JS sections synced to Gutenberg block content.
 * Version: 1.3.0
 * Author: Gaurav Tiwari
 * Author URI: https://gauravtiwari.org
 * Text Domain: page-blocks-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GT_PB_BUILDER_VERSION' ) ) {
	define( 'GT_PB_BUILDER_VERSION', '1.3.0' );
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

	/**
	 * Whether CSS has been output to head already.
	 *
	 * @var bool
	 */
	private $css_in_head = false;

	/**
	 * Parsed blocks cache for the current request.
	 *
	 * @var array|null
	 */
	private $parsed_blocks = null;

	/**
	 * Cached upload directory info for asset files.
	 *
	 * @var array|null
	 */
	private $upload_dir_cache = null;

	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		add_filter( 'template_include', array( $this, 'builder_template_include' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_builder_assets' ), 1 );
		add_action( 'admin_bar_menu', array( $this, 'add_builder_admin_bar_link' ), 80 );

		add_action( 'wp_ajax_md_page_blocks_builder_apply', array( $this, 'ajax_builder_apply' ) );
		add_action( 'wp_ajax_md_page_blocks_builder_preview', array( $this, 'ajax_builder_preview' ) );
		add_action( 'wp_ajax_md_page_blocks_ai_generate', array( $this, 'ajax_ai_generate' ) );
		add_action( 'wp_ajax_md_page_blocks_terminal_exec', array( $this, 'ajax_terminal_exec' ) );

		add_action( 'wp_footer', array( $this, 'output_footer_scripts' ), 99 );

		add_action( 'template_redirect', array( $this, 'collect_css_for_head' ) );
		add_action( 'template_redirect', array( $this, 'collect_js_for_file' ) );

		add_action( 'save_post', array( $this, 'on_post_save' ), 20, 2 );
		add_action( 'delete_post', array( $this, 'on_post_delete' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );

		add_action( 'admin_footer', array( $this, 'output_rankmath_integration' ) );

		if ( ! wp_is_block_theme() ) {
			add_filter( 'theme_page_templates', array( $this, 'register_page_templates' ) );
			add_filter( 'template_include', array( $this, 'load_page_template' ) );
			add_action( 'wp_head', array( $this, 'output_template_styles' ) );
		}

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
					'output'     => array( 'type' => 'string', 'default' => 'inline' ),
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
		$attributes   = is_array( $attributes ) ? $attributes : array();
		$content      = isset( $attributes['content'] ) ? (string) $attributes['content'] : '';
		$css          = isset( $attributes['css'] ) ? (string) $attributes['css'] : '';
		$js           = isset( $attributes['js'] ) ? (string) $attributes['js'] : '';
		$js_loc       = isset( $attributes['jsLocation'] ) && $attributes['jsLocation'] === 'inline' ? 'inline' : 'footer';
		$output_mode  = isset( $attributes['output'] ) ? $attributes['output'] : 'inline';
		$format       = ! empty( $attributes['format'] );
		$php_exec     = ! empty( $attributes['phpExec'] );
		$is_file_mode = $output_mode === 'file';
		$output       = '';

		if ( $css !== '' && ! $is_file_mode && ! $this->css_in_head ) {
			$output .= '<style>' . self::minify_css( self::sanitize_css( $css ) ) . '</style>' . "\n";
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

		if ( $js !== '' && ! $is_file_mode ) {
			$js       = self::minify_js( $js );
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

		$combined = implode( ';', $this->footer_scripts );
		echo '<script>' . $combined . '</script>' . "\n";

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
		if ( wp_is_block_theme() ) {
			return;
		}

		$current = get_page_template_slug( $post_id );

		if ( ! empty( $current ) && $current !== 'default' ) {
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
				'viewPostUrl'        => get_permalink( $post_id ) ?: '',
				'initialSections'    => $this->get_builder_sections_from_post( $post_id ),
				'postTemplate'       => $this->get_builder_post_template_slug( $post_id ),
				'availableTemplates' => $this->get_available_page_templates( $post_id ),
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
				'aiEndpoint'      => admin_url( 'admin-ajax.php' ),
				'aiAction'        => 'md_page_blocks_ai_generate',
				'aiDefaultModel'  => get_option( 'gt_pb_ai_default_model', 'gpt-5.2' ),
				'aiHasOpenAI'     => ! empty( get_option( 'gt_pb_ai_openai_key', '' ) ),
				'aiHasAnthropic'  => ! empty( get_option( 'gt_pb_ai_anthropic_key', '' ) ),
				'aiHasGemini'     => ! empty( get_option( 'gt_pb_ai_gemini_key', '' ) ),
				'aiModels'        => array(
					array( 'id' => 'gpt-5.2', 'label' => 'GPT-5.2', 'provider' => 'openai' ),
					array( 'id' => 'gpt-5-mini', 'label' => 'GPT-5 Mini', 'provider' => 'openai' ),
					array( 'id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'provider' => 'openai' ),
					array( 'id' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6', 'provider' => 'anthropic' ),
					array( 'id' => 'claude-opus-4-6', 'label' => 'Claude Opus 4.6', 'provider' => 'anthropic' ),
					array( 'id' => 'claude-haiku-4-5-20241022', 'label' => 'Claude Haiku 4.5', 'provider' => 'anthropic' ),
					array( 'id' => 'gemini-3-flash-preview', 'label' => 'Gemini 3 Flash', 'provider' => 'gemini' ),
				),
				'terminalEnabled' => (bool) get_option( 'gt_pb_terminal_enabled', false ),
				'terminalAction'  => 'md_page_blocks_terminal_exec',
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

	private function get_available_page_templates( $post_id ) {
		$post = get_post( $post_id );
		$post_type = $post ? $post->post_type : 'page';

		$wp_templates = wp_get_theme()->get_page_templates( $post, $post_type );

		$templates = array(
			array( 'slug' => 'default-template', 'label' => 'Default Template' ),
		);

		foreach ( $wp_templates as $slug => $label ) {
			$templates[] = array( 'slug' => $slug, 'label' => $label );
		}

		return $templates;
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
		$output      = isset( $section['output'] ) && $section['output'] === 'file' ? 'file' : 'inline';
		$content     = isset( $section['content'] ) ? (string) $section['content'] : '';
		$css         = isset( $section['css'] ) ? (string) $section['css'] : '';
		$js          = isset( $section['js'] ) ? (string) $section['js'] : '';

		return array(
			'content'    => $this->decode_builder_unicode_sequences( $content ),
			'css'        => $this->decode_builder_unicode_sequences( $css ),
			'js'         => $this->decode_builder_unicode_sequences( $js ),
			'jsLocation' => $js_location,
			'output'     => $output,
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

		$raw_sections  = isset( $_POST['sections'] ) ? wp_unslash( $_POST['sections'] ) : '';
		$page_template = isset( $_POST['page_template'] ) ? sanitize_text_field( wp_unslash( $_POST['page_template'] ) ) : '';
		$decoded       = json_decode( (string) $raw_sections, true );
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

		$update_args = array(
			'ID'           => $post_id,
			'post_content' => wp_slash( serialize_blocks( $next_blocks ) ),
		);

		$updated = wp_update_post( $update_args, true );

		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ), 500 );
		}

		if ( ! empty( $page_template ) ) {
			if ( $page_template === 'default-template' ) {
				delete_post_meta( $post_id, '_wp_page_template' );
			} else {
				update_post_meta( $post_id, '_wp_page_template', sanitize_file_name( $page_template ) );
			}
		} else {
			$this->maybe_set_builder_template( $post_id );
		}

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

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_ai_openai_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_ai_anthropic_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_ai_gemini_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_ai_default_model', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_ai_model' ),
			'default'           => 'gpt-5.2',
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_terminal_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
	}

	public function sanitize_ai_model( $value ) {
		$allowed = array(
			'gpt-5.2', 'gpt-5-mini', 'gpt-4o-mini',
			'claude-sonnet-4-6', 'claude-opus-4-6', 'claude-haiku-4-5-20241022',
			'gemini-3-flash-preview',
		);

		return in_array( $value, $allowed, true ) ? $value : 'gpt-5.2';
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

		$cache_parts = array( get_stylesheet() );
		foreach ( $files as $file ) {
			$cache_parts[] = $file . ':' . (string) filemtime( $file );
		}
		$cache_key = 'gt_pb_cls_' . md5( implode( '|', $cache_parts ) );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$this->theme_class_suggestions = $cached;
			return $this->theme_class_suggestions;
		}

		$map = array();

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

		set_transient( $cache_key, $classes, DAY_IN_SECONDS );

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
	 * Get parsed blocks for the current singular request, cached.
	 *
	 * @return array|null
	 */
	private function get_parsed_blocks() {
		if ( $this->parsed_blocks !== null ) {
			return $this->parsed_blocks;
		}

		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		if ( ! $post || ! isset( $post->post_content ) || ! has_blocks( $post->post_content ) ) {
			return null;
		}

		$this->parsed_blocks = parse_blocks( $post->post_content );
		return $this->parsed_blocks;
	}

	/**
	 * Parse post content early and output all Page Block CSS in <head>.
	 */
	public function collect_css_for_head() {
		$blocks = $this->get_parsed_blocks();
		if ( $blocks === null ) {
			return;
		}

		$inline_parts = array();
		$file_parts   = array();

		foreach ( self::find_page_blocks( $blocks ) as $block ) {
			$css = $block['attrs']['css'] ?? '';
			if ( ! $css ) {
				continue;
			}

			$output = $block['attrs']['output'] ?? 'inline';
			if ( $output === 'file' ) {
				$file_parts[] = self::sanitize_css( $css );
			} else {
				$inline_parts[] = self::sanitize_css( $css );
			}
		}

		if ( empty( $inline_parts ) && empty( $file_parts ) ) {
			return;
		}

		$this->css_in_head = true;

		$post    = get_queried_object();
		$post_id = $post->ID;

		if ( ! empty( $file_parts ) ) {
			if ( ! $this->css_file_exists( $post_id, 'gb-' ) ) {
				$this->generate_file( $post_id, 'gb-', 'css', $file_parts );
			}

			$that = $this;
			add_action( 'wp_head', function() use ( $that, $post_id ) {
				$that->enqueue_asset_file( $post_id, 'gb-', 'css' );
			}, 99 );
		}

		if ( ! empty( $inline_parts ) ) {
			$combined = self::minify_css( implode( "\n", $inline_parts ) );

			add_action( 'wp_head', function() use ( $combined ) {
				echo '<style id="gt-page-block-css">' . $combined . '</style>' . "\n";
			}, 99 );
		}
	}

	/**
	 * Parse post content early and collect all Page Block JS for external file output.
	 */
	public function collect_js_for_file() {
		$blocks = $this->get_parsed_blocks();
		if ( $blocks === null ) {
			return;
		}

		$js_parts = array();

		foreach ( self::find_page_blocks( $blocks ) as $block ) {
			$output = $block['attrs']['output'] ?? 'inline';
			if ( $output !== 'file' ) {
				continue;
			}

			$js = $block['attrs']['js'] ?? '';
			if ( $js ) {
				$js_parts[] = $js;
			}
		}

		if ( empty( $js_parts ) ) {
			return;
		}

		$post    = get_queried_object();
		$post_id = $post->ID;

		if ( ! $this->css_file_exists( $post_id, 'gb-', 'js' ) ) {
			$this->generate_file( $post_id, 'gb-', 'js', $js_parts );
		}

		$that = $this;
		add_action( 'wp_footer', function() use ( $that, $post_id ) {
			$that->enqueue_asset_file( $post_id, 'gb-', 'js' );
		}, 99 );
	}

	/**
	 * Get the uploads directory for page blocks asset files.
	 *
	 * @return array Array with 'path' and 'url' keys.
	 */
	private function get_upload_dir() {
		if ( $this->upload_dir_cache !== null ) {
			return $this->upload_dir_cache;
		}

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/gt-page-blocks';
		$url        = $upload_dir['baseurl'] . '/gt-page-blocks';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$wp_filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.", FS_CHMOD_FILE );
		}

		$this->upload_dir_cache = array(
			'path' => $dir,
			'url'  => $url,
		);

		return $this->upload_dir_cache;
	}

	/**
	 * Get asset file info for a specific post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $prefix    File prefix (e.g. '' or 'gb-').
	 * @param string $extension File extension.
	 * @return array Array with 'path' and 'url' keys.
	 */
	private function get_asset_file_info( $post_id, $prefix = '', $extension = 'css' ) {
		$dir      = $this->get_upload_dir();
		$filename = 'page-blocks-' . $prefix . $post_id . '.' . $extension;

		return array(
			'path' => $dir['path'] . '/' . $filename,
			'url'  => $dir['url'] . '/' . $filename,
		);
	}

	/**
	 * Check if an asset file exists.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $prefix    File prefix.
	 * @param string $extension File extension.
	 * @return bool
	 */
	private function css_file_exists( $post_id, $prefix = '', $extension = 'css' ) {
		$info = $this->get_asset_file_info( $post_id, $prefix, $extension );
		return file_exists( $info['path'] );
	}

	/**
	 * Delete an asset file.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $prefix    File prefix.
	 * @param string $extension File extension.
	 * @return bool
	 */
	private function delete_asset_file( $post_id, $prefix = '', $extension = 'css' ) {
		$info = $this->get_asset_file_info( $post_id, $prefix, $extension );
		if ( file_exists( $info['path'] ) ) {
			return @unlink( $info['path'] );
		}
		return false;
	}

	/**
	 * Write content to an asset file.
	 *
	 * @param string $path    File path.
	 * @param string $content File content.
	 * @return bool
	 */
	private function write_asset_file( $path, $content ) {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return (bool) $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
	}

	/**
	 * Generate and save a minified asset file.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $prefix    File prefix.
	 * @param string $extension 'css' or 'js'.
	 * @param array  $parts     Array of code strings.
	 * @return bool
	 */
	private function generate_file( $post_id, $prefix, $extension, $parts ) {
		if ( empty( $parts ) ) {
			$this->delete_asset_file( $post_id, $prefix, $extension );
			return false;
		}

		$separator = $extension === 'js' ? ";\n" : "\n";
		$combined  = implode( $separator, $parts );
		$minified  = $extension === 'js' ? self::minify_js( $combined ) : self::minify_css( $combined );
		$info      = $this->get_asset_file_info( $post_id, $prefix, $extension );

		return $this->write_asset_file( $info['path'], $minified );
	}

	/**
	 * Enqueue an external asset file via HTML tag.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $prefix    File prefix.
	 * @param string $extension 'css' or 'js'.
	 */
	public function enqueue_asset_file( $post_id, $prefix = '', $extension = 'css' ) {
		$info = $this->get_asset_file_info( $post_id, $prefix, $extension );
		if ( ! file_exists( $info['path'] ) ) {
			return;
		}

		$version = filemtime( $info['path'] );
		$id      = 'gt-page-blocks-' . $prefix . esc_attr( $post_id );

		if ( $extension === 'js' ) {
			echo '<script src="' . esc_url( $info['url'] ) . '?ver=' . esc_attr( $version ) . '"></script>' . "\n";
		} else {
			echo '<link rel="stylesheet" id="' . $id . '" href="' . esc_url( $info['url'] ) . '?ver=' . esc_attr( $version ) . '" media="all" />' . "\n";
		}
	}

	/**
	 * Handle post save - regenerate external asset files.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_post_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, md_page_blocks_builder_post_types(), true ) ) {
			return;
		}

		if ( ! has_blocks( $post->post_content ) ) {
			$this->delete_asset_file( $post_id, 'gb-', 'css' );
			$this->delete_asset_file( $post_id, 'gb-', 'js' );
			return;
		}

		$blocks    = parse_blocks( $post->post_content );
		$css_parts = array();
		$js_parts  = array();

		foreach ( self::find_page_blocks( $blocks ) as $block ) {
			$output = $block['attrs']['output'] ?? 'inline';
			if ( $output !== 'file' ) {
				continue;
			}

			$css = $block['attrs']['css'] ?? '';
			if ( $css ) {
				$css_parts[] = self::sanitize_css( $css );
			}

			$js = $block['attrs']['js'] ?? '';
			if ( $js ) {
				$js_parts[] = $js;
			}
		}

		if ( ! empty( $css_parts ) ) {
			$this->generate_file( $post_id, 'gb-', 'css', $css_parts );
		} else {
			$this->delete_asset_file( $post_id, 'gb-', 'css' );
		}

		if ( ! empty( $js_parts ) ) {
			$this->generate_file( $post_id, 'gb-', 'js', $js_parts );
		} else {
			$this->delete_asset_file( $post_id, 'gb-', 'js' );
		}
	}

	/**
	 * Handle post delete - remove all asset files.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_post_delete( $post_id ) {
		$this->delete_asset_file( $post_id, 'gb-', 'css' );
		$this->delete_asset_file( $post_id, 'gb-', 'js' );
	}

	/**
	 * Sanitize CSS to strip XSS vectors.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	public static function sanitize_css( $css ) {
		$css = (string) $css;
		$css = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $css );
		$css = preg_replace( '/<[a-z\/!][^>]*>/i', '', $css );
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
		$css = preg_replace( '/\s*([\{\};:,~+])\s*/', '$1', $css );
		$css = preg_replace( '/\s*>(?!=)\s*/', '>', $css );
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

		$html = preg_replace( '/<!--(?!\\[if\\s|PRESERVED_).*?-->/s', '', $html );
		$html = preg_replace( '/>\\s+</', '> <', $html );
		$html = preg_replace( '/\\s+/', ' ', $html );
		$html = str_replace( array_keys( $preserved ), array_values( $preserved ), $html );

		return trim( (string) $html );
	}

	public function ajax_ai_generate() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Authentication required.', 'page-blocks-builder' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$nonce   = isset( $_POST['pb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pb_nonce'] ) ) : '';

		if ( ! $this->can_access_builder( $post_id, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'page-blocks-builder' ) ), 403 );
		}

		$prompt    = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$tab       = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'html';
		$model     = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$existing  = isset( $_POST['existing_code'] ) ? wp_unslash( $_POST['existing_code'] ) : '';
		$selection = isset( $_POST['selection'] ) ? wp_unslash( $_POST['selection'] ) : '';
		$ctx_html  = isset( $_POST['context_html'] ) ? wp_unslash( $_POST['context_html'] ) : '';
		$ctx_css   = isset( $_POST['context_css'] ) ? wp_unslash( $_POST['context_css'] ) : '';
		$page_url  = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';

		if ( empty( $prompt ) ) {
			wp_send_json_error( array( 'message' => __( 'Prompt is required.', 'page-blocks-builder' ) ), 400 );
		}

		if ( ! in_array( $tab, array( 'html', 'css', 'js' ), true ) ) {
			$tab = 'html';
		}

		$result = $this->call_ai_api( $model, $tab, $prompt, $existing, $selection, $ctx_html, $ctx_css, $page_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'code' => $result ) );
	}

	private function call_ai_api( $model, $tab, $prompt, $existing, $selection, $ctx_html, $ctx_css, $page_url ) {
		if ( empty( $model ) ) {
			$model = get_option( 'gt_pb_ai_default_model', 'gpt-5.2' );
		}

		$allowed = array(
			'gpt-5.2', 'gpt-5-mini', 'gpt-4o-mini',
			'claude-sonnet-4-6', 'claude-opus-4-6', 'claude-haiku-4-5-20241022',
			'gemini-3-flash-preview',
		);
		if ( ! in_array( $model, $allowed, true ) ) {
			$model = 'gpt-5.2';
		}

		if ( strpos( $model, 'gpt' ) === 0 ) {
			$provider = 'openai';
			$api_key  = get_option( 'gt_pb_ai_openai_key', '' );
		} elseif ( strpos( $model, 'claude' ) === 0 ) {
			$provider = 'anthropic';
			$api_key  = get_option( 'gt_pb_ai_anthropic_key', '' );
		} elseif ( strpos( $model, 'gemini' ) === 0 ) {
			$provider = 'gemini';
			$api_key  = get_option( 'gt_pb_ai_gemini_key', '' );
		} else {
			return new WP_Error( 'invalid_model', __( 'Unknown model.', 'page-blocks-builder' ) );
		}

		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_key', sprintf(
				__( 'No API key configured for %s. Add it in Settings > Page Blocks Builder.', 'page-blocks-builder' ),
				ucfirst( $provider )
			) );
		}

		$system_prompt = $this->get_ai_system_prompt( $tab, $page_url );
		$user_message  = $this->build_ai_user_message( $prompt, $tab, $existing, $selection, $ctx_html, $ctx_css );

		switch ( $provider ) {
			case 'openai':
				return $this->call_openai( $api_key, $model, $system_prompt, $user_message );
			case 'anthropic':
				return $this->call_anthropic( $api_key, $model, $system_prompt, $user_message );
			case 'gemini':
				return $this->call_gemini( $api_key, $model, $system_prompt, $user_message );
			default:
				return new WP_Error( 'invalid_provider', __( 'Invalid provider.', 'page-blocks-builder' ) );
		}
	}

	private function get_ai_system_prompt( $tab, $page_url ) {
		$base = 'You are generating code for a standalone WordPress Page Block section. Each section has its own HTML, CSS, and JS tabs. A page can have multiple sections. Your output goes directly into one tab of one section.';
		if ( ! empty( $page_url ) ) {
			$base .= "\nThe page this code appears on is: " . $page_url;
		}

		switch ( $tab ) {
			case 'html':
				$base .= "\n\nHTML TAB RULES:\n- Generate section-level HTML only (the content inside a single section).\n- Use semantic elements with descriptive class names.\n- No <!DOCTYPE>, <html>, <head>, <body>, <style>, or <script> tags.\n- No boilerplate. Just the section content markup.";
				break;
			case 'css':
				$base .= "\n\nCSS TAB RULES:\n- Generate CSS rules that target ONLY classes present in the HTML context provided.\n- No <style> tags. No unused selectors. No generic resets or normalizations.\n- Every rule must style an element that exists in this section's HTML.\n- Use the class names from the HTML context exactly as written.";
				break;
			case 'js':
				$base .= "\n\nJS TAB RULES:\n- Generate vanilla JavaScript only. No <script> tags.\n- Wrap in an IIFE if declaring variables to avoid global scope pollution.\n- Target elements using the class names from this section's HTML context.\n- No jQuery unless explicitly requested.";
				break;
		}

		$base .= "\n\nOutput raw code only. No markdown fences, no explanations, no commentary.";

		return $base;
	}

	private function build_ai_user_message( $prompt, $tab, $existing, $selection, $ctx_html, $ctx_css ) {
		$parts = array();

		if ( ! empty( $selection ) ) {
			$parts[] = "Modify the following selected code. Output the complete modified code only.\n\nSelected code:\n" . $selection;
		} elseif ( ! empty( $existing ) ) {
			$parts[] = "Here is the current code in this section's " . strtoupper( $tab ) . " tab. Edit it based on my instruction below. Output the complete modified code only.\n\nExisting code:\n" . $existing;
		}

		if ( $tab !== 'html' && ! empty( $ctx_html ) ) {
			$parts[] = "This section's HTML (style only these elements and classes):\n" . $ctx_html;
		}
		if ( $tab !== 'css' && ! empty( $ctx_css ) ) {
			$parts[] = "This section's CSS (for reference):\n" . $ctx_css;
		}

		$parts[] = "Instruction: " . $prompt;

		return implode( "\n\n", $parts );
	}

	private function call_openai( $api_key, $model, $system_prompt, $user_message ) {
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'       => $model,
				'messages'    => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user', 'content' => $user_message ),
				),
				'temperature'           => 0.3,
				'max_completion_tokens' => 4096,
			) ),
		) );

		return $this->parse_ai_response( $response, 'openai' );
	}

	private function call_anthropic( $api_key, $model, $system_prompt, $user_message ) {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 60,
			'headers' => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'       => $model,
				'system'      => $system_prompt,
				'messages'    => array(
					array( 'role' => 'user', 'content' => $user_message ),
				),
				'max_tokens'  => 4096,
				'temperature' => 0.3,
			) ),
		) );

		return $this->parse_ai_response( $response, 'anthropic' );
	}

	private function call_gemini( $api_key, $model, $system_prompt, $user_message ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

		$response = wp_remote_post( $url, array(
			'timeout' => 60,
			'headers' => array(
				'x-goog-api-key' => $api_key,
				'Content-Type'   => 'application/json',
			),
			'body' => wp_json_encode( array(
				'system_instruction' => array(
					'parts' => array( array( 'text' => $system_prompt ) ),
				),
				'contents' => array(
					array( 'parts' => array( array( 'text' => $user_message ) ) ),
				),
				'generationConfig' => array(
					'temperature'    => 0.3,
					'maxOutputTokens' => 4096,
				),
			) ),
		) );

		return $this->parse_ai_response( $response, 'gemini' );
	}

	private function parse_ai_response( $response, $provider ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$error_msg = '';
			if ( is_array( $body ) ) {
				if ( isset( $body['error']['message'] ) ) {
					$error_msg = $body['error']['message'];
				} elseif ( isset( $body['error']['type'] ) ) {
					$error_msg = $body['error']['type'];
				}
			}
			return new WP_Error( 'api_error', $error_msg ?: sprintf( 'API returned HTTP %d', $code ) );
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid API response.', 'page-blocks-builder' ) );
		}

		$text = '';

		switch ( $provider ) {
			case 'openai':
				$text = $body['choices'][0]['message']['content'] ?? '';
				break;
			case 'anthropic':
				$text = $body['content'][0]['text'] ?? '';
				break;
			case 'gemini':
				$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
				break;
		}

		$text = trim( $text );
		$text = preg_replace( '/^```[a-z]*\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		return $text;
	}

	public function ajax_terminal_exec() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Authentication required.', 'page-blocks-builder' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$nonce   = isset( $_POST['pb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pb_nonce'] ) ) : '';

		if ( ! $this->can_access_builder( $post_id, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'page-blocks-builder' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Admin access required.', 'page-blocks-builder' ) ), 403 );
		}

		if ( ! get_option( 'gt_pb_terminal_enabled', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Terminal is not enabled.', 'page-blocks-builder' ) ), 403 );
		}

		$command = isset( $_POST['command'] ) ? wp_unslash( $_POST['command'] ) : '';
		$cwd     = isset( $_POST['cwd'] ) && ! empty( $_POST['cwd'] ) ? wp_unslash( $_POST['cwd'] ) : ABSPATH;

		if ( empty( $command ) ) {
			wp_send_json_error( array( 'message' => __( 'No command provided.', 'page-blocks-builder' ) ), 400 );
		}

		if ( ! is_dir( $cwd ) ) {
			$cwd = ABSPATH;
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to execute command.', 'page-blocks-builder' ) ), 500 );
		}

		fclose( $pipes[0] );

		stream_set_timeout( $pipes[1], 30 );
		stream_set_timeout( $pipes[2], 30 );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		wp_send_json_success( array(
			'output'    => (string) $stdout,
			'error'     => (string) $stderr,
			'exit_code' => $exit_code,
			'cwd'       => $cwd,
		) );
	}
}

$GLOBALS['gt_page_blocks_builder'] = new GT_Page_Blocks_Builder();

require_once GT_PB_BUILDER_DIR . 'includes/class-license-manager.php';
$gt_pb_license_manager = new GT_PB_License_Manager( GT_PB_BUILDER_FILE );
$gt_pb_license_manager->hook();
