<?php
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CanvasApiFloorTest extends TestCase {
	#[RunInSeparateProcess]
	public function test_wordpress_without_style_engine_keeps_the_builder_available(): void {
		define( 'ABSPATH', __DIR__ );
		function wp_is_block_theme() {
			return true; }
		function wp_get_layout_style() {
			throw new RuntimeException( 'The unsupported layout path must not execute' ); }
		require __DIR__ . '/../../includes/class-canvas-editor.php';
		$this->assertSame( array(), GT_PB_Canvas_Editor::template_layout( 1, 'default-template' ) );
	}
}
