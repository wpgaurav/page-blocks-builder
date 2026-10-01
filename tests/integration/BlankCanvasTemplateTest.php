<?php
/** Blank canvas routing must work with either kind of WordPress theme. */

use PHPUnit\Framework\TestCase;

final class BlankCanvasTemplateTest extends TestCase {
	private static string $theme_root;
	private static array $theme_directories;
	private string $theme_slug;
	private int $post_id;
	private $previous_query;
	private $previous_post;
	private array $previous_get;

	public static function setUpBeforeClass(): void {
		self::$theme_directories = $GLOBALS['wp_theme_directories'];
		self::$theme_root        = sys_get_temp_dir() . '/pbb-template-qa-' . wp_generate_uuid4();
		foreach ( array( 'pbb-qa-classic', 'pbb-qa-block' ) as $slug ) {
			mkdir( self::$theme_root . '/' . $slug, 0700, true );
			file_put_contents( self::$theme_root . '/' . $slug . '/style.css', "/* Theme Name: $slug */" );
			file_put_contents( self::$theme_root . '/' . $slug . '/index.php', '<?php echo "THEME-SHELL";' );
		}
		mkdir( self::$theme_root . '/pbb-qa-block/templates', 0700 );
		file_put_contents( self::$theme_root . '/pbb-qa-block/templates/index.html', '<!-- wp:post-content /-->' );
		register_theme_directory( self::$theme_root );
	}

	public static function tearDownAfterClass(): void {
		$GLOBALS['wp_theme_directories'] = self::$theme_directories;
		unlink( self::$theme_root . '/pbb-qa-block/templates/index.html' );
		rmdir( self::$theme_root . '/pbb-qa-block/templates' );
		foreach ( array( 'pbb-qa-classic', 'pbb-qa-block' ) as $slug ) {
			unlink( self::$theme_root . '/' . $slug . '/index.php' );
			unlink( self::$theme_root . '/' . $slug . '/style.css' );
			rmdir( self::$theme_root . '/' . $slug );
		}
		rmdir( self::$theme_root );
	}

	protected function setUp(): void {
		$this->previous_query = $GLOBALS['wp_query'];
		$this->previous_post  = $GLOBALS['post'] ?? null;
		$this->previous_get   = $_GET;
		unset( $_GET['build'] );
		$this->post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Page title must not be injected',
				'post_content' => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Authored title</h1><!-- /wp:heading -->',
			)
		);
		update_post_meta( $this->post_id, '_wp_page_template', 'page-blocks-full-builder.php' );
		$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $this->post_id ) );
		$GLOBALS['post']     = get_post( $this->post_id );
	}

	protected function tearDown(): void {
		remove_filter( 'stylesheet', array( $this, 'fixture_theme_slug' ) );
		remove_filter( 'template', array( $this, 'fixture_theme_slug' ) );
		remove_filter( 'theme_root', array( $this, 'fixture_theme_root' ) );
		remove_filter( 'pre_site_transient_theme_roots', array( $this, 'fixture_theme_roots' ) );
		$GLOBALS['wp_query'] = $this->previous_query;
		$GLOBALS['post']     = $this->previous_post;
		$_GET                = $this->previous_get;
		wp_delete_post( $this->post_id, true );
	}

	public function fixture_theme_slug(): string {
		return $this->theme_slug;
	}

	public function fixture_theme_root(): string {
		return self::$theme_root;
	}

	public function fixture_theme_roots(): array {
		return array(
			'pbb-qa-classic' => self::$theme_root,
			'pbb-qa-block'   => self::$theme_root,
		);
	}

	public static function theme_types(): array {
		return array(
			'classic' => array( false ),
			'block'   => array( true ),
		);
	}

	/** @dataProvider theme_types */
	public function test_blank_template_is_selectable_and_routes_for_either_theme_type( bool $is_block ): void {
		$this->use_fixture_theme( $is_block );
		$plugin    = $GLOBALS['gt_page_blocks_builder'];
		$templates = wp_get_theme()->get_page_templates( get_post( $this->post_id ) );
		$this->assertSame( 'Blank canvas (no header or footer)', $templates['page-blocks-full-builder.php'] );
		$this->assertSame( ! $is_block, isset( $templates['page-blocks-builder.php'] ) );
		$this->assertSame( GT_PB_BUILDER_DIR . 'templates/page-blocks-full-builder.php', apply_filters( 'template_include', '/theme/template-canvas.php' ) );

		$method    = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'get_available_page_templates' );
		$available = array_column( $method->invoke( $plugin, $this->post_id ), 'label', 'slug' );
		$this->assertArrayHasKey( 'page-blocks-full-builder.php', $available );
		$this->assertSame( 'page-blocks-full-builder.php', get_page_template_slug( $this->post_id ) );

		// Ordinary theme templates remain theme-owned; viewing settings does not migrate them.
		update_post_meta( $this->post_id, '_wp_page_template', 'theme-owned-template' );
		$this->assertSame( '/theme/template-canvas.php', $plugin->load_page_template( '/theme/template-canvas.php' ) );
		$method->invoke( $plugin, $this->post_id );
		$this->assertSame( 'theme-owned-template', get_page_template_slug( $this->post_id ) );
	}

	/** @dataProvider theme_types */
	public function test_blank_render_preserves_authored_content_and_hooks_without_theme_shell( bool $is_block ): void {
		$this->use_fixture_theme( $is_block );
		$hooks          = array( 'wp_head', 'wp_body_open', 'wp_footer', 'get_header', 'get_footer' );
		$previous_hooks = array();
		foreach ( $hooks as $hook ) {
			$previous_hooks[ $hook ] = $GLOBALS['wp_filter'][ $hook ] ?? null;
			unset( $GLOBALS['wp_filter'][ $hook ] );
			add_action(
				$hook,
				static function () use ( $hook ) {
					echo '<!-- qa-' . esc_html( $hook ) . ' -->';
				}
			);
		}
		add_action( 'wp_head', array( $GLOBALS['gt_page_blocks_builder'], 'output_template_styles' ), 99 );
		ob_start();
		try {
			include GT_PB_BUILDER_DIR . 'templates/page-blocks-full-builder.php';
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
			foreach ( $previous_hooks as $hook => $callbacks ) {
				if ( null === $callbacks ) {
					unset( $GLOBALS['wp_filter'][ $hook ] );
				} else {
					$GLOBALS['wp_filter'][ $hook ] = $callbacks;
				}
			}
		}
		$this->assertStringContainsString( '<main id="main" class="page-blocks-main"', $html );
		$this->assertStringContainsString( 'Authored title', $html );
		$this->assertStringNotContainsString( 'Page title must not be injected', $html );
		foreach ( array( 'wp_head', 'wp_body_open', 'wp_footer' ) as $hook ) {
			$this->assertStringContainsString( '<!-- qa-' . $hook . ' -->', $html );
		}
		foreach ( array( 'get_header', 'get_footer' ) as $hook ) {
			$this->assertStringNotContainsString( '<!-- qa-' . $hook . ' -->', $html );
		}
		$this->assertStringContainsString( 'max-width:none;padding:0;margin:0;', $html );
		$this->assertStringContainsString( 'body.page-blocks-full-builder{margin:0;padding:0;}', $html );
	}

	public function test_blank_preview_uses_unconstrained_layout_and_does_not_replace_editor_shell(): void {
		$layout = GT_PB_Canvas_Editor::template_layout( $this->post_id, 'page-blocks-full-builder.php' );
		$this->assertSame( 'pb-preview-content', $layout['className'] );
		$this->assertStringContainsString( 'max-width:none;margin:0;padding:0;', $layout['css'] );
		$this->assertStringNotContainsString( 'wp--style--global--', $layout['css'] );
		$_GET['build'] = 'page-blocks';
		$this->assertSame( GT_PB_BUILDER_DIR . 'templates/builder-shell.php', $GLOBALS['gt_page_blocks_builder']->load_page_template( GT_PB_BUILDER_DIR . 'templates/builder-shell.php' ) );
	}

	private function use_fixture_theme( bool $is_block ): void {
		$this->theme_slug = $is_block ? 'pbb-qa-block' : 'pbb-qa-classic';
		add_filter( 'stylesheet', array( $this, 'fixture_theme_slug' ) );
		add_filter( 'template', array( $this, 'fixture_theme_slug' ) );
		add_filter( 'theme_root', array( $this, 'fixture_theme_root' ) );
		add_filter( 'pre_site_transient_theme_roots', array( $this, 'fixture_theme_roots' ) );
		$this->assertSame( $is_block, wp_is_block_theme() );
	}
}
