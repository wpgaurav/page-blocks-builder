<?php
use PHPUnit\Framework\TestCase;

final class PerformanceAnalysisTest extends TestCase {
	public function test_analysis_counts_actual_modes_and_minifies_without_executing_authored_code(): void {
		$sections = array(
			array( 'name' => 'Hero', 'css' => '.a { color: red; }', 'cssOutput' => 'file', 'cssDefer' => true, 'content' => '<?php throw new Exception("Do not execute"); ?><img src="hero.webp" loading="lazy"><script src="app.js"></script>' ),
			array( 'name' => 'Other', 'css' => '.a { color: red; }', 'cssOutput' => 'file', 'js' => 'const x = 1; // note' ),
			array( 'css' => '.c{}', 'output' => 'file' ),
			array( 'css' => '.d{}', 'output' => 'file' ),
		);
		$result = gt_pb_performance::analyze( $sections, new gt_pb_db() );
		$this->assertCount( 4, $result['rows'] );
		$this->assertSame( 3, $result['totals']['css_requests'] );
		$this->assertSame( 1, $result['totals']['loader_requests'] );
		$this->assertSame( strlen( '.a{color:red}' ), $result['rows'][0]['css_minified_bytes'] );
		$this->assertCount( 4, $result['rows'][0]['notes'] );
		$this->assertStringContainsString( 'Exact CSS duplicate of Hero', implode( ' ', $result['rows'][1]['notes'] ) );
		$this->assertSame( strlen( $sections[1]['js'] ), $result['rows'][1]['js_bytes'] );
	}

	public function test_section_scope_does_not_guess_that_a_selected_section_is_the_hero(): void {
		$result = gt_pb_performance::analyze( array( array( 'css' => '.later{}', 'cssOutput' => 'file', 'cssDefer' => true, 'content' => '<img width="20" height="30" loading="lazy" src="later.jpg">' ) ), new gt_pb_db(), false );
		$this->assertSame( array(), $result['rows'][0]['notes'] );
	}

	public function test_library_source_is_resolved_and_duplicate_library_file_is_counted_once(): void {
		$db = new gt_pb_db();
		$id = $db->insert( array( 'title' => 'Performance fixture', 'status' => 'publish', 'css' => '.library{color:red}', 'content' => '<p>Library</p>', 'output' => 'file' ) );
		try {
			$result = gt_pb_performance::analyze( array( array( 'blockId' => $id, 'css' => 'ignored' ), array( 'blockId' => $id ) ), $db );
			$this->assertSame( strlen( '.library{color:red}' ), $result['rows'][0]['css_bytes'] );
			$this->assertSame( 1, $result['totals']['css_requests'] );
		} finally { $db->delete( $id ); }
	}

	public function test_foreign_container_includes_nested_page_blocks_without_rendering_it(): void {
		$raw = '<!-- wp:group --><div><!-- wp:gt-page-block/page-block {"css":".nested{}","cssOutput":"file"} /--></div><!-- /wp:group -->';
		$result = gt_pb_performance::analyze( array( array( 'kind' => 'foreign', 'serialized' => $raw ) ), new gt_pb_db() );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 1, $result['totals']['css_requests'] );
	}

	public function test_image_hints_ignore_correct_dimensions_and_report_duplicate_font_preloads(): void {
		$html = '<img src="one.jpg" width="30" height="40" fetchpriority="high"><img src="two.jpg" width="30" height="40" fetchpriority="high"><link rel="preload" as="font" href="font.woff2"><link rel="preload" as="font" href="font.woff2"><script src="ok.js" defer></script>';
		$result = gt_pb_performance::analyze( array( array( 'content' => $html ) ), new gt_pb_db() );
		$this->assertCount( 1, $result['rows'][0]['notes'] );
		$this->assertStringContainsString( 'Repeated font preload', $result['rows'][0]['notes'][0] );
		$this->assertStringContainsString( 'Several images', implode( ' ', $result['notes'] ) );
	}
}
