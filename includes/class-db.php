<?php
/**
 * Page Blocks Database Layer
 *
 * Custom table creation and CRUD operations for reusable page blocks.
 *
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class gt_pb_db {

	const TABLE_VERSION = '1.0';
	const VERSION_OPTION = 'gt_pb_table_version';
	const ASSET_VERSION_OPTION = 'gt_pb_asset_version';

	private string $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'gt_page_blocks';
	}

	/**
	 * Get the table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Create table if needed.
	 */
	public function maybe_create_table(): void {
		if ( get_option( self::VERSION_OPTION ) === self::TABLE_VERSION ) {
			return;
		}
		$this->create_table();
	}

	/**
	 * Create the page blocks table.
	 */
	private function create_table(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			slug varchar(200) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'publish',
			content longtext NOT NULL,
			css longtext NOT NULL,
			js longtext NOT NULL,
			js_location varchar(10) NOT NULL DEFAULT 'footer',
			output varchar(10) NOT NULL DEFAULT 'inline',
			php_exec tinyint(1) NOT NULL DEFAULT 0,
			format tinyint(1) NOT NULL DEFAULT 0,
			position varchar(100) NOT NULL DEFAULT '',
			priority int(11) NOT NULL DEFAULT 10,
			conditions longtext DEFAULT NULL,
			author bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY position (position)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::TABLE_VERSION );
	}

	/**
	 * Get a single block by ID.
	 *
	 * @param int $id Block ID.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $id )
		);

		return $row ?: null;
	}

	/**
	 * Get a single block by slug.
	 *
	 * @param string $slug Block slug.
	 * @return object|null
	 */
	public function get_by_slug( string $slug ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE slug = %s", $slug )
		);

		return $row ?: null;
	}

	/**
	 * Query blocks with flexible parameters.
	 *
	 * @param array $args {
	 *     @type string $status   Filter by status. Default 'publish'.
	 *     @type string $position Filter by position hook.
	 *     @type string $search   Search title and slug.
	 *     @type string $orderby  Column to order by. Default 'updated_at'.
	 *     @type string $order    ASC or DESC. Default 'DESC'.
	 *     @type int    $per_page Results per page. Default 20.
	 *     @type int    $page     Page number. Default 1.
	 * }
	 * @return array Array of objects.
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'status'   => '',
			'position' => '',
			'search'   => '',
			'orderby'  => 'updated_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'page'     => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array();
		$values = array();

		if ( $args['status'] ) {
			$where[] = 'status = %s';
			$values[] = $args['status'];
		}

		if ( $args['position'] ) {
			$where[] = 'position = %s';
			$values[] = $args['position'];
		}

		if ( $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(title LIKE %s OR slug LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = array( 'id', 'title', 'slug', 'status', 'position', 'priority', 'created_at', 'updated_at' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$sql = "SELECT * FROM {$this->table_name} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$values[] = $per_page;
		$values[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Count blocks matching criteria.
	 *
	 * @param array $args Same as query() args (pagination ignored).
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		$where = array();
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['position'] ) ) {
			$where[] = 'position = %s';
			$values[] = $args['position'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(title LIKE %s OR slug LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$sql = "SELECT COUNT(*) FROM {$this->table_name} {$where_sql}";

		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Insert a new block.
	 *
	 * @param array $data Block data.
	 * @return int|false New block ID or false on failure.
	 */
	public function insert( array $data ): int|false {
		global $wpdb;

		$data = $this->sanitize_data( $data );
		$data['created_at'] = current_time( 'mysql' );
		$data['updated_at'] = current_time( 'mysql' );

		if ( empty( $data['slug'] ) && ! empty( $data['title'] ) ) {
			$data['slug'] = $this->generate_unique_slug( $data['title'] );
		}

		$result = $wpdb->insert( $this->table_name, $data, $this->get_formats( $data ) );

		if ( $result === false ) {
			return false;
		}

		$this->bump_asset_version();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing block.
	 *
	 * @param int   $id   Block ID.
	 * @param array $data Data to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data = $this->sanitize_data( $data );
		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $id ),
			$this->get_formats( $data ),
			array( '%d' )
		);

		if ( $result !== false ) {
			$this->bump_asset_version();
		}

		return $result !== false;
	}

	/**
	 * Delete a block permanently.
	 *
	 * @param int $id Block ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );

		if ( $result ) {
			$this->bump_asset_version();
		}

		return (bool) $result;
	}

	/**
	 * Trash a block (soft delete).
	 *
	 * @param int $id Block ID.
	 * @return bool
	 */
	public function trash( int $id ): bool {
		return $this->update( $id, array( 'status' => 'trash' ) );
	}

	/**
	 * Restore a trashed block.
	 *
	 * @param int $id Block ID.
	 * @return bool
	 */
	public function restore( int $id ): bool {
		return $this->update( $id, array( 'status' => 'publish' ) );
	}

	/**
	 * Duplicate a block.
	 *
	 * @param int $id Block ID to duplicate.
	 * @return int|false New block ID or false.
	 */
	public function duplicate( int $id ): int|false {
		$block = $this->get( $id );

		if ( ! $block ) {
			return false;
		}

		$data = (array) $block;
		unset( $data['id'], $data['created_at'], $data['updated_at'] );

		$data['title'] = $block->title . ' (Copy)';
		$data['slug'] = $this->generate_unique_slug( $data['title'] );
		$data['status'] = 'draft';

		return $this->insert( $data );
	}

	/**
	 * Generate a unique slug from a title.
	 *
	 * @param string $title      Title to generate slug from.
	 * @param int    $exclude_id ID to exclude from uniqueness check (for updates).
	 * @return string
	 */
	public function generate_unique_slug( string $title, int $exclude_id = 0 ): string {
		global $wpdb;

		$slug = sanitize_title( $title );

		if ( empty( $slug ) ) {
			$slug = 'page-block';
		}

		$original = $slug;
		$counter = 2;

		while ( true ) {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE slug = %s AND id != %d",
				$slug,
				$exclude_id
			);

			if ( (int) $wpdb->get_var( $sql ) === 0 ) {
				break;
			}

			$slug = $original . '-' . $counter;
			$counter++;
		}

		return $slug;
	}

	/**
	 * Get all published blocks assigned to positions.
	 *
	 * @return array
	 */
	public function get_positioned_blocks(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE status = %s AND position != %s ORDER BY priority ASC, id ASC",
				'publish',
				''
			)
		);
	}

	/**
	 * Bump the asset version to invalidate caches.
	 */
	public function bump_asset_version(): void {
		$version = (int) get_option( self::ASSET_VERSION_OPTION, 0 );
		update_option( self::ASSET_VERSION_OPTION, $version + 1 );
	}

	/**
	 * Get current asset version.
	 *
	 * @return int
	 */
	public function get_asset_version(): int {
		return (int) get_option( self::ASSET_VERSION_OPTION, 1 );
	}

	/**
	 * Sanitize block data before insert/update.
	 *
	 * @param array $data Raw data.
	 * @return array Sanitized data.
	 */
	private function sanitize_data( array $data ): array {
		$sanitized = array();

		$allowed = array(
			'title', 'slug', 'status', 'content', 'css', 'js',
			'js_location', 'output', 'php_exec', 'format',
			'position', 'priority', 'conditions', 'author',
			'created_at', 'updated_at',
		);

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			$sanitized[ $key ] = match ( $key ) {
				'title'       => sanitize_text_field( $value ),
				'slug'        => sanitize_title( $value ),
				'status'      => in_array( $value, array( 'publish', 'draft', 'trash' ), true ) ? $value : 'draft',
				'content'     => $value, // Raw HTML/PHP — sanitized at render time
				'css'         => $value, // Sanitized at render time via sanitize_css()
				'js'          => $value, // Raw JS — user responsibility
				'js_location' => in_array( $value, array( 'footer', 'inline' ), true ) ? $value : 'footer',
				'output'      => in_array( $value, array( 'inline', 'file' ), true ) ? $value : 'inline',
				'php_exec'    => (int) (bool) $value,
				'format'      => (int) (bool) $value,
				'position'    => sanitize_text_field( $value ),
				'priority'    => max( 0, (int) $value ),
				'conditions'  => is_string( $value ) ? $value : wp_json_encode( $value ),
				'author'      => absint( $value ),
				'created_at'  => sanitize_text_field( $value ),
				'updated_at'  => sanitize_text_field( $value ),
			};
		}

		return $sanitized;
	}

	/**
	 * Get format strings for wpdb insert/update.
	 *
	 * @param array $data Data array.
	 * @return array Format strings.
	 */
	private function get_formats( array $data ): array {
		$format_map = array(
			'id'          => '%d',
			'title'       => '%s',
			'slug'        => '%s',
			'status'      => '%s',
			'content'     => '%s',
			'css'         => '%s',
			'js'          => '%s',
			'js_location' => '%s',
			'output'      => '%s',
			'php_exec'    => '%d',
			'format'      => '%d',
			'position'    => '%s',
			'priority'    => '%d',
			'conditions'  => '%s',
			'author'      => '%d',
			'created_at'  => '%s',
			'updated_at'  => '%s',
		);

		$formats = array();
		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $format_map[ $key ] ?? '%s';
		}

		return $formats;
	}
}
