<?php
/**
 * A preview loads the theme's stylesheets but not its scripts. Content built on
 * the near-universal "start hidden, reveal on scroll" idiom therefore renders as
 * an empty box, and the author has no way to tell why.
 *
 * Reported against gatilab.com's homepage, whose blocks use:
 *   .gl-reveal          { opacity: 0; transform: translateY(40px) }
 *   .gl-reveal.visible  { opacity: 1; transform: translateY(0) }
 */

use PHPUnit\Framework\TestCase;

final class PreviewRevealTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( 'gt_pb_preview_reveal_enabled' );
		remove_all_filters( 'gt_pb_preview_reveal_classes' );
		remove_all_filters( 'gt_pb_preview_reveal_selector' );
	}

	private function script(): string {
		return $GLOBALS['gt_page_blocks_builder']->get_preview_reveal_script();
	}

	public function test_it_emits_a_script(): void {
		$js = $this->script();
		$this->assertNotSame( '', $js );
		$this->assertStringStartsWith( '(function(){', $js );
	}

	public function test_it_carries_the_common_trigger_classes(): void {
		$js = $this->script();
		foreach ( array( 'visible', 'is-visible', 'in-view', 'aos-animate' ) as $class ) {
			$this->assertStringContainsString( '"' . $class . '"', $js, "missing trigger class {$class}" );
		}
	}

	public function test_it_targets_reveal_shaped_selectors(): void {
		$this->assertStringContainsString( 'reveal', $this->script() );
	}

	/**
	 * The point of adding classes rather than forcing styles: an !important
	 * declaration outranks a CSS animation, so the obvious fix would break
	 * every genuine animation to repair the ones that never started.
	 */
	public function test_it_never_forces_styles_with_important(): void {
		$js = $this->script();
		$this->assertStringNotContainsString( '!important', $js );
		$this->assertStringNotContainsString( 'animation:none', str_replace( ' ', '', $js ) );
		$this->assertStringNotContainsString( 'transform:none', str_replace( ' ', '', $js ) );
	}

	public function test_the_opacity_fallback_skips_running_animations(): void {
		// The last-resort pass must not un-hide something mid-animation.
		$this->assertStringContainsString( 'animationName', $this->script() );
	}

	public function test_it_can_be_disabled(): void {
		add_filter( 'gt_pb_preview_reveal_enabled', '__return_false' );
		$this->assertSame( '', $this->script() );
	}

	public function test_trigger_classes_are_filterable(): void {
		add_filter( 'gt_pb_preview_reveal_classes', static fn() => array( 'theme-specific-class' ) );
		$this->assertStringContainsString( '"theme-specific-class"', $this->script() );
	}

	public function test_selector_is_filterable(): void {
		add_filter( 'gt_pb_preview_reveal_selector', static fn() => '.only-this' );
		$this->assertStringContainsString( '.only-this', $this->script() );
	}

	public function test_filter_output_is_json_encoded_not_interpolated(): void {
		// A class name containing a quote must not be able to break out of the
		// generated JavaScript.
		add_filter( 'gt_pb_preview_reveal_classes', static fn() => array( 'a"});alert(1);//' ) );
		$js = $this->script();
		$this->assertStringNotContainsString( 'alert(1);//"', $js );
		$this->assertStringContainsString( '\\"', $js, 'the class name should be escaped by wp_json_encode' );
	}
}
