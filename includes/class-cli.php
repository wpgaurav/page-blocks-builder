<?php
/**
 * WP-CLI commands for the block library.
 *
 * WP-CLI stopped at the two migration commands, so the library - the thing the
 * plugin exists to manage - could only be reached through wp-admin or by
 * writing REST calls by hand. These wrap gt_pb_db, which is already public and
 * already derives the checksum on every write, so nothing here is a second
 * write path with its own rules.
 *
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_cli {

	private gt_pb_db $db;

	public function __construct( gt_pb_db $db ) {
		$this->db = $db;
	}

	/**
	 * List library blocks.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : publish, draft or trash. Default publish.
	 *
	 * [--format=<format>]
	 * : table, json, csv, ids. Default table.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function list_blocks( $args, $assoc_args ): void {
		$rows = $this->db->query( array(
			'status'   => $assoc_args['status'] ?? 'publish',
			'per_page' => 500,
		) );

		$format = $assoc_args['format'] ?? 'table';

		if ( 'ids' === $format ) {
			\WP_CLI::log( implode( ' ', array_map( static fn( $r ) => (int) $r->id, $rows ) ) );
			return;
		}

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'       => (int) $r->id,
				'title'    => (string) $r->title,
				'slug'     => (string) $r->slug,
				'status'   => (string) $r->status,
				'position' => (string) $r->position,
				'updated'  => (string) $r->updated_at,
			);
		}

		\WP_CLI\Utils\format_items( $format, $out, array( 'id', 'title', 'slug', 'status', 'position', 'updated' ) );
	}

	/**
	 * Show one block.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Block ID, or a slug.
	 *
	 * [--field=<field>]
	 * : Print only this field.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function get_block( $args, $assoc_args ): void {
		$row = $this->resolve( $args[0] ?? '' );

		if ( ! $row ) {
			\WP_CLI::error( 'No such block.' );
			return;
		}

		if ( ! empty( $assoc_args['field'] ) ) {
			\WP_CLI::log( (string) ( $row->{$assoc_args['field']} ?? '' ) );
			return;
		}

		\WP_CLI::log( (string) wp_json_encode( $row, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Create a block.
	 *
	 * ## OPTIONS
	 *
	 * --title=<title>
	 * : Block title.
	 *
	 * [--slug=<slug>]
	 * : Slug. Derived from the title when omitted.
	 *
	 * [--content=<html>]
	 * : Block HTML. Use - to read stdin.
	 *
	 * [--status=<status>]
	 * : Default publish.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function create_block( $args, $assoc_args ): void {
		$content = $assoc_args['content'] ?? '';
		if ( '-' === $content ) {
			$content = (string) file_get_contents( 'php://stdin' );
		}

		$id = $this->db->insert( array(
			'title'   => (string) ( $assoc_args['title'] ?? '' ),
			'slug'    => (string) ( $assoc_args['slug'] ?? '' ),
			'content' => $content,
			'status'  => (string) ( $assoc_args['status'] ?? 'publish' ),
		) );

		if ( false === $id || $id <= 0 ) {
			\WP_CLI::error(
				$this->db->last_error_is_duplicate_slug()
					? 'That slug is already taken.'
					: 'The database rejected the write.'
			);
			return;
		}

		\WP_CLI::success( "Created block {$id}." );
	}

	/**
	 * Delete a block permanently.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Block ID or slug.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function delete_block( $args, $assoc_args ): void {
		$row = $this->resolve( $args[0] ?? '' );

		if ( ! $row ) {
			\WP_CLI::error( 'No such block.' );
			return;
		}

		$used = GT_Page_Blocks_Builder::get_block_usage_posts( (int) $row->id );
		if ( $used ) {
			\WP_CLI::warning( sprintf( 'This block is placed on %d post(s).', count( $used ) ) );
		}

		\WP_CLI::confirm( sprintf( 'Permanently delete "%s"?', $row->title ), $assoc_args );

		if ( ! $this->db->delete( (int) $row->id ) ) {
			\WP_CLI::error( 'Delete failed.' );
			return;
		}

		\WP_CLI::success( 'Deleted.' );
	}

	/**
	 * Render a block as the front end would.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Block ID or slug.
	 *
	 * @param array $args Positional args.
	 */
	public function render_block( $args ): void {
		$row = $this->resolve( $args[0] ?? '' );

		if ( ! $row ) {
			\WP_CLI::error( 'No such block.' );
			return;
		}

		$plugin = $GLOBALS['gt_page_blocks_builder'] ?? null;
		if ( ! $plugin instanceof GT_Page_Blocks_Builder ) {
			\WP_CLI::error( 'Plugin not loaded.' );
			return;
		}

		\WP_CLI::log( $plugin->render_library_block( $row ) );
	}

	/**
	 * Accept either an id or a slug, so scripts do not have to care.
	 *
	 * @param string $ref Id or slug.
	 * @return object|null
	 */
	private function resolve( string $ref ): ?object {
		if ( '' === $ref ) {
			return null;
		}

		return ctype_digit( $ref ) ? $this->db->get( (int) $ref ) : $this->db->get_by_slug( $ref );
	}
}
