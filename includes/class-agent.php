<?php
/**
 * Commands for AI agents, such as those connected through Site Agent's MCP server.
 *
 * Library commands run through the plugin's own pbb/v1 REST routes, so validation and
 * permissions match the REST API. Page commands read and write page-block sections with
 * WordPress's block parser and serializer, so the block delimiter JSON is always escaped
 * correctly. Published pages are changed through an autosave unless publishing is asked for.
 *
 * @package GT_Page_Blocks_Builder
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are returned to the agent as JSON data, never printed as HTML.

/** Agent API: gt_pb_agent( 'command', $input ) returns array( 'ok' => bool, ... ) and never throws. */
final class GT_PB_Agent {
	const VERSION = 1;

	/** Section attributes an agent may set. PHP execution is never available to agents. */
	const SECTION_ATTRS = array( 'name', 'content', 'css', 'js', 'jsLocation', 'format', 'output', 'cssOutput', 'cssDefer', 'blockId', 'blockSlug', 'respectConditions' );

	/** Library fields an agent may write through REST. */
	const BLOCK_FIELDS = array( 'title', 'slug', 'status', 'content', 'css', 'js', 'js_location', 'output', 'format', 'position', 'priority', 'conditions', 'tags', 'description' );

	/**
	 * Run one command.
	 *
	 * @param string               $command Command name.
	 * @param array<string, mixed> $input   Command input.
	 * @return array<string, mixed>
	 */
	public static function call( string $command, array $input = array() ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array(
				'ok'    => false,
				'error' => 'This WordPress user cannot edit content.',
			);
		}
		try {
			$result = match ( $command ) {
				'context'          => self::context( $input ),
				'blocks.list'      => self::blocks_list( $input ),
				'blocks.get'       => self::blocks_get( $input ),
				'blocks.create'    => self::blocks_create( $input ),
				'blocks.update'    => self::blocks_update( $input ),
				'blocks.duplicate' => self::blocks_duplicate( $input ),
				'blocks.render'    => self::blocks_render( $input ),
				'blocks.trash'     => self::blocks_trash( $input ),
				'section.markup'   => self::section_markup( $input ),
				'page.sections'    => self::page_sections( $input ),
				'page.section'     => self::page_section( $input ),
				'page.set_section' => self::page_set_section( $input ),
				'page.create'      => self::page_create( $input ),
				default            => throw new InvalidArgumentException( 'Unknown command: ' . $command ),
			};
			return array( 'ok' => true ) + $result;
		} catch ( \Throwable $error ) {
			return array(
				'ok'    => false,
				'error' => $error->getMessage(),
			);
		}
	}

	/**
	 * Dispatch to the plugin's own REST route.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	private static function rest( string $method, string $route, array $params = array() ): array {
		$request = new WP_REST_Request( $method, '/pbb/v1' . $route );
		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );
		if ( $response->is_error() ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'Request failed.';
			throw new RuntimeException( $message );
		}
		$out     = array( 'data' => $data );
		$headers = $response->get_headers();
		if ( isset( $headers['X-WP-Total'] ) ) {
			$out['total'] = (int) $headers['X-WP-Total'];
		}
		return $out;
	}

	private static function id( array $input, string $key = 'id' ): int {
		$id = absint( $input[ $key ] ?? 0 );
		if ( ! $id ) {
			throw new InvalidArgumentException( 'Pass a numeric ' . $key . '.' );
		}
		return $id;
	}

	/**
	 * Library fields from input, refusing PHP.
	 *
	 * @return array<string, mixed>
	 */
	private static function block_fields( array $fields ): array {
		if ( ! empty( $fields['php_exec'] ) ) {
			throw new InvalidArgumentException( 'Agents cannot enable PHP execution. Ask an administrator to do that in wp-admin.' );
		}
		$unknown = array_diff( array_keys( $fields ), self::BLOCK_FIELDS, array( 'php_exec' ) );
		if ( $unknown ) {
			throw new InvalidArgumentException( 'Unsupported block fields: ' . implode( ', ', $unknown ) );
		}
		unset( $fields['php_exec'] );
		return $fields;
	}

	private static function context( array $input ): array {
		unset( $input );
		$counts = array();
		foreach ( array( 'publish', 'draft' ) as $status ) {
			$counts[ $status ] = (int) ( self::rest(
				'GET',
				'/blocks',
				array(
					'status'   => $status,
					'per_page' => 1,
					'context'  => 'summary',
				)
			)['total'] ?? 0 );
		}
		return array(
			'api_version'       => self::VERSION,
			'plugin_version'    => GT_PB_BUILDER_VERSION,
			'site_url'          => home_url( '/' ),
			'post_types'        => array_values( (array) gt_page_blocks_builder_post_types() ),
			'positions'         => array_keys( (array) gt_pb_get_positions() ),
			'library_counts'    => $counts,
			'can_write_library' => current_user_can( 'manage_options' ),
			'can_save_markup'   => current_user_can( 'unfiltered_html' ),
			'templates'         => array( 'page-blocks-full-builder.php' => 'Blank canvas (no header or footer)' ),
		);
	}

	private static function blocks_list( array $input ): array {
		$params            = array_intersect_key( $input, array_flip( array( 'search', 'status', 'page', 'per_page', 'orderby', 'order' ) ) );
		$params['context'] = 'summary';
		$result            = self::rest( 'GET', '/blocks', $params );
		return array(
			'total'  => $result['total'] ?? 0,
			'blocks' => $result['data'],
		);
	}

	private static function blocks_get( array $input ): array {
		return array( 'block' => self::rest( 'GET', '/blocks/' . self::id( $input ) )['data'] );
	}

	/** New library blocks start as drafts with no theme position unless the input says otherwise. */
	private static function blocks_create( array $input ): array {
		$fields  = self::block_fields( is_array( $input['block'] ?? null ) ? $input['block'] : array() );
		$fields += array(
			'status'   => 'draft',
			'position' => '',
		);
		return array( 'block' => self::rest( 'POST', '/blocks', $fields )['data'] );
	}

	private static function blocks_update( array $input ): array {
		$fields = self::block_fields( is_array( $input['patch'] ?? null ) ? $input['patch'] : array() );
		if ( ! $fields ) {
			throw new InvalidArgumentException( 'Pass the fields to change in patch.' );
		}
		return array( 'block' => self::rest( 'PATCH', '/blocks/' . self::id( $input ), $fields )['data'] );
	}

	private static function blocks_duplicate( array $input ): array {
		return array( 'block' => self::rest( 'POST', '/blocks/' . self::id( $input ) . '/duplicate' )['data'] );
	}

	private static function blocks_render( array $input ): array {
		return array( 'render' => self::rest( 'GET', '/blocks/' . self::id( $input ) . '/render' )['data'] );
	}

	/** Moves a library block to the trash; permanent deletion stays in wp-admin. */
	private static function blocks_trash( array $input ): array {
		return array( 'result' => self::rest( 'DELETE', '/blocks/' . self::id( $input ) )['data'] );
	}

	/**
	 * A page-block block from agent attributes.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 * @return array<string, mixed>
	 */
	private static function section_block( array $attrs ): array {
		if ( ! empty( $attrs['phpExec'] ) ) {
			throw new InvalidArgumentException( 'Agents cannot enable PHP execution in a section.' );
		}
		$unknown = array_diff( array_keys( $attrs ), self::SECTION_ATTRS, array( 'phpExec' ) );
		if ( $unknown ) {
			throw new InvalidArgumentException( 'Unsupported section attributes: ' . implode( ', ', $unknown ) );
		}
		unset( $attrs['phpExec'] );
		foreach ( array( 'name', 'content', 'css', 'js', 'jsLocation', 'output', 'cssOutput', 'blockSlug' ) as $key ) {
			if ( isset( $attrs[ $key ] ) && ! is_string( $attrs[ $key ] ) ) {
				throw new InvalidArgumentException( $key . ' must be a string.' );
			}
		}
		if ( isset( $attrs['jsLocation'] ) && ! in_array( $attrs['jsLocation'], array( 'header', 'footer', 'inline' ), true ) ) {
			throw new InvalidArgumentException( 'jsLocation must be header, footer or inline.' );
		}
		if ( isset( $attrs['blockId'] ) ) {
			$attrs['blockId'] = absint( $attrs['blockId'] );
		}
		return array(
			'blockName'    => GT_Page_Blocks_Builder::BLOCK_NAME,
			'attrs'        => array( 'name' => (string) ( $attrs['name'] ?? '' ) ) + $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	private static function section_markup( array $input ): array {
		$block = self::section_block( is_array( $input['section'] ?? null ) ? $input['section'] : array() );
		return array( 'markup' => serialize_block( $block ) );
	}

	/**
	 * Page-block sections at any depth, with the path of block indexes that reaches each.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param int[]                            $path   Parent path.
	 * @return array<int, array<string, mixed>>
	 */
	private static function walk( array $blocks, array $path = array() ): array {
		$found = array();
		foreach ( $blocks as $index => $block ) {
			$here = array_merge( $path, array( $index ) );
			if ( GT_Page_Blocks_Builder::is_page_block_name( (string) ( $block['blockName'] ?? '' ) ) ) {
				$found[] = array(
					'path'  => $here,
					'block' => $block,
				);
			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::walk( $block['innerBlocks'], $here ) );
			}
		}
		return $found;
	}

	/**
	 * The editable post, its working content (the user's newer autosave if one exists) and parsed blocks.
	 *
	 * @return array<string, mixed>
	 */
	private static function page( array $input ): array {
		$post = get_post( self::id( $input, 'post_id' ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new RuntimeException( 'Post not found or not editable by this user.' );
		}
		$content  = $post->post_content;
		$autosave = wp_get_post_autosave( $post->ID, get_current_user_id() );
		// An autosave that differs and is not older than the post is the user's staged work.
		$staged = $autosave && $autosave->post_content !== $post->post_content && strtotime( $autosave->post_modified_gmt ) >= strtotime( $post->post_modified_gmt );
		if ( $staged ) {
			$content = $autosave->post_content;
		}
		$blocks = parse_blocks( $content );
		return array(
			'post'    => $post,
			'content' => $content,
			'staged'  => $staged,
			'blocks'  => $blocks,
			// Writes rebuild the content with the serializer, so only content it reproduces exactly is changed.
			'exact'   => serialize_blocks( $blocks ) === $content,
		);
	}

	/**
	 * Summary of a section without its full code.
	 *
	 * @param array<string, mixed> $found Path and block.
	 * @return array<string, mixed>
	 */
	private static function summary( int $index, array $found ): array {
		$attrs = (array) $found['block']['attrs'];
		return array(
			'index'      => $index,
			'name'       => (string) ( $attrs['name'] ?? '' ),
			'linked'     => absint( $attrs['blockId'] ?? 0 ) > 0 ? array(
				'blockId'   => absint( $attrs['blockId'] ),
				'blockSlug' => (string) ( $attrs['blockSlug'] ?? '' ),
			) : null,
			'html_bytes' => strlen( (string) ( $attrs['content'] ?? '' ) ),
			'css_bytes'  => strlen( (string) ( $attrs['css'] ?? '' ) ),
			'js_bytes'   => strlen( (string) ( $attrs['js'] ?? '' ) ),
			'php'        => ! empty( $attrs['phpExec'] ),
		);
	}

	private static function page_sections( array $input ): array {
		$page     = self::page( $input );
		$sections = array();
		foreach ( self::walk( $page['blocks'] ) as $index => $found ) {
			$sections[] = self::summary( $index, $found );
		}
		return array(
			'post_id'        => $page['post']->ID,
			'status'         => $page['post']->post_status,
			'template'       => (string) get_post_meta( $page['post']->ID, '_wp_page_template', true ),
			'reading'        => $page['staged'] ? 'your newer autosave' : 'the saved post',
			'editable'       => $page['exact'],
			'content_sha256' => hash( 'sha256', $page['content'] ),
			'sections'       => $sections,
		);
	}

	private static function page_section( array $input ): array {
		$page  = self::page( $input );
		$found = self::walk( $page['blocks'] );
		$index = absint( $input['index'] ?? -1 );
		if ( ! isset( $input['index'] ) || ! isset( $found[ $index ] ) ) {
			throw new InvalidArgumentException( 'Pass the index of a section from page.sections.' );
		}
		$attrs = (array) $found[ $index ]['block']['attrs'];
		unset( $attrs['visualData'] );
		return array(
			'index'          => $index,
			'attrs'          => $attrs,
			'content_sha256' => hash( 'sha256', $page['content'] ),
		);
	}

	/**
	 * Replace a block at a path in a parsed tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param int[]                            $path   Path.
	 * @param array<string, mixed>             $block  Replacement.
	 * @return array<int, array<string, mixed>>
	 */
	private static function replace_at( array $blocks, array $path, array $block ): array {
		$index = array_shift( $path );
		if ( ! $path ) {
			$blocks[ $index ] = $block;
			return $blocks;
		}
		$blocks[ $index ]['innerBlocks'] = self::replace_at( $blocks[ $index ]['innerBlocks'], $path, $block );
		return $blocks;
	}

	/**
	 * Insert a block after a path, inside the same parent, keeping the parent's innerContent in step.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param int[]                            $path   Path of the block to insert after.
	 * @param array<string, mixed>             $block  New block.
	 * @return array<int, array<string, mixed>>
	 */
	private static function insert_after( array $blocks, array $path, array $block ): array {
		$index = array_shift( $path );
		if ( $path ) {
			$blocks[ $index ] = self::insert_into( $blocks[ $index ], $path, $block );
			return $blocks;
		}
		array_splice(
			$blocks,
			$index + 1,
			0,
			array(
				array(
					'blockName'    => null,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => "\n\n",
					'innerContent' => array( "\n\n" ),
				),
				$block,
			)
		);
		return $blocks;
	}

	private static function insert_into( array $container, array $path, array $block ): array {
		$index = array_shift( $path );
		if ( $path ) {
			$container['innerBlocks'][ $index ] = self::insert_into( $container['innerBlocks'][ $index ], $path, $block );
			return $container;
		}
		array_splice( $container['innerBlocks'], $index + 1, 0, array( $block ) );
		// innerContent holds a null placeholder per inner block; add one after the placeholder of $index.
		$seen = -1;
		foreach ( $container['innerContent'] as $position => $chunk ) {
			if ( null === $chunk && ++$seen === $index ) {
				array_splice( $container['innerContent'], $position + 1, 0, array( "\n\n", null ) );
				break;
			}
		}
		return $container;
	}

	/**
	 * Change one section, or add one after another section or at the end of the page.
	 * Published posts are staged as the user's autosave unless publish is true.
	 */
	private static function page_set_section( array $input ): array {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			throw new RuntimeException( 'This WordPress user cannot save section markup. It needs the unfiltered_html capability.' );
		}
		$page = self::page( $input );
		if ( ! $page['exact'] ) {
			throw new RuntimeException( 'This post\'s markup does not survive a parse and re-serialize unchanged, so it is not edited automatically. Edit it in the block editor.' );
		}
		$expected = (string) ( $input['expected_sha256'] ?? '' );
		if ( '' === $expected || ! hash_equals( hash( 'sha256', $page['content'] ), $expected ) ) {
			throw new RuntimeException( 'The page changed since you read it. Call page.sections again and use its content_sha256.' );
		}
		$found = self::walk( $page['blocks'] );
		$patch = is_array( $input['section'] ?? null ) ? $input['section'] : array();
		if ( isset( $input['index'] ) ) {
			$index = absint( $input['index'] );
			if ( ! isset( $found[ $index ] ) ) {
				throw new InvalidArgumentException( 'No section at that index.' );
			}
			$current = (array) $found[ $index ]['block']['attrs'];
			if ( ! empty( $current['phpExec'] ) || ! empty( $current['nativeContent'] ) ) {
				throw new RuntimeException( 'This section runs PHP or uses native blocks. Change it in the block editor.' );
			}
			$block  = self::section_block( array_intersect_key( array_merge( $current, $patch ), array_flip( self::SECTION_ATTRS ) ) );
			$blocks = self::replace_at( $page['blocks'], $found[ $index ]['path'], $block );
		} else {
			$block = self::section_block( $patch );
			if ( isset( $input['after'] ) ) {
				$after = absint( $input['after'] );
				if ( ! isset( $found[ $after ] ) ) {
					throw new InvalidArgumentException( 'No section at the after index.' );
				}
				$blocks = self::insert_after( $page['blocks'], $found[ $after ]['path'], $block );
			} else {
				$blocks   = $page['blocks'];
				$blocks[] = array(
					'blockName'    => null,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => "\n\n",
					'innerContent' => array( "\n\n" ),
				);
				$blocks[] = $block;
			}
		}
		return self::save( $page['post'], serialize_blocks( $blocks ), ! empty( $input['publish'] ) );
	}

	/**
	 * Save content: directly for drafts, as an autosave for published posts unless publishing was asked for.
	 *
	 * @return array<string, mixed>
	 */
	private static function save( WP_Post $post, string $content, bool $publish ): array {
		$live = in_array( $post->post_status, array( 'publish', 'private', 'future' ), true );
		if ( $live && ! $publish ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
			$result = wp_create_post_autosave(
				array(
					'post_ID'      => $post->ID,
					'post_type'    => $post->post_type,
					'post_title'   => $post->post_title,
					'post_excerpt' => $post->post_excerpt,
					'post_content' => wp_slash( $content ),
				)
			);
			if ( is_wp_error( $result ) || ! $result ) {
				throw new RuntimeException( is_wp_error( $result ) ? $result->get_error_message() : 'The autosave could not be saved.' );
			}
			return array(
				'saved'          => 'autosave',
				'note'           => 'Staged for review: open the post in the editor and restore the autosave, or repeat with publish true when the user asks to change the live page.',
				'preview_url'    => get_preview_post_link( $post ),
				'content_sha256' => hash( 'sha256', $content ),
			);
		}
		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		$stored = (string) get_post_field( 'post_content', $post->ID, 'raw' );
		if ( $stored !== $content ) {
			throw new RuntimeException( 'WordPress changed the markup while saving. Check the post in the editor.' );
		}
		return array(
			'saved'          => 'post',
			'status'         => get_post_status( $post->ID ),
			'url'            => get_permalink( $post->ID ),
			'content_sha256' => hash( 'sha256', $content ),
		);
	}

	/** Create a draft page from sections, optionally on the blank canvas template. */
	private static function page_create( array $input ): array {
		if ( ! current_user_can( 'unfiltered_html' ) || ! current_user_can( 'edit_pages' ) ) {
			throw new RuntimeException( 'This WordPress user cannot create pages with section markup.' );
		}
		$title    = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$sections = is_array( $input['sections'] ?? null ) ? $input['sections'] : array();
		if ( '' === $title || ! $sections ) {
			throw new InvalidArgumentException( 'Pass a title and at least one section.' );
		}
		$markup = array();
		foreach ( $sections as $section ) {
			$markup[] = serialize_block( self::section_block( is_array( $section ) ? $section : array() ) );
		}
		$content = implode( "\n\n", $markup );
		$id      = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		if ( ! empty( $input['canvas'] ) ) {
			update_post_meta( $id, '_wp_page_template', 'page-blocks-full-builder.php' );
		}
		if ( (string) get_post_field( 'post_content', $id, 'raw' ) !== $content ) {
			throw new RuntimeException( 'WordPress changed the markup while saving the draft. Check it in the editor.' );
		}
		return array(
			'post_id'     => $id,
			'status'      => 'draft',
			'preview_url' => get_preview_post_link( $id ),
			'edit_url'    => get_edit_post_link( $id, 'raw' ),
		);
	}
}
