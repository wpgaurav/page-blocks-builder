<?php
use PHPUnit\Framework\TestCase;

final class VisualBuilderTest extends TestCase {
	private function data(): array {
		return array(
			'version' => 1,
			'root' => array(
				'id' => 'vsection123', 'type' => 'section', 'props' => array(),
				'styles' => array( 'desktop' => array( 'backgroundColor' => '#f4f4f4' ) ),
				'children' => array(
					array(
						'id' => 'vcontainer123', 'type' => 'container', 'props' => array(),
						'styles' => array(), 'children' => array(
							array(
								'id' => 'vheading123', 'type' => 'heading',
								'props' => array( 'level' => 1, 'text' => 'Build <visually>' ),
								'styles' => array( 'desktop' => array( 'fontSize' => '42px' ), 'mobile' => array( 'fontSize' => '28px' ) ),
								'children' => array(),
							),
						),
					),
				),
			),
		);
	}

	public function test_visual_tree_compiles_to_safe_html_and_responsive_css(): void {
		$result = GT_PB_Visual_Builder::compile( $this->data() );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( '<h1', $result['content'] );
		$this->assertStringContainsString( 'Build &lt;visually&gt;', $result['content'] );
		$this->assertStringContainsString( 'data-pb-v-id="vheading123"', $result['content'] );
		$this->assertStringContainsString( '@media(max-width:480px)', $result['css'] );
		$this->assertStringContainsString( 'font-size:28px', $result['css'] );
		$this->assertStringContainsString( '"tablet":{}', (string) wp_json_encode( $result['visualData'] ) );
	}

	public function test_visual_data_survives_block_serialization_and_server_render(): void {
		$normalize = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'normalize_builder_section' );
		$attrs = $normalize->invoke( $GLOBALS['gt_page_blocks_builder'], array(
			'visualData' => $this->data(),
			'js' => 'alert("must not run")',
			'phpExec' => true,
		) );
		$this->assertSame( '', $attrs['js'] );
		$this->assertFalse( $attrs['phpExec'] );
		$this->assertArrayHasKey( 'visualData', $attrs );
		$serialized = serialize_block( array(
			'blockName' => GT_Page_Blocks_Builder::BLOCK_NAME,
			'attrs' => $attrs,
			'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(),
		) );
		$parsed = parse_blocks( $serialized );
		$this->assertSame( $this->data()['root']['id'], $parsed[0]['attrs']['visualData']['root']['id'] );
		$rendered = $GLOBALS['gt_page_blocks_builder']->render_block( $parsed[0]['attrs'] );
		$this->assertStringContainsString( 'Build &lt;visually&gt;', $rendered );
		$this->assertStringNotContainsString( 'alert(', $rendered );
	}

	public function test_invalid_nodes_are_rejected_and_style_injection_is_removed(): void {
		$data = $this->data();
		$data['root']['children'][0]['children'][0]['styles']['desktop']['color'] = 'red;}</style><script>alert(1)</script>';
		$result = GT_PB_Visual_Builder::compile( $data );
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( 'script', $result['css'] );
		$this->assertArrayNotHasKey( 'color', (array) $result['visualData']['root']['children'][0]['children'][0]['styles']['desktop'] );
		$data['root']['children'][0]['id'] = $data['root']['id'];
		$this->assertInstanceOf( WP_Error::class, GT_PB_Visual_Builder::compile( $data ) );
		$data = $this->data();
		$data['root']['children'][0]['styles'] = 'not a style map';
		$this->assertIsArray( GT_PB_Visual_Builder::compile( $data ) );
		$data['root']['children'][0]['props'] = array( 'text' => array( 'nested' ) );
		$this->assertInstanceOf( WP_Error::class, GT_PB_Visual_Builder::compile( $data ) );
	}
}
