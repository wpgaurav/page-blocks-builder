<?php
/**
 * Plugin Name: GT Page Blocks Builder
 * Plugin URI: https://gauravtiwari.org/product/gt-page-blocks-builder/
 * Description: Standalone visual Page Blocks builder with HTML/CSS/JS sections synced to Gutenberg block content.
 * Version: 2.8.0
 * Author: Gaurav Tiwari
 * Author URI: https://gauravtiwari.org
 * Text Domain: page-blocks-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GT_PB_BUILDER_VERSION' ) ) {
	define( 'GT_PB_BUILDER_VERSION', '2.8.0' );
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

if ( ! function_exists( 'gt_page_blocks_builder_post_types' ) ) {
	/**
	 * Get allowed post types for builder mode.
	 *
	 * @return array
	 */
	function gt_page_blocks_builder_post_types() {
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

		// Renamed to the gt_ prefix in 2.7.4. The old name still runs first, so
		// a site that hooked it keeps working; the current filter gets the last
		// word. apply_filters_deprecated() is silent unless something is
		// actually listening on the old name.
		$post_types = apply_filters_deprecated(
			'md_page_blocks_builder_post_types',
			array( $post_types ),
			'2.7.4',
			'gt_page_blocks_builder_post_types'
		);

		/**
		 * Post types the visual builder is offered on.
		 *
		 * @since 2.7.4 Renamed from md_page_blocks_builder_post_types.
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		$post_types = apply_filters( 'gt_page_blocks_builder_post_types', $post_types );
		if ( ! is_array( $post_types ) ) {
			return $defaults;
		}

		$post_types = array_map( 'sanitize_key', $post_types );
		$post_types = array_filter( $post_types );
		$post_types = array_values( array_unique( $post_types ) );

		return ! empty( $post_types ) ? $post_types : $defaults;
	}
}

if ( ! function_exists( 'gt_page_blocks_builder_nonce_action' ) ) {
	/**
	 * Builder nonce action.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function gt_page_blocks_builder_nonce_action( $post_id ) {
		// The returned string is deliberately unchanged. It identifies nonces
		// already issued into open builder tabs and saved URLs; renaming it
		// would invalidate every one of them for no gain.
		return 'md_page_blocks_builder_' . absint( $post_id );
	}
}

if ( ! function_exists( 'gt_page_blocks_preview_nonce_action' ) ) {
	/**
	 * Preview nonce action.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function gt_page_blocks_preview_nonce_action( $post_id ) {
		return 'md_page_blocks_preview_' . absint( $post_id );
	}
}

if ( ! function_exists( 'gt_pb_get_positions' ) ) {
	/**
	 * Available position hooks for library page blocks.
	 *
	 * Theme-independent — uses WordPress core action hooks. Themes can
	 * extend via the `gt_pb_positions` filter.
	 *
	 * @return array<string,string>
	 */
	function gt_pb_get_positions(): array {
		$positions = array(
			''                       => __( 'None (shortcode only)', 'page-blocks-builder' ),
			'wp_head'                => __( 'wp_head', 'page-blocks-builder' ),
			'wp_body_open'           => __( 'wp_body_open (after <body>)', 'page-blocks-builder' ),
			'wp_footer'              => __( 'wp_footer (before </body>)', 'page-blocks-builder' ),
			'the_content_before'     => __( 'Before The Content', 'page-blocks-builder' ),
			'the_content_after'      => __( 'After The Content', 'page-blocks-builder' ),
			'loop_start'             => __( 'Loop Start', 'page-blocks-builder' ),
			'loop_end'               => __( 'Loop End', 'page-blocks-builder' ),
			'get_header'             => __( 'get_header', 'page-blocks-builder' ),
			'get_footer'             => __( 'get_footer', 'page-blocks-builder' ),
			'get_sidebar'            => __( 'get_sidebar', 'page-blocks-builder' ),
		);

		return apply_filters( 'gt_pb_positions', $positions );
	}
}

if ( ! function_exists( 'gt_pb_php_enabled' ) ) {
	/**
	 * Whether PHP execution in page blocks is switched on for this site.
	 *
	 * Opt-in via wp-config.php. `MD_ALLOW_PHP_SNIPPETS` is honoured as well
	 * so sites migrating off the Marketers Delight dropin keep working
	 * without editing wp-config first.
	 *
	 * @since 2.7.0
	 */
	function gt_pb_php_enabled(): bool {
		return ( defined( 'GT_PB_ALLOW_PHP' ) && GT_PB_ALLOW_PHP )
			|| ( defined( 'MD_ALLOW_PHP_SNIPPETS' ) && MD_ALLOW_PHP_SNIPPETS );
	}
}

if ( ! function_exists( 'gt_pb_inline_php_enabled' ) ) {
	/**
	 * Whether PHP may run inside inline (post_content) blocks.
	 *
	 * Separate from gt_pb_php_enabled(): inline content carries no
	 * save-time checksum, so it needs its own explicit opt-in. Mirrors the
	 * dropin's MD_ALLOW_INLINE_PHP gate.
	 *
	 * @since 2.7.0
	 */
	function gt_pb_inline_php_enabled(): bool {
		return gt_pb_php_enabled()
			&& ( ( defined( 'GT_PB_ALLOW_INLINE_PHP' ) && GT_PB_ALLOW_INLINE_PHP )
				|| ( defined( 'MD_ALLOW_INLINE_PHP' ) && MD_ALLOW_INLINE_PHP ) );
	}
}

if ( ! function_exists( 'gt_pb_execute_php' ) ) {
	/**
	 * Execute PHP in page block content.
	 *
	 * Two independent gates, both required:
	 *
	 *   1. The site opts in with GT_PB_ALLOW_PHP (or MD_ALLOW_PHP_SNIPPETS).
	 *   2. The caller supplies the content checksum recorded at save time.
	 *      Any DB-only mutation of the content invalidates the checksum and
	 *      falls back to stripping PHP tags, so a write that reaches the row
	 *      without going through the editor cannot become executable code.
	 *
	 * Inline blocks have no library row and therefore no stored checksum, so
	 * they pass an empty one (never executed) unless the site also sets
	 * GT_PB_ALLOW_INLINE_PHP — see gt_pb_inline_php_enabled().
	 *
	 * @param string $content  Content with PHP tags.
	 * @param string $checksum md5 of $content as recorded at save time. Empty
	 *                         disables execution.
	 * @return string Executed content, or content with PHP tags stripped.
	 */
	function gt_pb_execute_php( string $content, string $checksum = '' ): string {
		if ( strpos( $content, '<?php' ) === false && strpos( $content, '<?=' ) === false ) {
			return $content;
		}

		$gate_default = gt_pb_php_enabled()
			&& '' !== $checksum
			&& hash_equals( $checksum, md5( $content ) );

		// The filter receives the conservative default. A site can override it
		// in either direction with full context, but the default is
		// constant + checksum.
		$can_execute = (bool) apply_filters(
			'gt_pb_can_execute_php',
			$gate_default,
			$content,
			$checksum
		);

		if ( ! $can_execute ) {
			return preg_replace( '/<\?(?:php|=).*?\?>/is', '', $content );
		}

		$temp_file = tempnam( sys_get_temp_dir(), 'gt_pb_' );
		if ( ! $temp_file ) {
			return $content;
		}

		file_put_contents( $temp_file, $content );

		ob_start();
		try {
			include $temp_file;
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				echo '<!-- Page Blocks PHP Error: ' . esc_html( $e->getMessage() ) . ' -->';
			}
		} finally {
			unlink( $temp_file );
		}

		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'gt_page_blocks_builder_url' ) ) {
	/**
	 * Build frontend visual builder URL.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $nonce   Nonce.
	 * @return string
	 */
	function gt_page_blocks_builder_url( $post_id, $nonce = '' ) {
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

/**
 * Compatibility shims for the pre-2.7.4 function names.
 *
 * These four helpers were named md_page_blocks_* after the theme this plugin
 * grew out of. The implementations now live under gt_, and these wrappers keep
 * any site or snippet calling the old names working. Each raises a pointer to
 * its replacement under WP_DEBUG and is otherwise silent.
 *
 * Nothing inside the plugin calls these — they exist only for callers outside
 * it, so removing them later costs nothing internally.
 */

if ( ! function_exists( 'md_page_blocks_builder_post_types' ) ) {
	/**
	 * @deprecated 2.7.4 Use gt_page_blocks_builder_post_types().
	 * @return array
	 */
	function md_page_blocks_builder_post_types() {
		_deprecated_function( __FUNCTION__, '2.7.4', 'gt_page_blocks_builder_post_types' );

		return gt_page_blocks_builder_post_types();
	}
}

if ( ! function_exists( 'md_page_blocks_builder_nonce_action' ) ) {
	/**
	 * @deprecated 2.7.4 Use gt_page_blocks_builder_nonce_action().
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function md_page_blocks_builder_nonce_action( $post_id ) {
		_deprecated_function( __FUNCTION__, '2.7.4', 'gt_page_blocks_builder_nonce_action' );

		return gt_page_blocks_builder_nonce_action( $post_id );
	}
}

if ( ! function_exists( 'md_page_blocks_preview_nonce_action' ) ) {
	/**
	 * @deprecated 2.7.4 Use gt_page_blocks_preview_nonce_action().
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function md_page_blocks_preview_nonce_action( $post_id ) {
		_deprecated_function( __FUNCTION__, '2.7.4', 'gt_page_blocks_preview_nonce_action' );

		return gt_page_blocks_preview_nonce_action( $post_id );
	}
}

if ( ! function_exists( 'md_page_blocks_builder_url' ) ) {
	/**
	 * @deprecated 2.7.4 Use gt_page_blocks_builder_url().
	 * @param int    $post_id Post ID.
	 * @param string $nonce   Nonce.
	 * @return string
	 */
	function md_page_blocks_builder_url( $post_id, $nonce = '' ) {
		_deprecated_function( __FUNCTION__, '2.7.4', 'gt_page_blocks_builder_url' );

		return gt_page_blocks_builder_url( $post_id, $nonce );
	}
}

class GT_Page_Blocks_Builder {
	const BLOCK_NAME = 'gt-page-block/page-block';

	/** Ceiling on the whole-page code the assistant is handed, in bytes. */
	const AI_PAGE_CONTEXT_LIMIT = 60000;

	/**
	 * Pre-2.6.0 block name. Stays registered server-side so un-migrated
	 * content keeps rendering; run `wp gt-pb migrate-blocks` (or
	 * Settings -> Tools) to rewrite stored content to BLOCK_NAME.
	 */
	const LEGACY_BLOCK_NAME = 'marketers-delight/page-block';

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
	 * Cached theme CSS custom-property names.
	 *
	 * @var array<int,string>|null
	 */
	private $theme_css_variables = null;

	/**
	/**
	 * Library block IDs whose CSS has already been emitted this request.
	 *
	 * A referenced library block can appear many times on one page; its CSS
	 * belongs in the output once, not once per placement.
	 *
	 * @var array<int,bool>
	 */
	private $library_css_done = array();

	/**
	 * Hashes of inline-block CSS already emitted this request.
	 *
	 * Keyed by the CSS itself rather than by a global "did we write a <head>
	 * block" flag: collect_css_for_head() only scans the queried post, so a
	 * block rendered from anywhere else (a widget shortcode, a synced
	 * pattern, a template part) would otherwise be silenced by a flag set on
	 * its behalf and lose its styles entirely.
	 *
	 * @var array<string,bool>
	 */
	private $inline_css_done = array();

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

	/**
	 * Database layer for reusable Page Blocks (shortcode-driven library).
	 *
	 * @var gt_pb_db|null
	 */
	public $db = null;

	public function __construct() {
		// Load includes
		require_once GT_PB_BUILDER_DIR . 'includes/class-db.php';
		require_once GT_PB_BUILDER_DIR . 'includes/class-shortcode.php';
		require_once GT_PB_BUILDER_DIR . 'includes/class-list-table.php';
		require_once GT_PB_BUILDER_DIR . 'includes/class-css-loader.php';
		require_once GT_PB_BUILDER_DIR . 'includes/class-rest-api.php';
		require_once GT_PB_BUILDER_DIR . 'includes/class-theme-builder.php';
		require_once GT_PB_BUILDER_DIR . 'includes/class-migration.php';

		$this->db = new gt_pb_db();
		gt_pb_css_loader::init();

		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ), 10, 1 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		add_filter( 'template_include', array( $this, 'builder_template_include' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_builder_assets' ), 1 );
		add_action( 'admin_bar_menu', array( $this, 'add_builder_admin_bar_link' ), 80 );

		add_action( 'wp_ajax_md_page_blocks_builder_apply', array( $this, 'ajax_builder_apply' ) );
		add_action( 'wp_ajax_md_page_blocks_builder_preview', array( $this, 'ajax_builder_preview' ) );
		add_action( 'wp_ajax_md_page_blocks_ai_generate', array( $this, 'ajax_ai_generate' ) );
		add_action( 'wp_ajax_md_page_blocks_terminal_exec', array( $this, 'ajax_terminal_exec' ) );

		// Reusable blocks AJAX (admin edit page)
		add_action( 'wp_ajax_gt_pb_save_to_library', array( $this, 'ajax_save_to_library' ) );
		add_action( 'wp_ajax_gt_pb_admin_preview', array( $this, 'ajax_admin_preview' ) );
		add_action( 'wp_ajax_gt_pb_admin_preview_css', array( $this, 'ajax_admin_preview_css' ) );

		add_action( 'wp_footer', array( $this, 'output_footer_scripts' ), 99 );

		add_action( 'template_redirect', array( $this, 'collect_css_for_head' ) );
		add_action( 'template_redirect', array( $this, 'collect_js_for_file' ) );

		add_action( 'save_post', array( $this, 'on_post_save' ), 20, 2 );
		add_action( 'delete_post', array( $this, 'on_post_delete' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this->db, 'maybe_create_table' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_form_submission' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Shortcode for reusable library blocks
		$shortcode = new gt_pb_shortcode( $this->db, $this );
		new gt_pb_rest_api( $this->db, $this );
		$GLOBALS['gt_pb_theme_builder'] = new gt_pb_theme_builder( $this->db, $this );
		new gt_pb_migration();
		$shortcode->init();

		add_action( 'admin_footer', array( $this, 'output_rankmath_integration' ) );

		if ( ! wp_is_block_theme() ) {
			add_filter( 'theme_page_templates', array( $this, 'register_page_templates' ) );
			// A usage tally is only as good as the content it counted.
			add_action( 'save_post', array( __CLASS__, 'flush_block_usage_counts' ) );
			add_action( 'deleted_post', array( __CLASS__, 'flush_block_usage_counts' ) );
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
			if ( ! empty( $category['slug'] ) && $category['slug'] === 'gt-page-blocks' ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => 'gt-page-blocks',
			'title' => __( 'Page Blocks', 'page-blocks-builder' ),
		);

		return $categories;
	}

	/**
	 * Register block type.
	 */
	public function register_block() {
		// The block's own editor chrome (badges, library-link panel, preview
		// frame, code toggles) renders inside the block canvas. Since WP 6.3
		// that canvas is an iframe, and styles enqueued on
		// enqueue_block_editor_assets only reach the outer admin document —
		// which is why the inspector looked right while the block itself
		// rendered unstyled. Registering the stylesheet as the block type's
		// editor_style is what gets it injected into the iframe.
		$style_path = GT_PB_BUILDER_DIR . 'assets/css/block-editor.css';
		if ( file_exists( $style_path ) && ! wp_style_is( 'gt-page-block-editor', 'registered' ) ) {
			wp_register_style(
				'gt-page-block-editor',
				GT_PB_BUILDER_URL . 'assets/css/block-editor.css',
				// dashicons is a dependency, not a nicety: the device-preview
				// and dark-scheme controls are dashicon glyphs, and inside the
				// canvas iframe they render as blank squares without it.
				array( 'dashicons' ),
				filemtime( $style_path )
			);
		}

		$args = array(
			'render_callback' => array( $this, 'render_block' ),
			'editor_style'    => 'gt-page-block-editor',
			'attributes'      => array(
				// A non-zero blockId makes this block a *reference* to a library
				// row: the code lives in one place and every placement updates
				// together. Zero means the code is inline in these attributes.
				'blockId'    => array( 'type' => 'number', 'default' => 0 ),
				'content'    => array( 'type' => 'string', 'default' => '' ),
				'css'        => array( 'type' => 'string', 'default' => '' ),
				'js'         => array( 'type' => 'string', 'default' => '' ),
				'jsLocation' => array( 'type' => 'string', 'default' => 'footer' ),
				'format'     => array( 'type' => 'boolean', 'default' => false ),
				'phpExec'    => array( 'type' => 'boolean', 'default' => false ),
				'output'     => array( 'type' => 'string', 'default' => 'inline' ),
			),
		);

		register_block_type( self::BLOCK_NAME, $args );

		// Legacy name: identical render so un-migrated content keeps working.
		register_block_type( self::LEGACY_BLOCK_NAME, $args );
	}

	/**
	 * Whether a parsed block name is a page block (new or legacy).
	 */
	public static function is_page_block_name( string $name ): bool {
		return $name === self::BLOCK_NAME || $name === self::LEGACY_BLOCK_NAME;
	}

	/**
	 * Enqueue block editor assets.
	 */
	public function enqueue_block_editor_assets() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_id       = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$preview_nonce = $post_id > 0 ? wp_create_nonce( gt_page_blocks_preview_nonce_action( $post_id ) ) : '';

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
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-api-fetch', 'wp-plugins', 'wp-editor', 'code-editor', 'wp-codemirror' ),
				filemtime( $script_path ),
				true
			);

			// Use the same stylesheet set as the builder preview. Loading only
			// style.css left blocks previewing unstyled on themes that split
			// their CSS across modular files (Marketers Delight ships ~30),
			// which made the preview useless for judging a section.
			$preview_styles = $this->get_theme_style_urls();

			wp_localize_script(
				'gt-page-block-editor',
				'mdPageBlockEditor',
				array(
					'codeEditorSettings' => $editor_settings,
					'classSuggestions'   => $this->get_theme_class_suggestions(),
					// theme.json presets and the variables the theme defines,
					// so the preview resolves var() the way the front end does
					// and the editor can suggest what actually exists here.
					'previewGlobalCss'   => $this->get_preview_global_css(),
					'cssVariables'       => $this->get_theme_css_variables(),
					'postId'             => $post_id,
					'previewEndpoint'    => admin_url( 'admin-ajax.php' ),
					'previewAction'      => 'md_page_blocks_builder_preview',
					'previewNonce'       => $preview_nonce,
					'previewStyles'      => $preview_styles,
					// Save-to-library (block editor "Save to library" button)
					'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
					'canSave'            => current_user_can( 'manage_options' ),
					'libraryAction'      => 'gt_pb_save_to_library',
					'libraryNonce'       => wp_create_nonce( 'gt_pb_save_to_library' ),
					'libraryEditUrl'     => admin_url( 'admin.php?page=gt_pb_edit&id=' ),
					'restUrl'            => esc_url_raw( rest_url( gt_pb_rest_api::REST_NAMESPACE ) ),
					// The visual builder, reachable from the editor. Its nonce
					// is bound to this user, so it is minted here rather than
					// assembled in the browser.
					'builderUrl'         => $this->can_access_builder( $post_id, wp_create_nonce( gt_page_blocks_builder_nonce_action( $post_id ) ) )
						? gt_page_blocks_builder_url( $post_id, wp_create_nonce( gt_page_blocks_builder_nonce_action( $post_id ) ) )
						: '',
				)
			);
		}

		// Also load it in the outer admin document, for the parts of the UI
		// that render outside the canvas (inspector panel, modals). The handle
		// is registered in register_block(); enqueue it by handle so both
		// contexts share one registration.
		if ( file_exists( $style_path ) ) {
			if ( ! wp_style_is( 'gt-page-block-editor', 'registered' ) ) {
				wp_register_style(
					'gt-page-block-editor',
					GT_PB_BUILDER_URL . 'assets/css/block-editor.css',
					array( 'dashicons' ),
					filemtime( $style_path )
				);
			}
			wp_enqueue_style( 'gt-page-block-editor' );
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

		// Reference mode: the block points at a library row, so render that
		// row instead of the (empty) inline attributes. Blocks migrated from
		// the Marketers Delight dropin arrive in this shape.
		$block_id = isset( $attributes['blockId'] ) ? (int) $attributes['blockId'] : 0;
		if ( $block_id > 0 ) {
			$row = $this->db->get( $block_id );

			if ( ! $row || 'publish' !== $row->status ) {
				return '';
			}

			return $this->render_library_block( $row );
		}

		$content      = isset( $attributes['content'] ) ? (string) $attributes['content'] : '';
		$css          = isset( $attributes['css'] ) ? (string) $attributes['css'] : '';
		$js           = isset( $attributes['js'] ) ? (string) $attributes['js'] : '';
		$js_loc       = isset( $attributes['jsLocation'] ) && $attributes['jsLocation'] === 'inline' ? 'inline' : 'footer';
		$output_mode  = isset( $attributes['output'] ) ? $attributes['output'] : 'inline';
		$format       = ! empty( $attributes['format'] );
		$php_exec     = ! empty( $attributes['phpExec'] );
		$is_file_mode = $output_mode === 'file';
		$output       = '';

		// Emit this block's CSS unless this exact CSS already went out — either
		// hoisted into <head> by collect_css_for_head() or written by an
		// earlier placement — rather than on a request-global flag, which would
		// drop the styles of any block that scan never saw.
		if ( $css !== '' && ! $is_file_mode ) {
			$css_key = md5( $css );
			if ( ! isset( $this->inline_css_done[ $css_key ] ) ) {
				$this->inline_css_done[ $css_key ] = true;
				$output .= '<style>' . self::minify_css( self::sanitize_css( $css ) ) . '</style>' . "\n";
			}
		}

		if ( $content !== '' ) {
			// Inline blocks live in post_content with no separately-stored
			// save-time checksum, so a DB-only mutation of the post body could
			// not be detected. PHP execution therefore needs a second opt-in
			// (GT_PB_ALLOW_INLINE_PHP / MD_ALLOW_INLINE_PHP) on top of the
			// site-wide one; otherwise the tags are stripped.
			if ( $php_exec && gt_pb_inline_php_enabled() ) {
				$content = $this->execute_php( $content, md5( $content ) );
			} elseif ( $php_exec ) {
				$content = $this->execute_php( $content, '' );
			}

			// The toggle is labelled "WordPress formatting (wpautop)" and the
			// dropin these blocks were authored under ran exactly that. Running
			// the whole the_content chain instead re-enters a filter stack that
			// is already mid-run (blocks render during the_content), invites
			// injectors like schema/footnotes/related-posts to write inside a
			// block, and costs a full chain per block. Verified identical
			// output across every format-enabled block on the live sites.
			if ( $format ) {
				$content = wpautop( $content );
			}
			$content = do_shortcode( $content );

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
		if ( empty( $this->footer_scripts ) ) {
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

		return in_array( $post_type, gt_page_blocks_builder_post_types(), true );
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

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, gt_page_blocks_builder_nonce_action( $post_id ) ) ) {
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
				// dashicons: the builder runs on a front-end route, where the
				// admin's icon font is not loaded for us.
				array( 'code-editor', 'dashicons' ),
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
			'mdPbBuilder',
			array(
				'postId'             => $post_id,
				'blockName'          => self::BLOCK_NAME,
				// Save endpoint (dropin naming)
				'saveEndpoint'       => admin_url( 'admin-ajax.php' ),
				'saveAction'         => 'md_page_blocks_builder_apply',
				'saveNonce'          => $nonce,
				// Preview endpoint
				'previewEndpoint'    => admin_url( 'admin-ajax.php' ),
				'previewAction'      => 'md_page_blocks_builder_preview',
				'previewNonce'       => $nonce, // Use same nonce for both
				'previewCssUrl'      => '', // Plugin doesn't compile theme CSS; uses themeStyleUrls
				'editPostUrl'        => get_edit_post_link( $post_id, 'raw' ) ?: '',
				'viewPostUrl'        => get_permalink( $post_id ) ?: '',
				'initialSections'    => $this->get_builder_sections_from_post( $post_id ),
				'postTemplate'       => $this->get_builder_post_template_slug( $post_id ),
				'availableTemplates' => $this->get_available_page_templates( $post_id ),
				// Page settings, edited in the builder's own dialog rather than
				// sending the user back to the WordPress editor for a slug.
				'postTitle'          => get_the_title( $post_id ),
				'postSlug'           => get_post_field( 'post_name', $post_id ),
				'postStatus'         => get_post_status( $post_id ),
				'permalinkBase'      => $this->get_builder_permalink_base( $post_id ),
				'previewInjection'   => $this->get_builder_preview_injection( $post_id ),
				'codeEditorSettings' => $editor_settings,
				'themeStyleUrls'     => $this->get_builder_style_urls(),
				'cssClasses'         => array_values( array_unique( array_merge(
					$this->get_theme_css_classes_for_builder(),
					$this->get_utility_class_names()
				) ) ),
				// Detaching a linked section needs to read the library row it
				// points at, so the builder can copy that code into the page.
				'restUrl'            => esc_url_raw( rest_url( gt_pb_rest_api::REST_NAMESPACE ) ),
				'restNonce'          => wp_create_nonce( 'wp_rest' ),
				// AI
				'aiEndpoint'         => admin_url( 'admin-ajax.php' ),
				'aiAction'           => 'md_page_blocks_ai_generate',
				'aiDefaultModel'     => self::ai_stored_default_model(),
				'aiHasOpenAI'        => ! empty( get_option( 'gt_pb_ai_openai_key', '' ) ),
				'aiHasAnthropic'     => ! empty( get_option( 'gt_pb_ai_anthropic_key', '' ) ),
				'aiHasGemini'        => ! empty( get_option( 'gt_pb_ai_gemini_key', '' ) ),
				'aiCssContext'       => $this->get_ai_css_context(),
				'aiModels'           => self::ai_models(),
				// Library/export/import stubs (plugin uses post_content only, no DB library)
				'libraryEndpoint'    => '',
				'librarySaveAction'  => '',
				'libraryListAction'  => '',
				'exportAction'       => '',
				'importAction'       => '',
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
		$urls[]       = esc_url_raw( $template_uri );

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
	 * Style URLs to load in the BUILDER preview iframe.
	 *
	 * Includes theme stylesheets PLUS the plugin's reset/utilities files
	 * (always loaded in builder so authors see the full system).
	 *
	 * @return array
	 */
	private function get_builder_style_urls() {
		$urls = $this->get_theme_style_urls();

		// Always include reset + typography + utilities in builder so the builder preview
		// matches what would be available with the toggles enabled on frontend.
		$urls[] = GT_PB_BUILDER_URL . 'assets/css/reset.min.css';
		$urls[] = GT_PB_BUILDER_URL . 'assets/css/typography.min.css';
		$urls[] = GT_PB_BUILDER_URL . 'assets/css/utilities.css';

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Get all utility class names from utilities.css.
	 *
	 * Used for editor autocomplete in the builder.
	 *
	 * @return array<string>
	 */
	private function get_utility_class_names(): array {
		$cache_key = 'gt_pb_utility_class_names_v' . GT_PB_BUILDER_VERSION;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$path = GT_PB_BUILDER_DIR . 'assets/css/utilities.css';
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$css     = file_get_contents( $path );
		$classes = array();

		if ( preg_match_all( '/\.([\w\\\\\/:-]+)\s*\{/', $css, $matches ) ) {
			foreach ( $matches[1] as $cls ) {
				// Un-escape backslashes (e.g. md\:flex → md:flex)
				$cls = str_replace( '\\', '', $cls );
				if ( strlen( $cls ) > 0 ) {
					$classes[ $cls ] = true;
				}
			}
		}

		$names = array_keys( $classes );
		sort( $names, SORT_NATURAL );

		set_transient( $cache_key, $names, DAY_IN_SECONDS );

		return $names;
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
	 * The permalink up to (but not including) the slug.
	 *
	 * Used by the builder's page-settings dialog to show what the URL will
	 * look like while the slug is being typed. Derived from the real
	 * permalink so it respects the site's permalink structure, hierarchy and
	 * post type rather than assuming home_url() . '/'.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_builder_permalink_base( $post_id ) {
		$permalink = get_permalink( $post_id );
		$slug      = get_post_field( 'post_name', $post_id );

		if ( ! $permalink ) {
			return trailingslashit( home_url( '/' ) );
		}

		if ( $slug && false !== strpos( $permalink, $slug ) ) {
			$cut = strrpos( $permalink, $slug );
			if ( false !== $cut ) {
				return substr( $permalink, 0, $cut );
			}
		}

		return trailingslashit( home_url( '/' ) );
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
			'headHtml'      => (string) get_option( 'gt_pb_preview_head_html', '' ),
			'bodyStartHtml' => '',
			'bodyEndHtml'   => '',
			'css'           => (string) get_option( 'gt_pb_preview_css', '' ),
			'jsHead'        => '',
			'jsFooter'      => (string) get_option( 'gt_pb_preview_js_footer', '' ),
		);

		$injection = apply_filters_deprecated(
			'md_page_blocks_builder_preview_injection',
			array( $defaults, $post_id ),
			'2.7.4',
			'gt_page_blocks_builder_preview_injection'
		);

		/**
		 * Markup, CSS and JS injected into the builder preview document.
		 *
		 * @since 2.7.4 Renamed from md_page_blocks_builder_preview_injection.
		 *
		 * @param array<string,string> $injection Keys: headHtml, bodyStartHtml,
		 *                                        bodyEndHtml, css, jsHead, jsFooter.
		 * @param int                  $post_id   Post being edited.
		 */
		$injection = apply_filters( 'gt_page_blocks_builder_preview_injection', $injection, $post_id );
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

		$valid_nonce = wp_verify_nonce( $nonce, gt_page_blocks_preview_nonce_action( $post_id ) )
			|| wp_verify_nonce( $nonce, gt_page_blocks_builder_nonce_action( $post_id ) );

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
		// A library link has to survive the builder's load/save round trip:
		// dropping it here would rewrite every migrated block as an empty
		// inline one and blank the section everywhere it appears.
		$block_id    = isset( $section['blockId'] ) ? max( 0, (int) $section['blockId'] ) : 0;

		return array(
			'blockId'    => $block_id,
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

		$sections = array();

		// Walk the TOP-LEVEL blocks in document order and keep every one of
		// them, not just the page blocks. Two reasons:
		//
		//  - The builder can then show what else is on the page instead of
		//    pretending a page of mixed content is only its page blocks.
		//  - Saving can write the page back in this exact order. The old save
		//    collected page blocks and re-inserted them all at the position of
		//    the first one, so a core block sitting between two page blocks
		//    silently moved after them.
		//
		// Blocks nested inside containers are deliberately left alone: the
		// container travels as one opaque unit, which also fixes page blocks
		// inside a Group being hoisted out of it on save.
		foreach ( parse_blocks( (string) $post->post_content ) as $index => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			// parse_blocks() emits whitespace between blocks as nameless
			// entries. Preserve them verbatim so spacing round-trips.
			if ( '' === $name ) {
				$raw = (string) ( $block['innerHTML'] ?? '' );
				if ( '' === trim( $raw ) ) {
					continue;
				}
				$sections[] = array(
					'kind'       => 'foreign',
					'blockName'  => '',
					'label'      => __( 'Classic content', 'page-blocks-builder' ),
					'serialized' => $raw,
					'rendered'   => apply_filters( 'the_content', $raw ),
				);
				continue;
			}

			if ( self::is_page_block_name( $name ) ) {
				$attrs             = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$section           = $this->normalize_builder_section( $attrs );
				$section['kind']   = 'block';
				$sections[]        = $section;
				continue;
			}

			$sections[] = array(
				'kind'       => 'foreign',
				'blockName'  => $name,
				'label'      => $this->builder_block_label( $name ),
				// Kept verbatim so saving re-emits exactly what was parsed,
				// including any inner blocks and their attributes.
				'serialized' => serialize_block( $block ),
				'rendered'   => (string) render_block( $block ),
				'hasPageBlocks' => (bool) self::find_page_blocks( array( $block ) ),
			);
		}

		return $sections;
	}

	/**
	 * Human label for a block name, for the builder's section list.
	 *
	 * @param string $name Block name.
	 * @return string
	 */
	private function builder_block_label( $name ) {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

		if ( $type && ! empty( $type->title ) ) {
			return (string) $type->title;
		}

		// Unregistered block (a plugin that is inactive, say). Show the raw
		// name rather than a blank row, so it is obvious what is on the page.
		return $name;
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
			if ( self::is_page_block_name( (string) ( $block['blockName'] ?? '' ) ) ) {
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
			$section = is_array( $section ) ? $section : array();

			// Blocks the builder cannot edit still belong in the preview —
			// otherwise the preview shows a different page from the one the
			// visitor gets. They are rendered from their own markup and
			// contribute no editable CSS or JS.
			if ( isset( $section['kind'] ) && 'foreign' === $section['kind'] ) {
				$raw = isset( $section['serialized'] ) ? (string) $section['serialized'] : '';
				if ( '' !== trim( $raw ) ) {
					$html_output[] = (string) do_blocks( $raw );
				}
				continue;
			}

			// Linked sections preview through render_library_block(), the same
			// path render_block() takes, so the row's PHP checksum gate still
			// applies. That call queues footer JS instead of returning it, so
			// pull this row's entry back out for the preview document.
			$linked_id = isset( $section['blockId'] ) ? (int) $section['blockId'] : 0;
			if ( $linked_id > 0 ) {
				$row = $this->db->get( $linked_id );
				if ( ! $row || 'publish' !== $row->status ) {
					continue;
				}

				// No extra minify pass here: render_library_block() already
				// minifies, and render_block() calls it bare on the front end.
				// Wrapping it would make the preview diverge from what ships.
				$html_output[] = (string) $this->render_library_block( $row );

				$queued_key = 'block-' . (int) $row->id;
				if ( isset( $this->footer_scripts[ $queued_key ] ) ) {
					$js_footer_output[] = self::minify_js( (string) $this->footer_scripts[ $queued_key ] );
					unset( $this->footer_scripts[ $queued_key ] );
				}

				continue;
			}

			$content     = isset( $section['content'] ) ? (string) $section['content'] : '';
			$css         = isset( $section['css'] ) ? (string) $section['css'] : '';
			$js          = isset( $section['js'] ) ? (string) $section['js'] : '';
			$format      = ! empty( $section['format'] );
			$php_exec    = ! empty( $section['phpExec'] );
			$js_location = isset( $section['jsLocation'] ) && $section['jsLocation'] === 'inline' ? 'inline' : 'footer';

			if ( $content !== '' ) {
				// Admin-authenticated preview of code the editor just supplied:
				// a self-checksum reduces the gate to the site-wide constant,
				// matching what the block will do once saved.
				if ( $php_exec ) {
					$content = $this->execute_php( $content, md5( $content ) );
				}

				// Same formatting rule as the front end (see render_block()).
				if ( $format ) {
					$content = wpautop( $content );
				}
				$content = do_shortcode( $content );
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
	/**
	 * AJAX: save the current inline block as a reusable library Page Block.
	 *
	 * @since 2.5.0
	 */
	public function ajax_save_to_library() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Authentication required.', 'page-blocks-builder' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'gt_pb_save_to_library' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to save to the library.', 'page-blocks-builder' ) ), 403 );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => __( 'A title is required.', 'page-blocks-builder' ) ), 400 );
		}

		$id = $this->db->insert( array(
			'title'       => $title,
			'status'      => 'publish',
			'content'     => isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '',
			'css'         => isset( $_POST['css'] ) ? (string) wp_unslash( $_POST['css'] ) : '',
			'js'          => isset( $_POST['js'] ) ? (string) wp_unslash( $_POST['js'] ) : '',
			'js_location' => ( isset( $_POST['js_location'] ) && 'inline' === $_POST['js_location'] ) ? 'inline' : 'footer',
			'output'      => ( isset( $_POST['output'] ) && 'file' === $_POST['output'] ) ? 'file' : 'inline',
			'php_exec'    => ! empty( $_POST['php_exec'] ) ? 1 : 0,
			'format'      => ! empty( $_POST['format'] ) ? 1 : 0,
		) );

		if ( false === $id ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the Page Block.', 'page-blocks-builder' ) ), 500 );
		}

		wp_send_json_success( array( 'id' => $id, 'title' => $title ) );
	}

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
		$post_title    = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : null;
		$post_slug     = isset( $_POST['post_slug'] ) ? sanitize_title( wp_unslash( $_POST['post_slug'] ) ) : null;
		// How many blocks the builder cannot rebuild the author deleted on
		// purpose. Without it the guard below cannot tell a deliberate
		// deletion from a client that lost them in transit.
		$removed_foreign = isset( $_POST['removed_foreign'] ) ? absint( $_POST['removed_foreign'] ) : 0;
		$decoded       = json_decode( (string) $raw_sections, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid builder payload.', 'page-blocks-builder' ) ), 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post no longer exists.', 'page-blocks-builder' ) ), 404 );
		}

		// Rebuild the document in exactly the order the builder is showing.
		// Foreign blocks are re-emitted from the markup they were loaded with,
		// so anything the builder cannot edit round-trips byte for byte and
		// stays where it was. The previous version collected page blocks and
		// re-inserted them all at the first one's position, which silently
		// moved any core block that sat between them.
		$parts    = array();
		$sections = array();

		foreach ( $decoded as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			if ( isset( $section['kind'] ) && 'foreign' === $section['kind'] ) {
				$raw = isset( $section['serialized'] ) ? (string) $section['serialized'] : '';
				if ( '' !== trim( $raw ) ) {
					$parts[] = $raw;
				}
				continue;
			}

			$normalized = $this->normalize_builder_section( $section );
			$sections[] = $normalized;

			$attrs = $normalized;
			// Keep a real link, but leave ordinary sections unmarked rather
			// than writing blockId:0 into every block in post_content.
			if ( empty( $attrs['blockId'] ) ) {
				unset( $attrs['blockId'] );
			}
			unset( $attrs['kind'] );

			$parts[] = serialize_block( array(
				'blockName'    => self::BLOCK_NAME,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			) );
		}

		// A payload with no page-block sections at all would blank a page that
		// only failed to send them. Refuse rather than destroy content.
		if ( ! $parts ) {
			wp_send_json_error( array( 'message' => __( 'Refusing to save an empty document.', 'page-blocks-builder' ) ), 400 );
		}

		// Safety net for the blocks the builder cannot rebuild. If the payload
		// carries fewer of them than the page currently has, something dropped
		// them in transit — a stale client, a serialisation bug — and saving
		// would silently delete content the builder never had the means to
		// recreate. Refuse instead. This is not hypothetical: an early version
		// of the client whitelisted the page-block fields and stripped these,
		// which rewrote a mixed page as empty page blocks.
		$existing_foreign = 0;
		foreach ( parse_blocks( (string) $post->post_content ) as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					++$existing_foreign;
				}
				continue;
			}
			if ( ! self::is_page_block_name( $name ) ) {
				++$existing_foreign;
			}
		}

		$payload_foreign = 0;
		foreach ( $decoded as $section ) {
			if ( is_array( $section ) && isset( $section['kind'] ) && 'foreign' === $section['kind'] ) {
				++$payload_foreign;
			}
		}

		// A stale or broken client sends no deletion count, so it still fails
		// this check exactly as before — the safety net only widens by what
		// the author explicitly asked for.
		if ( $payload_foreign + $removed_foreign < $existing_foreign ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: blocks in the payload, 2: blocks on the page */
						__( 'Refusing to save: this page has %2$d block(s) the builder cannot edit but the save only accounted for %1$d. Reload the builder and try again.', 'page-blocks-builder' ),
						$payload_foreign,
						$existing_foreign
					),
				),
				409
			);
		}

		$update_args = array(
			'ID'           => $post_id,
			'post_content' => wp_slash( implode( "\n\n", $parts ) ),
		);

		// Only carry a field the client actually sent, and only when it
		// changed. Passing the current title back through wp_update_post on
		// every save would rewrite it for no reason, and an empty title would
		// hand WordPress a blank post.
		if ( null !== $post_title && '' !== $post_title && $post_title !== $post->post_title ) {
			$update_args['post_title'] = wp_slash( $post_title );
		}

		if ( null !== $post_slug && '' !== $post_slug && $post_slug !== $post->post_name ) {
			$update_args['post_name'] = $post_slug;
		}

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

		// WordPress sanitises and de-duplicates a slug on save, so the client
		// is told what it actually became rather than what it asked for.
		wp_send_json_success(
			array(
				'message'     => __( 'Page Blocks saved.', 'page-blocks-builder' ),
				'postId'      => $post_id,
				'sections'    => $sections,
				'editPostUrl' => get_edit_post_link( $post_id, 'raw' ) ?: '',
				'postTitle'   => get_the_title( $post_id ),
				'postSlug'    => get_post_field( 'post_name', $post_id ),
				'viewPostUrl' => get_permalink( $post_id ) ?: '',
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

		$builder_url = gt_page_blocks_builder_url( $post_id, wp_create_nonce( gt_page_blocks_builder_nonce_action( $post_id ) ) );

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

		if ( ! in_array( $screen->post_type, gt_page_blocks_builder_post_types(), true ) ) {
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
			'default'           => self::ai_default_model(),
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_terminal_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );

		// Preview customization settings
		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_preview_css', array(
			'type'    => 'string',
			'default' => '',
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_preview_head_html', array(
			'type'    => 'string',
			'default' => '',
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_preview_js_footer', array(
			'type'    => 'string',
			'default' => '',
		) );

		// Frontend CSS settings
		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_load_reset', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_load_typography', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );

		register_setting( 'gt_page_blocks_builder_settings', 'gt_pb_load_utilities', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
	}

	/** How long a usage tally stays good for. */
	const USAGE_CACHE_TTL = 300;

	/**
	 * How many posts reference each library block.
	 *
	 * Counted in one pass over the posts that mention a page block at all,
	 * rather than a LIKE per block — a library of two hundred blocks would
	 * otherwise mean two hundred full-table scans to draw one screen. The
	 * result is cached briefly and dropped whenever a post or a block changes.
	 *
	 * Both ways of placing a block count: the editor block, which carries
	 * "blockId":N, and the [page_block id="N"] shortcode.
	 *
	 * @return array<int,int> Block id => number of posts using it.
	 */
	public static function get_block_usage_counts() {
		$cached = get_transient( 'gt_pb_usage_counts' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$rows = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_status NOT IN ('auto-draft', 'inherit')
			   AND post_type NOT IN ('revision')
			   AND ( post_content LIKE '%blockId%' OR post_content LIKE '%page_block%' )"
		);

		$counts = array();

		foreach ( (array) $rows as $content ) {
			$seen = array();

			if ( preg_match_all( '/"blockId"\s*:\s*(\d+)/', (string) $content, $m ) ) {
				foreach ( $m[1] as $id ) {
					$seen[ (int) $id ] = true;
				}
			}

			if ( preg_match_all( '/\[page_block[^\]]*\bid\s*=\s*["\']?(\d+)/', (string) $content, $m ) ) {
				foreach ( $m[1] as $id ) {
					$seen[ (int) $id ] = true;
				}
			}

			// Counted once per post, not once per placement: the question the
			// number answers is "where would deleting this break something".
			foreach ( array_keys( $seen ) as $id ) {
				if ( $id > 0 ) {
					$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
				}
			}
		}

		set_transient( 'gt_pb_usage_counts', $counts, self::USAGE_CACHE_TTL );

		return $counts;
	}

	public static function flush_block_usage_counts() {
		delete_transient( 'gt_pb_usage_counts' );
	}

	/**
	 * Models the AI assistant offers.
	 *
	 * Single source of truth. This list used to be repeated in four places and
	 * had already drifted apart: the registered default was claude-sonnet-4-6
	 * while the request path fell back to gpt-5.2, so a saved model could be
	 * valid in one list and silently replaced by the other.
	 *
	 * @since 2.7.3
	 * @return array<int,array{id:string,label:string,provider:string}>
	 */
	public static function ai_models() {
		return array(
			// OpenAI — the GPT-5.6 family, deepest reasoning first. A site
			// still holding a retired id falls back to the default, so
			// dropping the older models cannot leave one stuck on a model the
			// request path would reject.
			array( 'id' => 'gpt-5.6-sol', 'label' => 'GPT-5.6 Sol', 'provider' => 'openai' ),
			array( 'id' => 'gpt-5.6-terra', 'label' => 'GPT-5.6 Terra', 'provider' => 'openai' ),
			array( 'id' => 'gpt-5.6-luna', 'label' => 'GPT-5.6 Luna', 'provider' => 'openai' ),
			// Anthropic — Claude 5 family.
			array( 'id' => 'claude-opus-5', 'label' => 'Claude Opus 5', 'provider' => 'anthropic' ),
			array( 'id' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5', 'provider' => 'anthropic' ),
			array( 'id' => 'claude-fable-5', 'label' => 'Claude Fable 5', 'provider' => 'anthropic' ),
			array( 'id' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5', 'provider' => 'anthropic' ),
			// Google.
			array( 'id' => 'gemini-3-flash-preview', 'label' => 'Gemini 3 Flash', 'provider' => 'gemini' ),
		);
	}

	/**
	 * The model used when none is chosen, or when a stored one is unknown.
	 */
	public static function ai_default_model() {
		return 'gpt-5.6-luna';
	}

	/**
	 * The configured default, or the built-in one when the stored value names
	 * a model that is no longer offered.
	 *
	 * Sanitising happens on save, so a site that stored a model before it was
	 * retired keeps that value in the option. Reading it raw would show the
	 * dead id in the settings screen and in the assistant's model picker.
	 */
	public static function ai_stored_default_model() {
		$stored = (string) get_option( 'gt_pb_ai_default_model', '' );

		return in_array( $stored, self::ai_model_ids(), true ) ? $stored : self::ai_default_model();
	}

	public static function ai_model_ids() {
		return array_map(
			static function ( $model ) {
				return $model['id'];
			},
			self::ai_models()
		);
	}

	public function sanitize_ai_model( $value ) {
		return in_array( $value, self::ai_model_ids(), true ) ? $value : self::ai_default_model();
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
	/**
	 * Register top-level "Page Blocks" admin menu with sub-pages:
	 * - All Page Blocks (list table)
	 * - Add New
	 * - Settings
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Page Blocks', 'page-blocks-builder' ),
			__( 'Page Blocks', 'page-blocks-builder' ),
			'manage_options',
			'gt_page_blocks',
			array( $this, 'render_list_page' ),
			'dashicons-layout',
			26
		);

		add_submenu_page(
			'gt_page_blocks',
			__( 'All Page Blocks', 'page-blocks-builder' ),
			__( 'All Page Blocks', 'page-blocks-builder' ),
			'manage_options',
			'gt_page_blocks',
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			'gt_page_blocks',
			__( 'Add New', 'page-blocks-builder' ),
			__( 'Add New', 'page-blocks-builder' ),
			'manage_options',
			'gt_pb_edit',
			array( $this, 'render_edit_page' )
		);

		add_submenu_page(
			'gt_page_blocks',
			__( 'Settings', 'page-blocks-builder' ),
			__( 'Settings', 'page-blocks-builder' ),
			'manage_options',
			'gt_pb_settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * @deprecated Use register_admin_menu() — kept as alias for back-compat.
	 */
	public function register_settings_page() {
		// no-op (replaced by register_admin_menu)
	}

	/**
	 * Render the list table admin page.
	 */
	public function render_list_page() {
		// Legacy list table via ?view=list (fallback / bulk ops).
		if ( isset( $_GET['view'] ) && $_GET['view'] === 'list' ) {
			$this->render_legacy_list_page();
			return;
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Page Blocks', 'page-blocks-builder' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=gt_pb_edit&action=new' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'page-blocks-builder' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_admin_notices(); ?>

			<div id="gt-pb-library">
				<p><span class="spinner is-active" style="float:none;"></span></p>
				<noscript><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=gt_page_blocks&view=list' ) ); ?>"><?php esc_html_e( 'JavaScript is required for the library view — use the list view instead.', 'page-blocks-builder' ); ?></a></p></noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * Legacy WP_List_Table view (?view=list).
	 */
	public function render_legacy_list_page() {
		$list_table = new gt_pb_list_table( $this->db );
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Page Blocks', 'page-blocks-builder' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=gt_pb_edit&action=new' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'page-blocks-builder' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=gt_page_blocks' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Library view', 'page-blocks-builder' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_admin_notices(); ?>

			<form method="get">
				<input type="hidden" name="page" value="gt_page_blocks">
				<input type="hidden" name="view" value="list">
				<?php
				$list_table->search_box( __( 'Search Page Blocks', 'page-blocks-builder' ), 'gt-pb-search' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the edit/add admin page.
	 */
	public function render_edit_page() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$block  = null;

		if ( $action !== 'new' && $id > 0 ) {
			$block = $this->db->get( $id );
			if ( ! $block ) {
				wp_die( esc_html__( 'Page block not found.', 'page-blocks-builder' ) );
			}
		}

		include GT_PB_BUILDER_DIR . 'templates/admin-edit.php';
	}

	private function render_admin_notices() {
		if ( ! isset( $_GET['msg'] ) ) {
			return;
		}
		$msg     = sanitize_key( $_GET['msg'] );
		$mapping = array(
			'created' => __( 'Page block created.', 'page-blocks-builder' ),
			'updated' => __( 'Page block updated.', 'page-blocks-builder' ),
			'trashed' => __( 'Page block moved to trash.', 'page-blocks-builder' ),
			'restored' => __( 'Page block restored.', 'page-blocks-builder' ),
			'deleted' => __( 'Page block permanently deleted.', 'page-blocks-builder' ),
		);
		if ( isset( $mapping[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $mapping[ $msg ] ) . '</p></div>';
		}
	}

	/**
	 * Handle save / row actions on admin pages.
	 */
	public function handle_admin_form_submission() {
		// Save (create/update)
		if ( isset( $_POST['gt_pb_save'] ) ) {
			$this->handle_block_save();
			return;
		}

		// Row actions: trash, restore, delete
		if ( isset( $_GET['page'], $_GET['action'], $_GET['id'] ) && $_GET['page'] === 'gt_page_blocks' ) {
			$action = sanitize_key( $_GET['action'] );
			$id     = (int) $_GET['id'];
			$nonce  = $_GET['_wpnonce'] ?? '';

			if ( $action === 'trash' && wp_verify_nonce( $nonce, 'md_pb_trash_' . $id ) ) {
				$this->db->trash( $id );
				wp_safe_redirect( admin_url( 'admin.php?page=gt_page_blocks&msg=trashed' ) );
				exit;
			}
			if ( $action === 'restore' && wp_verify_nonce( $nonce, 'md_pb_restore_' . $id ) ) {
				$this->db->restore( $id );
				wp_safe_redirect( admin_url( 'admin.php?page=gt_page_blocks&msg=restored' ) );
				exit;
			}
			if ( $action === 'delete' && wp_verify_nonce( $nonce, 'md_pb_delete_' . $id ) ) {
				$this->db->delete( $id );
				wp_safe_redirect( admin_url( 'admin.php?page=gt_page_blocks&msg=deleted' ) );
				exit;
			}
		}
	}

	/**
	 * Save handler for the edit form.
	 */
	private function handle_block_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'page-blocks-builder' ) );
		}

		check_admin_referer( 'gt_pb_save_block' );

		$id   = isset( $_POST['block_id'] ) ? (int) $_POST['block_id'] : 0;
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data = array(
			'title'       => sanitize_text_field( wp_unslash( $_POST['block_title'] ?? '' ) ),
			'slug'        => sanitize_title( wp_unslash( $_POST['block_slug'] ?? '' ) ),
			'status'      => sanitize_text_field( wp_unslash( $_POST['block_status'] ?? 'publish' ) ),
			'content'     => wp_unslash( $_POST['block_content'] ?? '' ),
			'css'         => wp_unslash( $_POST['block_css'] ?? '' ),
			'js'          => wp_unslash( $_POST['block_js'] ?? '' ),
			'js_location' => sanitize_text_field( wp_unslash( $_POST['block_js_location'] ?? 'footer' ) ),
			'output'      => sanitize_text_field( wp_unslash( $_POST['block_output'] ?? 'inline' ) ),
			'php_exec'    => isset( $_POST['block_php_exec'] ) ? 1 : 0,
			'format'      => isset( $_POST['block_format'] ) ? 1 : 0,
			'position'    => sanitize_text_field( wp_unslash( $_POST['block_position'] ?? '' ) ),
			'priority'    => isset( $_POST['block_priority'] ) ? (int) $_POST['block_priority'] : 10,
			'conditions'  => $this->parse_conditions_from_post(),
			'author'      => get_current_user_id(),
		);
		// phpcs:enable
		// php_checksum is derived in gt_pb_db, so every write path — admin
		// form, AJAX, REST — records it the same way.

		if ( $id > 0 ) {
			$this->db->update( $id, $data );
			$msg = 'updated';
		} else {
			$id  = $this->db->insert( $data );
			$msg = 'created';
		}

		wp_safe_redirect( admin_url( "admin.php?page=gt_pb_edit&id={$id}&msg={$msg}" ) );
		exit;
	}

	/**
	 * Build the conditions JSON from the block edit form.
	 *
	 * Shape: { post_types: [...], page_types: [...], post_ids: [...] }.
	 * An empty result is stored as '' meaning "show everywhere".
	 *
	 * @return string JSON, or '' when no condition was set.
	 */
	private function parse_conditions_from_post(): string {
		$post_types = isset( $_POST['block_condition_post_types'] ) && is_array( $_POST['block_condition_post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['block_condition_post_types'] ) ) ) )
			: array();

		$page_types = isset( $_POST['block_condition_page_types'] ) && is_array( $_POST['block_condition_page_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['block_condition_page_types'] ) ) ) )
			: array();

		$raw_ids  = isset( $_POST['block_condition_post_ids'] )
			? (string) wp_unslash( $_POST['block_condition_post_ids'] )
			: '';
		$post_ids = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw_ids ) ?: array() ) ) );

		if ( ! $post_types && ! $page_types && ! $post_ids ) {
			return '';
		}

		return (string) wp_json_encode( array(
			'post_types' => $post_types,
			'page_types' => $page_types,
			'post_ids'   => $post_ids,
		) );
	}

	/**
	 * Enqueue admin assets on Page Blocks admin pages.
	 */
	public function enqueue_admin_assets( $hook ) {
		// The settings screen had no stylesheet of its own, which is how it
		// ended up carrying its layout in style attributes.
		if ( strpos( $hook, 'gt_pb_settings' ) !== false ) {
			wp_enqueue_style(
				'gt-pb-settings',
				GT_PB_BUILDER_URL . 'assets/css/settings.css',
				array(),
				GT_PB_BUILDER_VERSION
			);
			return;
		}

		// Match our admin pages: toplevel_page_gt_page_blocks or page-blocks_page_gt_pb_*
		if ( strpos( $hook, 'gt_page_blocks' ) === false && strpos( $hook, 'gt_pb_edit' ) === false ) {
			return;
		}

		// Library panel (main screen, card-grid app)
		if ( strpos( $hook, 'toplevel_page_gt_page_blocks' ) !== false && empty( $_GET['view'] ) ) {
			wp_enqueue_style(
				'gt-pb-library',
				GT_PB_BUILDER_URL . 'assets/css/library.css',
				array(),
				GT_PB_BUILDER_VERSION
			);
			wp_enqueue_script(
				'gt-pb-library',
				GT_PB_BUILDER_URL . 'assets/js/library.js',
				array(),
				GT_PB_BUILDER_VERSION,
				true
			);
			$preview_styles = array( get_stylesheet_uri() );
			if ( is_child_theme() ) {
				$preview_styles[] = get_template_directory_uri() . '/style.css';
			}
			wp_localize_script( 'gt-pb-library', 'gtPbLibrary', array(
				'restUrl'       => esc_url_raw( rest_url( gt_pb_rest_api::REST_NAMESPACE ) ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'editUrl'       => admin_url( 'admin.php?page=gt_pb_edit&id=' ),
				'newUrl'        => admin_url( 'admin.php?page=gt_pb_edit&action=new' ),
				'previewStyles' => array_map( function ( $url ) {
					return preg_replace( '/^http:\/\//', 'https://', $url );
				}, $preview_styles ),
				'i18n'          => array(
					'all'               => __( 'All', 'page-blocks-builder' ),
					'published'         => __( 'Published', 'page-blocks-builder' ),
					'drafts'            => __( 'Drafts', 'page-blocks-builder' ),
					'draft'             => __( 'Draft', 'page-blocks-builder' ),
					'trash'             => __( 'Trash', 'page-blocks-builder' ),
					'searchPlaceholder' => __( 'Search blocks…', 'page-blocks-builder' ),
					'addNew'            => __( 'Add new block', 'page-blocks-builder' ),
					'edit'              => __( 'Edit', 'page-blocks-builder' ),
					'duplicate'         => __( 'Duplicate', 'page-blocks-builder' ),
					'shortcode'         => __( 'Shortcode', 'page-blocks-builder' ),
					'toTrash'           => __( 'Trash', 'page-blocks-builder' ),
					'restore'           => __( 'Restore', 'page-blocks-builder' ),
					'deleteForever'     => __( 'Delete forever', 'page-blocks-builder' ),
					'deleteConfirm'     => __( 'Delete this page block permanently? This cannot be undone.', 'page-blocks-builder' ),
					'duplicated'        => __( 'Block duplicated.', 'page-blocks-builder' ),
					'trashed'           => __( 'Block moved to trash.', 'page-blocks-builder' ),
					'restored'          => __( 'Block restored as draft.', 'page-blocks-builder' ),
					'deleted'           => __( 'Block deleted permanently.', 'page-blocks-builder' ),
					'shortcodeCopied'   => __( 'Shortcode copied to clipboard.', 'page-blocks-builder' ),
					'loadMore'          => __( 'Load more', 'page-blocks-builder' ),
					'loading'           => __( 'Loading…', 'page-blocks-builder' ),
					'justNow'           => __( 'just now', 'page-blocks-builder' ),
					'emptyLibrary'      => __( 'Your library is empty', 'page-blocks-builder' ),
					'emptyLibraryHint'  => __( 'Save reusable sections once, drop them anywhere with a shortcode or the editor block.', 'page-blocks-builder' ),
					'createFirst'       => __( 'Create your first block', 'page-blocks-builder' ),
					'emptyFiltered'     => __( 'No blocks match', 'page-blocks-builder' ),
					'emptyFilteredHint' => __( 'Try a different search or filter.', 'page-blocks-builder' ),
					// Shown in place of a thumbnail when a block has no markup
					// to render.
					'previewCssOnly'    => __( 'CSS only', 'page-blocks-builder' ),
					'previewJsOnly'     => __( 'JavaScript only', 'page-blocks-builder' ),
					'previewPhpOnly'    => __( 'PHP only', 'page-blocks-builder' ),
					'previewEmpty'      => __( 'Nothing to preview', 'page-blocks-builder' ),
					'searchLabel'       => __( 'Search blocks', 'page-blocks-builder' ),
					'searchSubmit'      => __( 'Search', 'page-blocks-builder' ),
					'usedOnePage'       => __( 'page', 'page-blocks-builder' ),
					'usedManyPages'     => __( 'pages', 'page-blocks-builder' ),
					'usedTitle'         => __( 'Posts and pages that place this block', 'page-blocks-builder' ),
					'unused'            => __( 'Unused', 'page-blocks-builder' ),
					'unusedTitle'       => __( 'Nothing on this site places this block', 'page-blocks-builder' ),
					'copyShortcode'     => __( 'Copy shortcode', 'page-blocks-builder' ),
					'previewWidth'      => __( 'Preview width', 'page-blocks-builder' ),
					'sortBy'            => __( 'Sort by', 'page-blocks-builder' ),
					'sortRecent'        => __( 'Recently updated', 'page-blocks-builder' ),
					'sortTitle'         => __( 'Title A–Z', 'page-blocks-builder' ),
					'sortMostUsed'      => __( 'Most used', 'page-blocks-builder' ),
					'sortLeastUsed'     => __( 'Least used', 'page-blocks-builder' ),
					'viewMode'          => __( 'View', 'page-blocks-builder' ),
					'viewGrid'          => __( 'Grid view', 'page-blocks-builder' ),
					'viewList'          => __( 'List view', 'page-blocks-builder' ),
					'selectAll'         => __( 'Select all', 'page-blocks-builder' ),
					'selected'          => __( 'selected', 'page-blocks-builder' ),
					'clearSelection'    => __( 'Clear', 'page-blocks-builder' ),
					'bulkTrashConfirm'  => __( 'Move %d block(s) to trash?', 'page-blocks-builder' ),
					'bulkDeleteConfirm' => __( 'Permanently delete %d block(s)? This cannot be undone.', 'page-blocks-builder' ),
					'bulkDone'          => __( '%d block(s) updated.', 'page-blocks-builder' ),
					'bulkPartly'        => __( '%1$d updated, %2$d failed.', 'page-blocks-builder' ),
					'colTitle'          => __( 'Title', 'page-blocks-builder' ),
					'colSlug'           => __( 'Slug', 'page-blocks-builder' ),
					'colContains'       => __( 'Contains', 'page-blocks-builder' ),
					'colUsage'          => __( 'Used on', 'page-blocks-builder' ),
					'colShortcode'      => __( 'Shortcode', 'page-blocks-builder' ),
					'colUpdated'        => __( 'Updated', 'page-blocks-builder' ),
				),
			) );
		}

		wp_enqueue_style(
			'gt-pb-admin-edit',
			GT_PB_BUILDER_URL . 'assets/css/admin-edit.css',
			array(),
			GT_PB_BUILDER_VERSION
		);

		wp_enqueue_script(
			'gt-pb-admin-edit',
			GT_PB_BUILDER_URL . 'assets/js/admin-edit.js',
			array(),
			GT_PB_BUILDER_VERSION,
			true
		);

		wp_localize_script( 'gt-pb-admin-edit', 'gtPbPreview', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'gt_pb_admin_preview' ),
			'previewCssUrl' => add_query_arg( array(
				'action' => 'gt_pb_admin_preview_css',
				'nonce'  => wp_create_nonce( 'gt_pb_admin_preview' ),
			), admin_url( 'admin-ajax.php' ) ),
			'cssClasses'    => $this->get_theme_class_suggestions(),
			'cssVariables'  => $this->get_theme_css_variables(),
			'previewGlobalCss' => $this->get_preview_global_css(),
		) );
	}

	/**
	 * AJAX: render preview HTML for admin edit page (single block).
	 */
	public function ajax_admin_preview() {
		check_ajax_referer( 'gt_pb_admin_preview', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
		$css     = isset( $_POST['css'] ) ? wp_unslash( $_POST['css'] ) : '';
		$js      = isset( $_POST['js'] ) ? wp_unslash( $_POST['js'] ) : '';
		// phpcs:enable
		$php     = ! empty( $_POST['php_exec'] );
		$format  = ! empty( $_POST['format'] );

		$html = $content;
		if ( $php ) {
			// Same policy as build_preview_payload(): admin preview of
			// just-submitted code, gated by the site-wide constant only.
			$html = $this->execute_php( $html, md5( $html ) );
		}
		if ( $format ) {
			$html = wpautop( $html );
		}
		$html = do_shortcode( $html );

		wp_send_json_success( array(
			'html' => $html,
			'css'  => $css,
			'js'   => $js,
		) );
	}

	/**
	 * AJAX: serve theme CSS for admin edit page preview iframe.
	 */
	public function ajax_admin_preview_css() {
		check_ajax_referer( 'gt_pb_admin_preview', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 'Unauthorized', array( 'response' => 403 ) );
		}

		$urls = $this->get_theme_style_urls();
		$css  = '';
		foreach ( $urls as $url ) {
			$css .= "@import url('" . esc_url_raw( $url ) . "');\n";
		}

		header( 'Content-Type: text/css; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo $css;
		wp_die();
	}

	/**
	 * Render a library block (used by [page_block] shortcode).
	 *
	 * @param object $block DB row.
	 * @return string Rendered HTML.
	 */
	public function render_library_block( $block ) {
		$content = $block->content ?? '';
		$css     = $block->css ?? '';
		$js      = $block->js ?? '';

		if ( ! empty( $block->php_exec ) ) {
			$content = $this->execute_php( $content, (string) ( $block->php_checksum ?? '' ) );
		}

		// Same formatting rule as render_block(): wpautop, not the full
		// the_content chain.
		if ( ! empty( $block->format ) ) {
			$content = wpautop( $content );
		}
		$content = do_shortcode( $content );

		// Match the inline block path (and the dropin this replaces), which
		// both minify. Without it, a page of referenced blocks ships every
		// newline and indent from the stored source. minify_html() leaves
		// pre/code/script/style/textarea untouched.
		$content = self::minify_html( (string) $content );

		$out      = '';
		$block_id = (int) ( $block->id ?? 0 );

		// CSS is emitted once per block per request. collect_css_for_head()
		// hoists it into <head> when the block is discoverable before wp_head;
		// anything rendered later (shortcodes, theme regions) inlines here.
		// The done-set is the only guard: a block hoisted into <head> is
		// already marked, and one that was not (shortcode in a widget, a
		// region rendered after wp_head) still needs its CSS inline.
		if ( ! empty( $css ) && empty( $this->library_css_done[ $block_id ] ) ) {
			$this->library_css_done[ $block_id ] = true;
			$out .= '<style id="gt-pb-block-' . $block_id . '">'
				. self::minify_css( self::sanitize_css( $css ) )
				. '</style>';
		}

		$out .= $content;

		if ( ! empty( $js ) ) {
			$location = $block->js_location ?? 'footer';
			if ( $location === 'inline' ) {
				$out .= '<script>' . $js . '</script>';
			} else {
				// Keyed by block ID, so repeated placements queue it once.
				$this->footer_scripts[ 'block-' . $block_id ] = $js;
			}
		}

		return $out;
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

		$enabled = gt_page_blocks_builder_post_types();
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
	 * theme.json global styles, as WordPress itself prints them.
	 *
	 * Classic themes get this too when a parent ships a theme.json, and it is
	 * where preset custom properties (--wp--preset--*) and base element styles
	 * live. Without it a preview can load every theme stylesheet and still
	 * render nothing like the front end, because the variables those sheets
	 * reference are simply absent.
	 *
	 * @since 2.7.1
	 * @return string CSS, or '' when the install has no global styles.
	 */
	public function get_preview_global_css() {
		if ( ! function_exists( 'wp_get_global_stylesheet' ) ) {
			return '';
		}

		$css = wp_get_global_stylesheet();

		return is_string( $css ) ? $css : '';
	}

	/**
	 * Every CSS custom property the active theme defines.
	 *
	 * Harvested from the theme's own stylesheets plus theme.json global
	 * styles, so the editor can suggest the variables that actually resolve on
	 * this site rather than a hardcoded list that only fits one theme.
	 *
	 * @since 2.7.1
	 * @return array<int,string> Sorted property names, each including the leading `--`.
	 */
	public function get_theme_css_variables() {
		if ( $this->theme_css_variables !== null ) {
			return $this->theme_css_variables;
		}

		$files = $this->get_theme_style_files();

		$cache_parts = array( get_stylesheet(), 'v2' );
		foreach ( $files as $file ) {
			$cache_parts[] = $file . ':' . (string) filemtime( $file );
		}
		$cache_key = 'gt_pb_vars_' . md5( implode( '|', $cache_parts ) );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$this->theme_css_variables = $cached;
			return $this->theme_css_variables;
		}

		$map = array();

		$harvest = function ( $css ) use ( &$map ) {
			// Only declarations (`--x:`), never usages (`var(--x)`), so the
			// list stays to variables this site actually defines.
			if ( ! preg_match_all( '/(--[A-Za-z0-9_-]+)\s*:/', (string) $css, $m ) ) {
				return;
			}
			foreach ( $m[1] as $name ) {
				$map[ $name ] = true;
				if ( count( $map ) >= 2000 ) {
					return;
				}
			}
		};

		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );
			if ( $contents !== false ) {
				$harvest( $contents );
			}
			if ( count( $map ) >= 2000 ) {
				break;
			}
		}

		$harvest( $this->get_preview_global_css() );

		$vars = array_keys( $map );
		sort( $vars, SORT_NATURAL | SORT_FLAG_CASE );

		set_transient( $cache_key, $vars, DAY_IN_SECONDS );

		$this->theme_css_variables = $vars;
		return $this->theme_css_variables;
	}

	/**
	 * Public alias for theme CSS classes (used by builder JS config).
	 *
	 * @return array
	 */
	public function get_theme_css_classes_for_builder() {
		return $this->get_theme_class_suggestions();
	}

	/**
	 * Get condensed CSS context for AI prompts.
	 *
	 * Reads the theme's stylesheet to extract CSS variables (custom properties)
	 * and a sample of utility class names. Sent to AI as system prompt context.
	 *
	 * @return string CSS context (max ~8KB).
	 */
	public function get_ai_css_context() {
		$cache_key = 'gt_pb_ai_css_context_' . md5( get_stylesheet() );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$context = '';
		$files   = $this->get_theme_style_files();

		// Extract :root { --* : value; } CSS variable definitions
		$variables_block = '';
		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );
			if ( ! $contents ) continue;

			if ( preg_match_all( '/--[A-Za-z0-9_-]+\s*:\s*[^;]+;/', $contents, $vm ) && ! empty( $vm[0] ) ) {
				foreach ( array_slice( $vm[0], 0, 100 ) as $v ) {
					$variables_block .= trim( $v ) . "\n";
				}
				if ( strlen( $variables_block ) > 4000 ) break;
			}
		}

		if ( ! empty( $variables_block ) ) {
			$context .= ":root {\n" . $variables_block . "}\n\n";
		}

		// Sample of utility classes
		$classes = $this->get_theme_class_suggestions();
		if ( ! empty( $classes ) ) {
			$context .= '/* Available utility classes: */ ' . implode( ' ', array_slice( $classes, 0, 200 ) );
		}

		// Cap at ~8KB
		if ( strlen( $context ) > 8000 ) {
			$context = substr( $context, 0, 8000 ) . "\n/* ... truncated ... */";
		}

		set_transient( $cache_key, $context, DAY_IN_SECONDS );

		return $context;
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
	 * Delegates to gt_pb_execute_php() so there is a single gate. Callers
	 * must pass the save-time checksum; an empty checksum (inline blocks,
	 * unsaved previews) means the PHP is stripped rather than run.
	 *
	 * @param string $content  Raw content.
	 * @param string $checksum md5 of $content recorded at save time.
	 * @return string
	 */
	private function execute_php( $content, $checksum = '' ) {
		return gt_pb_execute_php( (string) $content, (string) $checksum );
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
			$css      = $block['attrs']['css'] ?? '';
			$output   = $block['attrs']['output'] ?? 'inline';
			$block_id = isset( $block['attrs']['blockId'] ) ? (int) $block['attrs']['blockId'] : 0;

			// Reference blocks carry no inline CSS — pull it from the library
			// row so a referenced block's styles reach <head> like any other,
			// and only once however many times the block appears.
			if ( $block_id > 0 ) {
				if ( ! empty( $this->library_css_done[ $block_id ] ) ) {
					continue;
				}

				$row = $this->db->get( $block_id );
				if ( ! $row || 'publish' !== $row->status || empty( $row->css ) ) {
					continue;
				}

				$this->library_css_done[ $block_id ] = true;
				$css    = (string) $row->css;
				$output = (string) ( $row->output ?: 'inline' );
			}

			if ( ! $css ) {
				continue;
			}

			if ( $output === 'file' ) {
				$file_parts[] = self::sanitize_css( $css );
			} else {
				$key = md5( (string) $css );
				if ( isset( $this->inline_css_done[ $key ] ) ) {
					continue;
				}
				$this->inline_css_done[ $key ] = true;
				$inline_parts[] = self::sanitize_css( $css );
			}
		}

		if ( empty( $inline_parts ) && empty( $file_parts ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post || ! isset( $post->ID ) ) {
			return;
		}
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

		$post = get_queried_object();
		if ( ! $post || ! isset( $post->ID ) ) {
			return;
		}
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

		$version = (string) ( filemtime( $info['path'] ) ?: '0' );
		$id      = 'gt-page-blocks-' . $prefix . (string) $post_id;

		if ( $extension === 'js' ) {
			echo '<script src="' . esc_url( $info['url'] ) . '?ver=' . esc_attr( $version ) . '"></script>' . "\n";
		} else {
			echo '<link rel="stylesheet" id="' . esc_attr( $id ) . '" href="' . esc_url( $info['url'] ) . '?ver=' . esc_attr( $version ) . '" media="all" />' . "\n";
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

		if ( ! in_array( $post->post_type, gt_page_blocks_builder_post_types(), true ) ) {
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

		// Comments go first, before anything is stashed, so a commented-out
		// quote cannot unbalance the string scan below.
		$css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );

		// Quoted strings and url() payloads are literal values: whitespace
		// inside them is significant. Collapsing it silently rewrites
		// selectors — [style*="font-weight: 300"] and
		// [style*="font-weight:300"] are different selectors, and squashing
		// the first into the second drops every element it used to match.
		// Stash them, minify the structure, then put them back verbatim.
		$stash = array();

		// Math functions must be stashed BEFORE the structural pass: the space
		// around `+` is required inside calc()/clamp()/min()/max(), but `+` is
		// also the sibling combinator, where collapsing it is correct. Removing
		// it inside a clamp silently invalidates the whole declaration — that
		// is how `clamp(6.75rem, 6rem + 2.2vw, 9rem)` became `6rem+2.2vw` and
		// section padding computed to 0. (?1) recurses the parenthesised group
		// so nested var()/calc() are captured whole.
		$css = preg_replace_callback(
			'/\b(?:calc|clamp|min|max|minmax)\s*(\((?:[^()]++|(?1))*\))/i',
			static function ( $m ) use ( &$stash ) {
				$token = "\x01" . count( $stash ) . "\x02";
				$stash[ $token ] = $m[0];
				return $token;
			},
			$css
		);

		$css   = preg_replace_callback(
			'/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\burl\([^)\'"]*\)/s',
			static function ( $m ) use ( &$stash ) {
				$token = "\x01" . count( $stash ) . "\x02";
				$stash[ $token ] = $m[0];
				return $token;
			},
			$css
		);

		$css = str_replace( array( "\r\n", "\r", "\n", "\t" ), '', $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([\{\};:,~+])\s*/', '$1', $css );
		$css = preg_replace( '/\s*>(?!=)\s*/', '>', $css );
		$css = preg_replace( '/;}/', '}', $css );
		$css = trim( (string) $css );

		return $stash ? strtr( $css, $stash ) : $css;
	}

	/**
	 * Minify JS.
	 *
	 * @param string $js JS.
	 * @return string
	 */
	public static function minify_js( $js ) {
		$js = (string) $js;

		$preserved = array();
		$js = preg_replace_callback(
			'/([\'"`])(?:(?!\\1)[^\\\\]|\\\\.)*\\1/s',
			function ( $matches ) use ( &$preserved ) {
				$key               = '___JSSTR_' . count( $preserved ) . '___';
				$preserved[ $key ] = $matches[0];
				return $key;
			},
			$js
		);

		$js = preg_replace( '#/\*(?!!).*?\*/#s', '', $js );
		$js = preg_replace( '#(?<=[\s;{}(,=])//(?!/)[^\n]*#', '', $js );
		$js = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', $js );
		$js = preg_replace( '/\s+/', ' ', $js );
		$js = preg_replace( '/\s*([{};,])\s*/', '$1', $js );

		$js = str_replace( array_keys( $preserved ), array_values( $preserved ), $js );

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

		$prompt      = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$tab         = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'html';
		$model       = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$existing    = isset( $_POST['existing_code'] ) ? wp_unslash( $_POST['existing_code'] ) : '';
		$selection   = isset( $_POST['selection'] ) ? wp_unslash( $_POST['selection'] ) : '';
		$ctx_html    = isset( $_POST['context_html'] ) ? wp_unslash( $_POST['context_html'] ) : '';
		$ctx_css     = isset( $_POST['context_css'] ) ? wp_unslash( $_POST['context_css'] ) : '';
		$css_ctx     = isset( $_POST['css_context'] ) ? wp_unslash( $_POST['css_context'] ) : '';
		$ctx_page    = isset( $_POST['context_page'] ) ? wp_unslash( $_POST['context_page'] ) : '';
		$history_raw = isset( $_POST['history'] ) ? wp_unslash( $_POST['history'] ) : '';
		$page_url    = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';

		if ( empty( $prompt ) ) {
			wp_send_json_error( array( 'message' => __( 'Prompt is required.', 'page-blocks-builder' ) ), 400 );
		}

		if ( ! in_array( $tab, array( 'html', 'css', 'js' ), true ) ) {
			$tab = 'html';
		}

		// Parse conversation history
		$history = array();
		if ( ! empty( $history_raw ) ) {
			$decoded = json_decode( (string) $history_raw, true );
			if ( is_array( $decoded ) ) {
				$history = $decoded;
			}
		}

		$result = $this->call_ai_api( $model, $tab, $prompt, $existing, $selection, $ctx_html, $ctx_css, $page_url, $css_ctx, $history, $ctx_page );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'code' => $result ) );
	}

	private function call_ai_api( $model, $tab, $prompt, $existing, $selection, $ctx_html, $ctx_css, $page_url, $css_context = '', $history = array(), $ctx_page = '' ) {
		if ( empty( $model ) || ! in_array( $model, self::ai_model_ids(), true ) ) {
			$model = self::ai_stored_default_model();
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

		// Add CSS context to system prompt when provided
		if ( ! empty( $css_context ) ) {
			$system_prompt .= "\n\nAVAILABLE CSS VARIABLES AND UTILITY CLASSES:\n" . $css_context;
		}

		$user_message = $this->build_ai_user_message( $prompt, $tab, $existing, $selection, $ctx_html, $ctx_css, $ctx_page );

		$result = $this->execute_ai_provider_call( $provider, $api_key, $model, $system_prompt, $user_message, $history );
		if ( ! $this->should_retry_ai_compact( $result ) ) {
			return $result;
		}

		$this->maybe_log_ai_debug(
			$provider,
			'auto_retry',
			array(
				'trigger' => 'finish_reason=length',
				'tab'     => $tab,
				'model'   => $model,
			)
		);

		$retry_user_message = $this->build_ai_compact_retry_message( $user_message, $tab );
		return $this->execute_ai_provider_call( $provider, $api_key, $model, $system_prompt, $retry_user_message, $history );
	}

	private function execute_ai_provider_call( $provider, $api_key, $model, $system_prompt, $user_message, $history = array() ) {
		switch ( $provider ) {
			case 'openai':
				return $this->call_openai( $api_key, $model, $system_prompt, $user_message, $history );
			case 'anthropic':
				return $this->call_anthropic( $api_key, $model, $system_prompt, $user_message, $history );
			case 'gemini':
				return $this->call_gemini( $api_key, $model, $system_prompt, $user_message, $history );
			default:
				return new WP_Error( 'invalid_provider', __( 'Invalid provider.', 'page-blocks-builder' ) );
		}
	}

	private function should_retry_ai_compact( $result ) {
		if ( ! is_wp_error( $result ) || 'empty_ai_output' !== $result->get_error_code() ) {
			return false;
		}

		$data = $result->get_error_data();
		if ( ! is_array( $data ) || empty( $data['debug']['finish_reason'] ) || ! is_array( $data['debug']['finish_reason'] ) ) {
			return false;
		}

		$token_limit_reasons = array(
			'length',
			'max_tokens',
			'max_output_tokens',
			'max_token_limit',
			'token_limit',
		);

		foreach ( $data['debug']['finish_reason'] as $reason ) {
			if ( in_array( strtolower( trim( (string) $reason ) ), $token_limit_reasons, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function build_ai_compact_retry_message( $user_message, $tab ) {
		$compact = array(
			'html' => 'Retry in compact mode: previous output hit token length limit. Return concise section HTML only, keeping the full answer under about 120 lines. Include exactly one <style id="ai-generated"> and one <script id="ai-generated"> at the end, both compact.',
			'css'  => 'Retry in compact mode: previous output hit token length limit. Return concise CSS only, under about 120 lines, with only essential rules used by this section.',
			'js'   => 'Retry in compact mode: previous output hit token length limit. Return concise vanilla JS only, under about 100 lines, with only essential behavior.',
		);

		$instruction = isset( $compact[ $tab ] ) ? $compact[ $tab ] : $compact['html'];

		return $user_message . "\n\n" . $instruction;
	}

	private function get_ai_system_prompt( $tab, $page_url ) {
		$base = 'You are generating code for a standalone WordPress Page Block section. Each section has its own HTML, CSS, and JS tabs. A page can have multiple sections. Your output goes directly into one tab of one section.';
		if ( ! empty( $page_url ) ) {
			$base .= "\nThe page this code appears on is: " . $page_url;
		}

		switch ( $tab ) {
			case 'html':
				$base .= "\n\nHTML TAB RULES:\n- Generate section-level HTML only (the content inside a single section).\n- Use semantic elements with descriptive class names.\n- No <!DOCTYPE>, <html>, <head>, or <body> tags.\n- Include section CSS and JS at the end using these exact tags so the builder can move them into their tabs:\n  <style id=\"ai-generated\">/* section CSS */</style>\n  <script id=\"ai-generated\">/* section JS */</script>\n- Keep selectors and JS targets aligned to classes in this section.";
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

	private function build_ai_user_message( $prompt, $tab, $existing, $selection, $ctx_html, $ctx_css, $ctx_page = '' ) {
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

		// The whole page, when the author asked for it. Capped, because a long
		// page can outrun the model's context window and the useful part is
		// the shape of the other sections, not every byte of them.
		if ( ! empty( $ctx_page ) ) {
			$page = (string) $ctx_page;
			if ( strlen( $page ) > self::AI_PAGE_CONTEXT_LIMIT ) {
				$page = substr( $page, 0, self::AI_PAGE_CONTEXT_LIMIT ) .
					"\n\n[truncated: the page is longer than this context allows]";
			}
			$parts[] = "Every section on this page, for reference. Match its markup conventions, " .
				"class names and CSS variables. Only return code for the section I am editing:\n" . $page;
		}

		if ( $tab === 'html' ) {
			$parts[] = "When returning HTML, always append these exact tags at the end:\n<style id=\"ai-generated\">/* css */</style>\n<script id=\"ai-generated\">/* js */</script>";
		}

		$parts[] = "Instruction: " . $prompt;

		return implode( "\n\n", $parts );
	}

	private function get_ai_http_timeout( $provider = '' ) {
		$timeout = (int) apply_filters( 'gt_pb_ai_request_timeout', 120, $provider );
		return max( 30, $timeout );
	}

	private function call_openai( $api_key, $model, $system_prompt, $user_message, $history = array() ) {
		// Build messages array with conversation history
		$messages = array( array( 'role' => 'system', 'content' => $system_prompt ) );
		foreach ( (array) $history as $msg ) {
			if ( isset( $msg['role'], $msg['content'] ) ) {
				$role       = $msg['role'] === 'assistant' ? 'assistant' : 'user';
				$messages[] = array( 'role' => $role, 'content' => (string) $msg['content'] );
			}
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_message );

		$payload = array(
			'model'       => $model,
			'messages'    => $messages,
			'max_completion_tokens' => 4096,
		);

		if ( strpos( $model, 'gpt-5' ) !== 0 ) {
			$payload['temperature'] = 0.3;
		}

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => $this->get_ai_http_timeout( 'openai' ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
		) );

		return $this->parse_ai_response( $response, 'openai' );
	}

	private function call_anthropic( $api_key, $model, $system_prompt, $user_message, $history = array() ) {
		// Build messages with history
		$messages = array();
		foreach ( (array) $history as $msg ) {
			if ( isset( $msg['role'], $msg['content'] ) ) {
				$role       = $msg['role'] === 'assistant' ? 'assistant' : 'user';
				$messages[] = array( 'role' => $role, 'content' => (string) $msg['content'] );
			}
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_message );

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => $this->get_ai_http_timeout( 'anthropic' ),
			'headers' => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'       => $model,
				'system'      => $system_prompt,
				'messages'    => $messages,
				'max_tokens'  => 4096,
				'temperature' => 0.3,
			) ),
		) );

		return $this->parse_ai_response( $response, 'anthropic' );
	}

	private function call_gemini( $api_key, $model, $system_prompt, $user_message, $history = array() ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

		// Build contents with history (Gemini uses 'role' = 'user' or 'model')
		$contents = array();
		foreach ( (array) $history as $msg ) {
			if ( isset( $msg['role'], $msg['content'] ) ) {
				$role       = $msg['role'] === 'assistant' ? 'model' : 'user';
				$contents[] = array( 'role' => $role, 'parts' => array( array( 'text' => (string) $msg['content'] ) ) );
			}
		}
		$contents[] = array( 'role' => 'user', 'parts' => array( array( 'text' => $user_message ) ) );

		$response = wp_remote_post( $url, array(
			'timeout' => $this->get_ai_http_timeout( 'gemini' ),
			'headers' => array(
				'x-goog-api-key' => $api_key,
				'Content-Type'   => 'application/json',
			),
			'body' => wp_json_encode( array(
				'system_instruction' => array(
					'parts' => array( array( 'text' => $system_prompt ) ),
				),
				'contents' => $contents,
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
			if ( $this->is_ai_timeout_error( $response ) ) {
				return new WP_Error(
					'ai_timeout',
					sprintf(
						__( 'AI request timed out after %d seconds. Try again, reduce prompt/context size, or switch to a faster model.', 'page-blocks-builder' ),
						$this->get_ai_http_timeout( $provider )
					),
					array(
						'provider'        => $provider,
						'transport_error' => $response->get_error_message(),
					)
				);
			}

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
				$text = $this->extract_openai_text( $body );
				break;
			case 'anthropic':
				$text = $this->extract_anthropic_text( $body );
				break;
			case 'gemini':
				$text = $this->extract_gemini_text( $body );
				break;
		}

		$debug_summary = $this->build_ai_provider_debug_summary( $provider, $body );
		$this->maybe_log_ai_debug( $provider, 'summary', $debug_summary );
		$this->maybe_log_ai_debug( $provider, 'raw_response', $body, true );

		$text = is_string( $text ) ? trim( $text ) : '';
		if ( preg_match( '/^```[\w]*\s*\n([\s\S]*?)```\s*$/s', $text, $fenced ) ) {
			$text = $fenced[1];
		} else {
			$text = preg_replace( '/^```[\w]*\s*/i', '', $text );
			$text = preg_replace( '/\s*```$/', '', $text );
		}
		$text = trim( (string) $text );

		if ( $text === '' ) {
			$details = array();
			if ( ! empty( $debug_summary['finish_reason'] ) ) {
				$details[] = 'finish_reason=' . implode( ',', array_slice( $debug_summary['finish_reason'], 0, 3 ) );
			}
			if ( ! empty( $debug_summary['refusal'] ) ) {
				$details[] = 'refusal=' . $this->truncate_ai_debug_value( $debug_summary['refusal'][0], 180 );
			}

			$message = __( 'AI returned an empty response. Try again or switch the model.', 'page-blocks-builder' );
			if ( ! empty( $details ) ) {
				$message .= ' (' . implode( '; ', $details ) . ')';
			}

			return new WP_Error(
				'empty_ai_output',
				$message,
				array(
					'provider' => $provider,
					'debug'    => $debug_summary,
				)
			);
		}

		return $text;
	}

	/**
	 * Extract text output from OpenAI responses across known shapes.
	 *
	 * @param array $body Decoded response body.
	 * @return string
	 */
	private function extract_openai_text( $body ) {
		$chunks  = array();
		$choice  = isset( $body['choices'][0] ) && is_array( $body['choices'][0] ) ? $body['choices'][0] : array();
		$message = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();

		$content = isset( $message['content'] ) ? $message['content'] : null;
		if ( is_string( $content ) && $content !== '' ) {
			$chunks[] = $content;
		} elseif ( is_array( $content ) ) {
			$this->collect_text_chunks( $content, $chunks );
		}

		if ( empty( $chunks ) && isset( $choice['text'] ) && is_string( $choice['text'] ) && $choice['text'] !== '' ) {
			$chunks[] = $choice['text'];
		}

		$output_text = isset( $body['output_text'] ) ? $body['output_text'] : null;
		if ( is_string( $output_text ) && $output_text !== '' ) {
			$chunks[] = $output_text;
		} elseif ( is_array( $output_text ) ) {
			foreach ( $output_text as $line ) {
				if ( is_string( $line ) && $line !== '' ) {
					$chunks[] = $line;
				}
			}
		}

		if ( ! empty( $body['output'] ) && is_array( $body['output'] ) ) {
			$this->collect_text_chunks( $body['output'], $chunks );
		}

		if ( empty( $chunks ) && isset( $message['refusal'] ) && is_string( $message['refusal'] ) && $message['refusal'] !== '' ) {
			$chunks[] = $message['refusal'];
		}

		$chunks = array_values( array_unique( array_filter( array_map( 'trim', $chunks ) ) ) );

		return implode( "\n", $chunks );
	}

	/**
	 * Recursively collect text-like chunks from nested API payloads.
	 *
	 * @param mixed $node   Node to inspect.
	 * @param array $chunks Collected chunks (by reference).
	 */
	private function collect_text_chunks( $node, &$chunks ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( $node as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, array( 'text', 'output_text' ), true ) && is_string( $value ) && $value !== '' ) {
				$chunks[] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$this->collect_text_chunks( $value, $chunks );
			}
		}
	}

	/**
	 * Extract text blocks from Anthropic response payload.
	 *
	 * @param array $body Decoded response body.
	 * @return string
	 */
	private function extract_anthropic_text( $body ) {
		$chunks = array();

		if ( ! empty( $body['content'] ) && is_array( $body['content'] ) ) {
			foreach ( $body['content'] as $block ) {
				if ( isset( $block['text'] ) && is_string( $block['text'] ) && $block['text'] !== '' ) {
					$chunks[] = $block['text'];
				}
			}
		}

		$chunks = array_values( array_unique( array_filter( array_map( 'trim', $chunks ) ) ) );
		return implode( "\n", $chunks );
	}

	/**
	 * Extract text parts from Gemini response payload.
	 *
	 * @param array $body Decoded response body.
	 * @return string
	 */
	private function extract_gemini_text( $body ) {
		$chunks = array();

		if ( ! empty( $body['candidates'] ) && is_array( $body['candidates'] ) ) {
			foreach ( $body['candidates'] as $candidate ) {
				$parts = isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ? $candidate['content']['parts'] : array();

				foreach ( $parts as $part ) {
					if ( isset( $part['text'] ) && is_string( $part['text'] ) && $part['text'] !== '' ) {
						$chunks[] = $part['text'];
					}
				}
			}
		}

		$chunks = array_values( array_unique( array_filter( array_map( 'trim', $chunks ) ) ) );
		return implode( "\n", $chunks );
	}

	/**
	 * Build compact debug summary for a provider response.
	 *
	 * @param string $provider Provider key.
	 * @param array  $body     Decoded response body.
	 * @return array
	 */
	private function build_ai_provider_debug_summary( $provider, $body ) {
		$finish_reason = array();
		$refusal       = $this->collect_scalar_values_by_key( $body, 'refusal' );
		$model         = '';
		$usage         = array();

		switch ( $provider ) {
			case 'openai':
				$finish_reason = $this->collect_scalar_values_by_key( $body, 'finish_reason' );
				$model         = isset( $body['model'] ) && is_string( $body['model'] ) ? $body['model'] : '';
				$usage         = isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : array();
				break;
			case 'anthropic':
				$finish_reason = $this->collect_scalar_values_by_key( $body, 'stop_reason' );
				$model         = isset( $body['model'] ) && is_string( $body['model'] ) ? $body['model'] : '';
				$usage         = isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : array();
				break;
			case 'gemini':
				$finish_reason = $this->collect_scalar_values_by_key( $body, 'finishReason' );
				$model         = isset( $body['modelVersion'] ) && is_string( $body['modelVersion'] ) ? $body['modelVersion'] : '';
				$usage         = isset( $body['usageMetadata'] ) && is_array( $body['usageMetadata'] ) ? $body['usageMetadata'] : array();
				$refusal       = array_merge( $refusal, $this->collect_scalar_values_by_key( $body, 'blockReason' ) );
				break;
		}

		$finish_reason = array_values( array_unique( array_filter( array_map( 'trim', $finish_reason ) ) ) );
		$refusal       = array_values( array_unique( array_filter( array_map( 'trim', $refusal ) ) ) );

		return array(
			'provider'      => $provider,
			'model'         => $model,
			'finish_reason' => $finish_reason,
			'refusal'       => array_slice( $refusal, 0, 3 ),
			'usage'         => $usage,
		);
	}

	/**
	 * Collect scalar values for a specific key recursively.
	 *
	 * @param mixed  $node Node to inspect.
	 * @param string $key  Key to collect.
	 * @return array
	 */
	private function collect_scalar_values_by_key( $node, $key ) {
		$values = array();
		$this->collect_scalar_values_by_key_recursive( $node, $key, $values );
		return $values;
	}

	/**
	 * Recursive implementation for scalar value collection by key.
	 *
	 * @param mixed  $node   Node to inspect.
	 * @param string $key    Key to collect.
	 * @param array  $values Accumulator (by reference).
	 */
	private function collect_scalar_values_by_key_recursive( $node, $key, &$values ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( $node as $node_key => $value ) {
			if ( (string) $node_key === (string) $key ) {
				if ( is_scalar( $value ) || $value === null ) {
					$values[] = $value === null ? 'null' : (string) $value;
				} elseif ( is_array( $value ) ) {
					foreach ( $value as $item ) {
						if ( is_scalar( $item ) || $item === null ) {
							$values[] = $item === null ? 'null' : (string) $item;
						}
					}
				}
			}

			if ( is_array( $value ) ) {
				$this->collect_scalar_values_by_key_recursive( $value, $key, $values );
			}
		}
	}

	/**
	 * Truncate debug values to keep errors/logs readable.
	 *
	 * @param string $value Input string.
	 * @param int    $limit Max characters.
	 * @return string
	 */
	private function truncate_ai_debug_value( $value, $limit = 180 ) {
		$value = trim( (string) $value );
		$limit = max( 20, absint( $limit ) );

		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, $limit ) . '...';
	}

	/**
	 * Log AI diagnostics when debug is enabled.
	 *
	 * @param string $provider Provider key.
	 * @param string $label    Diagnostic label.
	 * @param mixed  $payload  Diagnostic payload.
	 * @param bool   $is_raw   Whether payload is full raw API response.
	 */
	private function maybe_log_ai_debug( $provider, $label, $payload, $is_raw = false ) {
		$enabled = apply_filters( 'gt_pb_ai_debug_enabled', defined( 'WP_DEBUG' ) && WP_DEBUG );
		if ( ! $enabled ) {
			return;
		}

		if ( $is_raw && ! apply_filters( 'gt_pb_ai_debug_log_raw_payload', false, $provider, $label ) ) {
			return;
		}

		$max_len = (int) apply_filters( 'gt_pb_ai_debug_max_length', 6000, $provider, $label );
		$max_len = $max_len > 0 ? $max_len : 6000;

		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			$encoded = print_r( $payload, true );
		}

		if ( strlen( $encoded ) > $max_len ) {
			$encoded = substr( $encoded, 0, $max_len ) . '...[truncated]';
		}

		error_log( '[GT Page Blocks AI][' . $provider . '][' . $label . '] ' . $encoded );
	}

	private function is_ai_timeout_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$message = strtolower( implode( ' ', $error->get_error_messages() ) );

		if ( false !== strpos( $message, 'curl error 28' ) ) {
			return true;
		}

		return false !== strpos( $message, 'timed out' );
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

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout   = '';
		$stderr   = '';
		$max_size = 1048576; // 1 MB
		$deadline = time() + 30;

		while ( true ) {
			$read = array();
			if ( is_resource( $pipes[1] ) ) {
				$read[] = $pipes[1];
			}
			if ( is_resource( $pipes[2] ) ) {
				$read[] = $pipes[2];
			}
			if ( empty( $read ) ) {
				break;
			}

			$write  = null;
			$except = null;
			$ready  = @stream_select( $read, $write, $except, 1 );

			if ( false === $ready ) {
				break;
			}

			foreach ( $read as $pipe ) {
				$chunk = fread( $pipe, 8192 );
				if ( false === $chunk || '' === $chunk ) {
					if ( feof( $pipe ) ) {
						if ( $pipe === $pipes[1] ) {
							fclose( $pipes[1] );
							$pipes[1] = null;
						} else {
							fclose( $pipes[2] );
							$pipes[2] = null;
						}
					}
					continue;
				}
				if ( $pipe === $pipes[1] ) {
					$stdout .= $chunk;
				} else {
					$stderr .= $chunk;
				}
			}

			if ( strlen( $stdout ) + strlen( $stderr ) > $max_size ) {
				$stderr .= "\n[Output truncated at 1 MB]";
				break;
			}

			if ( time() >= $deadline ) {
				$stderr .= "\n[Timed out after 30 seconds]";
				break;
			}
		}

		if ( is_resource( $pipes[1] ) ) {
			fclose( $pipes[1] );
		}
		if ( is_resource( $pipes[2] ) ) {
			fclose( $pipes[2] );
		}

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
