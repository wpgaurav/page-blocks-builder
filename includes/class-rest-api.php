<?php
/**
 * REST API for the Page Blocks library.
 *
 * Namespace: pbb/v1
 *
 *   GET    /blocks                 List blocks (search, status, pagination)
 *   POST   /blocks                 Create a block
 *   GET    /blocks/<id>            Get one block
 *   PUT    /blocks/<id>            Update a block (incl. status for restore)
 *   DELETE /blocks/<id>            Trash (or ?force=true to delete permanently)
 *   POST   /blocks/<id>/duplicate  Duplicate a block
 *   GET    /blocks/<id>/render     Rendered HTML + CSS + JS (for previews)
 *
 * Read requires edit_posts (block editor library browser);
 * write requires manage_options.
 *
 * @since 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_rest_api {

	const REST_NAMESPACE = 'pbb/v1';

	/**
	 * @var gt_pb_db
	 */
	private $db;

	/**
	 * @var GT_Page_Blocks_Builder
	 */
	private $plugin;

	/** Usage tally, resolved once per request. */
	private static $usage = null;

	public function __construct( gt_pb_db $db, $plugin ) {
		$this->db = $db;
		$this->plugin = $plugin;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/blocks', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => array( $this, 'read_permissions' ),
				'args'                => array(
					'search'   => array( 'type' => 'string', 'default' => '' ),
					'status'   => array( 'type' => 'string', 'default' => '' ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 24, 'minimum' => 1, 'maximum' => 100 ),
					'orderby'  => array( 'type' => 'string', 'default' => 'updated_at' ),
					'order'    => array( 'type' => 'string', 'default' => 'desc' ),
					'context'  => array( 'type' => 'string', 'default' => 'full', 'enum' => array( 'full', 'summary' ) ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'write_permissions' ),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/blocks/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'read_permissions' ),
			),
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'write_permissions' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'write_permissions' ),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/blocks/(?P<id>\d+)/duplicate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'duplicate_item' ),
			'permission_callback' => array( $this, 'write_permissions' ),
		) );

		register_rest_route( self::REST_NAMESPACE, '/blocks/(?P<id>\d+)/render', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'render_item' ),
			'permission_callback' => array( $this, 'read_permissions' ),
		) );
	}

	public function read_permissions(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function write_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /blocks
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'search'   => (string) $request['search'],
			'status'   => (string) $request['status'],
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
			'orderby'  => (string) $request['orderby'],
			'order'    => (string) $request['order'],
		);

		// Summary context strips content/css/js so pickers (e.g. the editor's
		// library modal) don't download every block's full code just to list titles.
		$prepare = 'summary' === (string) $request['context']
			? array( $this, 'prepare_item_summary' )
			: array( $this, 'prepare_item' );

		// "Most used" is not a column, so that ordering is applied after the
		// tally is known: fetch the filtered set, sort, then page it by hand.
		// The library is hundreds of rows, not millions.
		if ( 'usage' === $args['orderby'] ) {
			$all = $this->db->query( array_merge( $args, array( 'per_page' => 500, 'page' => 1 ) ) );
			$all = array_map( $prepare, $all );

			$direction = 'asc' === strtolower( $args['order'] ) ? 1 : -1;
			usort(
				$all,
				static function ( $a, $b ) use ( $direction ) {
					if ( $a['used_on'] === $b['used_on'] ) {
						return strcasecmp( (string) $a['title'], (string) $b['title'] );
					}
					return ( $a['used_on'] < $b['used_on'] ? -1 : 1 ) * $direction;
				}
			);

			$total  = count( $all );
			$offset = max( 0, ( $args['page'] - 1 ) * $args['per_page'] );
			$items  = array_slice( $all, $offset, $args['per_page'] );
		} else {
			$items = array_map( $prepare, $this->db->query( $args ) );
			$total = $this->db->count( $args );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $args['per_page'] ) ) );

		// Status counts for the library filter tabs.
		$response->header( 'X-PBB-Published', (string) $this->db->count( array( 'status' => 'publish' ) ) );
		$response->header( 'X-PBB-Drafts', (string) $this->db->count( array( 'status' => 'draft' ) ) );
		$response->header( 'X-PBB-Trash', (string) $this->db->count( array( 'status' => 'trash' ) ) );

		return $response;
	}

	/**
	 * GET /blocks/<id>
	 */
	public function get_item( WP_REST_Request $request ) {
		$block = $this->db->get( (int) $request['id'] );
		if ( ! $block ) {
			return new WP_Error( 'not_found', __( 'Page block not found.', 'page-blocks-builder' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->prepare_item( $block ) );
	}

	/**
	 * POST /blocks
	 */
	public function create_item( WP_REST_Request $request ) {
		$data = $this->extract_data( $request );

		if ( empty( $data['title'] ) ) {
			return new WP_Error( 'missing_title', __( 'A title is required.', 'page-blocks-builder' ), array( 'status' => 400 ) );
		}

		$id = $this->db->insert( $data );
		if ( false === $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create the page block.', 'page-blocks-builder' ), array( 'status' => 500 ) );
		}

		$response = rest_ensure_response( $this->prepare_item( $this->db->get( $id ) ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * PUT /blocks/<id>
	 */
	public function update_item( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->db->get( $id ) ) {
			return new WP_Error( 'not_found', __( 'Page block not found.', 'page-blocks-builder' ), array( 'status' => 404 ) );
		}

		$data = $this->extract_data( $request );
		if ( empty( $data ) ) {
			return new WP_Error( 'no_fields', __( 'No fields to update.', 'page-blocks-builder' ), array( 'status' => 400 ) );
		}

		if ( ! $this->db->update( $id, $data ) ) {
			return new WP_Error( 'update_failed', __( 'Failed to update the page block.', 'page-blocks-builder' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->prepare_item( $this->db->get( $id ) ) );
	}

	/**
	 * DELETE /blocks/<id> — trash by default, ?force=true deletes permanently.
	 */
	public function delete_item( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$block = $this->db->get( $id );
		if ( ! $block ) {
			return new WP_Error( 'not_found', __( 'Page block not found.', 'page-blocks-builder' ), array( 'status' => 404 ) );
		}

		$force = rest_sanitize_boolean( $request['force'] ?? false );

		if ( $force ) {
			$ok = $this->db->delete( $id );
		} else {
			$ok = $this->db->trash( $id );
		}

		if ( ! $ok ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete the page block.', 'page-blocks-builder' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true, 'force' => $force, 'id' => $id ) );
	}

	/**
	 * POST /blocks/<id>/duplicate
	 */
	public function duplicate_item( WP_REST_Request $request ) {
		$new_id = $this->db->duplicate( (int) $request['id'] );
		if ( false === $new_id ) {
			return new WP_Error( 'duplicate_failed', __( 'Failed to duplicate the page block.', 'page-blocks-builder' ), array( 'status' => 500 ) );
		}

		$response = rest_ensure_response( $this->prepare_item( $this->db->get( $new_id ) ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * GET /blocks/<id>/render — server-rendered output for previews.
	 */
	public function render_item( WP_REST_Request $request ) {
		$block = $this->db->get( (int) $request['id'] );
		if ( ! $block ) {
			return new WP_Error( 'not_found', __( 'Page block not found.', 'page-blocks-builder' ), array( 'status' => 404 ) );
		}

		// render_library_block() returns full front-end markup (incl. style/script tags).
		$html = method_exists( $this->plugin, 'render_library_block' )
			? (string) $this->plugin->render_library_block( $block )
			: (string) ( $block->content ?? '' );

		return rest_ensure_response( array(
			'id'   => (int) $block->id,
			'html' => $html,
			'css'  => (string) ( $block->css ?? '' ),
			'js'   => (string) ( $block->js ?? '' ),
		) );
	}

	/**
	 * Pull writable fields from a request. The DB layer sanitizes.
	 */
	private function extract_data( WP_REST_Request $request ): array {
		$params = $request->get_json_params() ?: $request->get_body_params();
		$fields = array(
			'title', 'slug', 'status', 'content', 'css', 'js',
			'js_location', 'output', 'php_exec', 'format',
			'position', 'priority',
		);

		$data = array();
		foreach ( $fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$data[ $field ] = $params[ $field ];
			}
		}

		return $data;
	}

	/**
	 * Shape a DB row as a lightweight summary (no content/css/js payloads).
	 */
	public function prepare_item_summary( object $block ): array {
		return array(
			'id'          => (int) $block->id,
			'title'       => (string) $block->title,
			'slug'        => (string) $block->slug,
			'status'      => (string) $block->status,
			'position'    => (string) ( $block->position ?? '' ),
			'php_exec'    => ! empty( $block->php_exec ),
			'has_content' => '' !== trim( (string) ( $block->content ?? '' ) ),
			'has_css'     => '' !== trim( (string) ( $block->css ?? '' ) ),
			'has_js'      => '' !== trim( (string) ( $block->js ?? '' ) ),
			'updated_at'  => (string) ( $block->updated_at ?? '' ),
			'used_on'     => self::usage_for( (int) $block->id ),
		);
	}

	/**
	 * Posts using one block, from the tally computed once per request.
	 */
	private static function usage_for( int $id ): int {
		if ( null === self::$usage ) {
			self::$usage = GT_Page_Blocks_Builder::get_block_usage_counts();
		}

		return (int) ( self::$usage[ $id ] ?? 0 );
	}

	/**
	 * Shape a DB row for API responses.
	 */
	public function prepare_item( object $block ): array {
		return array(
			'used_on'     => self::usage_for( (int) $block->id ),
			'id'          => (int) $block->id,
			'title'       => (string) $block->title,
			'slug'        => (string) $block->slug,
			'status'      => (string) $block->status,
			'content'     => (string) ( $block->content ?? '' ),
			'css'         => (string) ( $block->css ?? '' ),
			'js'          => (string) ( $block->js ?? '' ),
			'js_location' => (string) ( $block->js_location ?? 'footer' ),
			'output'      => (string) ( $block->output ?? 'inline' ),
			'php_exec'    => ! empty( $block->php_exec ),
			'format'      => ! empty( $block->format ),
			'position'    => (string) ( $block->position ?? '' ),
			'priority'    => (int) ( $block->priority ?? 10 ),
			'created_at'  => (string) ( $block->created_at ?? '' ),
			'updated_at'  => (string) ( $block->updated_at ?? '' ),
		);
	}
}
