<?php
/**
 * Migrations off the Marketers Delight "Page Blocks" dropin.
 *
 * Two independent, idempotent migrations:
 *
 * 1. Block names — rewrites `marketers-delight/page-block` and
 *    `marketers-delight/inline-page-block` block comments in post_content
 *    to `gt-page-block/page-block`, across all post types (skipping
 *    revisions and auto-drafts).
 *
 *      WP-CLI:  wp gt-pb migrate-blocks [--dry-run]
 *      Admin:   Page Blocks → Settings → Tools → "Migrate blocks"
 *
 * 2. Library rows — copies the dropin's `{prefix}md_page_blocks` table into
 *    `{prefix}gt_page_blocks`, **preserving row IDs** so existing
 *    `[page_block id="N"]` shortcodes and `blockId` block attributes keep
 *    resolving. PHP-execution checksums are recomputed on import and
 *    dropin-era theme hook positions are mapped to plugin positions.
 *
 *      WP-CLI:  wp gt-pb migrate-library [--dry-run] [--overwrite]
 *      Admin:   Page Blocks → Settings → Tools → "Import from dropin"
 *
 * The legacy block names stay registered server-side so un-migrated content
 * keeps rendering; migration 1 makes the editor use the new block everywhere
 * and lets the legacy registrations be dropped later.
 *
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_migration {

	/** Seconds a single migration batch may run before yielding. */
	const BUDGET = 8;


	const OLD_NAME    = 'marketers-delight/page-block';
	const OLD_INLINE  = 'marketers-delight/inline-page-block';
	const NEW_NAME    = 'gt-page-block/page-block';

	/** Dropin library table, without the wpdb prefix. */
	const LEGACY_TABLE = 'md_page_blocks';

	/**
	 * Dropin theme-hook positions → plugin position keys.
	 *
	 * The dropin hung positioned blocks on Marketers Delight's own
	 * `md_hook_*` actions, which never fire once the theme is gone. Each is
	 * remapped to the nearest plugin position (core hook or theme region);
	 * anything unmapped is cleared to '' so the block falls back to
	 * shortcode/block embedding instead of silently never rendering.
	 */
	const POSITION_MAP = array(
		'md_hook_before_html'        => 'wp_body_open',
		'md_hook_before_header'      => 'wp_body_open',
		'md_hook_header_top'         => 'region:header',
		'md_hook_header_bottom'      => 'region:header',
		'md_hook_after_header'       => 'get_header',
		'md_hook_before_content_box' => 'the_content_before',
		'md_hook_content_box_top'    => 'the_content_before',
		'md_hook_content_box_bottom' => 'the_content_after',
		'md_hook_before_content'     => 'the_content_before',
		'md_hook_content_top'        => 'the_content_before',
		'md_hook_before_the_content' => 'the_content_before',
		'md_hook_content'            => 'the_content_after',
		'md_hook_content_bottom'     => 'the_content_after',
		'md_hook_after_content'      => 'the_content_after',
		'md_hook_before_sidebar'     => 'region:sidebar',
		'md_hook_after_sidebar'      => 'region:sidebar',
		'md_hook_before_footer'      => 'region:footer',
		'md_hook_footer_top'         => 'region:footer',
		'md_hook_footer_bottom'      => 'region:footer',
		'md_hook_after_footer'       => 'wp_footer',
		'md_hook_before_footer_copy' => 'region:footer',
		'md_hook_after_footer_copy'  => 'region:footer',
	);

	public function __construct() {
		add_action( 'admin_post_gt_pb_migrate_blocks', array( $this, 'handle_admin_migrate' ) );
		add_action( 'admin_post_gt_pb_migrate_library', array( $this, 'handle_admin_migrate_library' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'gt-pb migrate-blocks', array( $this, 'cli_migrate' ) );
			\WP_CLI::add_command( 'gt-pb migrate-library', array( $this, 'cli_migrate_library' ) );
			\WP_CLI::add_command( 'gt-pb upgrade', array( $this, 'cli_upgrade' ) );
		}
	}

	/**
	 * Count posts still containing either legacy block.
	 */
	public function count_pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type NOT IN ( 'revision', 'auto-draft' )
			   AND ( post_content LIKE %s OR post_content LIKE %s )",
			'%' . $wpdb->esc_like( 'wp:' . self::OLD_NAME ) . '%',
			'%' . $wpdb->esc_like( 'wp:' . self::OLD_INLINE ) . '%'
		) );
	}

	/**
	 * Rewrite legacy block delimiters in a content string.
	 *
	 * Both dropin blocks collapse onto gt-page-block/page-block: the picker
	 * block carries a blockId, the inline block simply omits it (defaulting
	 * to 0), and every other attribute name already matches.
	 */
	public function migrate_content( string $content ): string {
		// Opening, self-closing, and closing delimiters; exact block name
		// bounded by whitespace or the comment terminator so longer names
		// are never clipped. The inline name is listed first so alternation
		// can never match the shorter name inside the longer one.
		$names = '(?:' . preg_quote( self::OLD_INLINE, '#' ) . '|' . preg_quote( self::OLD_NAME, '#' ) . ')';

		return (string) preg_replace(
			'#(<!--\s*/?wp:)' . $names . '(?=[\s{]|\s*/?-->)#',
			'$1' . self::NEW_NAME,
			$content
		);
	}

	/**
	 * Run pending schema and data upgrades.
	 *
	 * The same router the plugin runs on plugins_loaded and on activation, so a
	 * rehearsal against a copy of production exercises the identical code path
	 * rather than an approximation of it.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report the steps that would run without writing anything.
	 *
	 * [--batch=<n>]
	 * : Rows per batch in batched steps. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gt-pb upgrade --dry-run
	 *     wp gt-pb upgrade --batch=50
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function cli_upgrade( $args, $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$plugin  = $GLOBALS['gt_page_blocks_builder'] ?? null;

		if ( ! $plugin instanceof GT_Page_Blocks_Builder ) {
			\WP_CLI::error( 'Plugin not loaded.' );
			return;
		}

		// Loop to completion. A batched step yields on its wall-clock budget
		// and schedules itself; on the command line there is no request to
		// protect, so drive it to the end and report once.
		$passes = 0;
		do {
			$report = $plugin->maybe_upgrade( $dry_run );
			++$passes;

			foreach ( (array) ( $report['steps'] ?? array() ) as $step ) {
				if ( is_array( $step ) ) {
					\WP_CLI::log( sprintf( '  %s: %s', $step['step'], $step['complete'] ? 'done' : 'partial' ) );
				} else {
					\WP_CLI::log( sprintf( '  %s: pending', (string) $step ) );
				}
			}

			if ( $dry_run || 'partial' !== ( $report['reason'] ?? '' ) ) {
				break;
			}
		} while ( $passes < 1000 );

		\WP_CLI::success( sprintf(
			'%s (%s -> %s) after %d pass%s',
			$report['reason'] ?? 'unknown',
			$report['from'] ?? '?',
			$report['to'] ?? '?',
			$passes,
			1 === $passes ? '' : 'es'
		) );
	}

	/**
	 * Run the migration.
	 *
	 * Uses direct DB updates (not wp_update_post) so post_modified dates
	 * and save_post side effects are untouched; caches are cleaned.
	 *
	 * @param bool $dry_run Report without writing.
	 * @return array{scanned:int, migrated:int, ids:array<int,int>}
	 */
	public function migrate( bool $dry_run = false, int $limit = 0, int $after = 0 ): array {
		global $wpdb;

		// Batched deliberately. This used to select every matching post id and
		// then run get_post_field plus an update for each one inside a single
		// request, with no budget and nothing to resume from. Survivable while
		// it was an occasional opt-in tool; a support problem the moment a
		// major release points every site at it at once.
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type NOT IN ( 'revision', 'auto-draft' )
			   AND ID > %d
			   AND ( post_content LIKE %s OR post_content LIKE %s )
			 ORDER BY ID ASC" . $limit_sql,
			$after,
			'%' . $wpdb->esc_like( 'wp:' . self::OLD_NAME ) . '%',
			'%' . $wpdb->esc_like( 'wp:' . self::OLD_INLINE ) . '%'
		) );

		$migrated = array();
		$cursor   = $after;
		$deadline = time() + self::BUDGET;
		$budget_hit = false;

		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$cursor = $id;

			if ( time() > $deadline ) {
				$budget_hit = true;
				break;
			}

			$content = (string) get_post_field( 'post_content', $id, 'raw' );
			$updated = $this->migrate_content( $content );

			if ( $updated === $content ) {
				continue;
			}

			if ( ! $dry_run ) {
				$wpdb->update(
					$wpdb->posts,
					array( 'post_content' => $updated ),
					array( 'ID' => $id ),
					array( '%s' ),
					array( '%d' )
				);
				clean_post_cache( $id );
			}

			$migrated[] = $id;
		}

		return array(
			'scanned'   => count( $ids ),
			'migrated'  => count( $migrated ),
			'ids'       => $migrated,
			'cursor'    => $cursor,
			'complete'  => ! $budget_hit && ( 0 === $limit || count( $ids ) < $limit ),
		);
	}

	/**
	 * WP-CLI: wp gt-pb migrate-blocks [--dry-run]
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 */
	public function cli_migrate( $args, $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$result = $this->migrate( $dry_run );

		if ( $dry_run ) {
			\WP_CLI::log( sprintf( '%d post(s) contain the legacy block and would be migrated.', $result['migrated'] ) );
			if ( $result['ids'] ) {
				\WP_CLI::log( 'Post IDs: ' . implode( ', ', $result['ids'] ) );
			}
			return;
		}

		\WP_CLI::success( sprintf( 'Migrated %d of %d scanned post(s) to %s.', $result['migrated'], $result['scanned'], self::NEW_NAME ) );
	}

	// -------------------------------------------------------------------------
	// Library migration: {prefix}md_page_blocks → {prefix}gt_page_blocks
	// -------------------------------------------------------------------------

	/**
	 * Fully-qualified name of the dropin's library table.
	 */
	public function legacy_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::LEGACY_TABLE;
	}

	/**
	 * Whether a table exists in this database.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
	}

	/**
	 * Whether the dropin's library table exists in this database.
	 */
	public function has_legacy_table(): bool {
		return $this->table_exists( $this->legacy_table() );
	}

	/**
	 * Count dropin rows that have no counterpart in the plugin table.
	 *
	 * @return array{legacy:int, importable:int, conflicts:int}
	 */
	public function count_pending_library(): array {
		global $wpdb;

		$empty = array( 'legacy' => 0, 'importable' => 0, 'conflicts' => 0 );

		if ( ! $this->has_legacy_table() ) {
			return $empty;
		}

		$legacy = $this->legacy_table();
		$target = $wpdb->prefix . 'gt_page_blocks';

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$legacy}`" );
		if ( ! $total ) {
			return $empty;
		}

		// On a fresh install the plugin table may not exist yet, so nothing
		// can conflict — skip the join rather than letting it error.
		$conflicts = $this->table_exists( $target )
			? (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$legacy}` l INNER JOIN `{$target}` t ON t.id = l.id"
			)
			: 0;

		return array(
			'legacy'     => $total,
			'importable' => $total - $conflicts,
			'conflicts'  => $conflicts,
		);
	}

	/**
	 * Map a dropin position key to a plugin position key.
	 *
	 * Unknown `md_hook_*` keys resolve to '' rather than being carried over
	 * verbatim: an md_hook_* action never fires without the theme, so keeping
	 * it would leave the block permanently invisible with no signal why.
	 */
	public function map_position( string $position ): string {
		if ( '' === $position ) {
			return '';
		}

		if ( isset( self::POSITION_MAP[ $position ] ) ) {
			return self::POSITION_MAP[ $position ];
		}

		if ( 0 === strpos( $position, 'md_hook_' ) ) {
			return '';
		}

		// Core hooks (wp_head, wp_footer, …) already match plugin keys.
		return $position;
	}

	/**
	 * Copy the dropin's library rows into the plugin table, preserving IDs.
	 *
	 * ID preservation is the point: `[page_block id="N"]` shortcodes and the
	 * `blockId` attribute on every stored Gutenberg block reference these
	 * numbers, so a re-keyed import would silently blank out live sections.
	 *
	 * Idempotent — rows whose ID already exists in the target are skipped
	 * unless $overwrite is set.
	 *
	 * @param bool $dry_run   Report without writing.
	 * @param bool $overwrite Replace rows that already exist by ID.
	 * @return array{ok:bool, message:string, legacy:int, imported:int, skipped:int, overwritten:int, remapped:array<int,string>, cleared:array<int,string>}
	 */
	public function migrate_library( bool $dry_run = false, bool $overwrite = false, int $limit = 0, int $after = 0 ): array {
		global $wpdb;

		$result = array(
			'ok'          => false,
			'message'     => '',
			'legacy'      => 0,
			'imported'    => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'overwritten' => 0,
			'remapped'    => array(),
			'cleared'     => array(),
		);

		if ( ! $this->has_legacy_table() ) {
			$result['message'] = sprintf(
				/* translators: %s: database table name */
				__( 'No dropin library table found (%s). Nothing to import.', 'page-blocks-builder' ),
				$this->legacy_table()
			);
			return $result;
		}

		// Make sure the target table exists and is at the current schema
		// before we start writing into it.
		if ( ! $dry_run && class_exists( 'gt_pb_db' ) ) {
			( new gt_pb_db() )->maybe_create_table();
		}

		$legacy = $this->legacy_table();
		$target = $wpdb->prefix . 'gt_page_blocks';

		// Batched: three of these columns are longtext, so a few hundred
		// substantial blocks in one SELECT * is plausible memory exhaustion.
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				"SELECT * FROM `{$legacy}` WHERE id > %d ORDER BY id ASC" . $limit_sql,
				$after
			)
		);
		$result['legacy'] = count( (array) $rows );

		if ( ! $rows ) {
			$result['ok']      = true;
			$result['message'] = __( 'The dropin library table is empty. Nothing to import.', 'page-blocks-builder' );
			return $result;
		}

		$existing = $this->table_exists( $target )
			? array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM `{$target}`" ) )
			: array();
		$existing = array_flip( $existing );

		foreach ( $rows as $row ) {
			$id = (int) $row->id;

			if ( isset( $existing[ $id ] ) && ! $overwrite ) {
				++$result['skipped'];
				continue;
			}

			$content  = (string) ( $row->content ?? '' );
			$php_exec = ! empty( $row->php_exec ) ? 1 : 0;

			// Recompute rather than trust the stored checksum: the dropin only
			// added php_checksum in its v7.3.0 schema, so older rows carry ''
			// and would land here permanently un-executable.
			$checksum = $php_exec ? md5( $content ) : '';

			$position = $this->map_position( (string) ( $row->position ?? '' ) );
			$original = (string) ( $row->position ?? '' );

			if ( '' !== $original && $position !== $original ) {
				if ( '' === $position ) {
					$result['cleared'][ $id ] = $original;
				} else {
					$result['remapped'][ $id ] = $original . ' → ' . $position;
				}
			}

			$data = array(
				'id'           => $id,
				'title'        => (string) ( $row->title ?? '' ),
				'slug'         => (string) ( $row->slug ?? '' ),
				'status'       => in_array( (string) ( $row->status ?? '' ), array( 'publish', 'draft', 'trash' ), true )
					? (string) $row->status
					: 'draft',
				'content'      => $content,
				'css'          => (string) ( $row->css ?? '' ),
				'js'           => (string) ( $row->js ?? '' ),
				'js_location'  => 'inline' === ( $row->js_location ?? '' ) ? 'inline' : 'footer',
				'output'       => 'file' === ( $row->output ?? '' ) ? 'file' : 'inline',
				'php_exec'     => $php_exec,
				'php_checksum' => $checksum,
				'format'       => ! empty( $row->format ) ? 1 : 0,
				'position'     => $position,
				'priority'     => (int) ( $row->priority ?? 10 ),
				'conditions'   => isset( $row->conditions ) && '' !== $row->conditions ? (string) $row->conditions : null,
				'author'       => (int) ( $row->author ?? 0 ),
				'created_at'   => (string) ( $row->created_at ?? current_time( 'mysql' ) ),
				'updated_at'   => (string) ( $row->updated_at ?? current_time( 'mysql' ) ),
			);

			// Derive formats from the data keys rather than hardcoding a
			// positional list, so reordering $data can never misalign them.
			$format_map = array(
				'id'           => '%d',
				'php_exec'     => '%d',
				'format'       => '%d',
				'priority'     => '%d',
				'author'       => '%d',
			);
			$formats = array_map(
				static fn( $key ) => $format_map[ $key ] ?? '%s',
				array_keys( $data )
			);

			if ( $dry_run ) {
				if ( isset( $existing[ $id ] ) ) {
					++$result['overwritten'];
				} else {
					++$result['imported'];
				}
				continue;
			}

			if ( isset( $existing[ $id ] ) ) {
				unset( $data['id'] );
				$formats = array_map(
					static fn( $key ) => $format_map[ $key ] ?? '%s',
					array_keys( $data )
				);
				$wpdb->update( $target, $data, array( 'id' => $id ), $formats, array( '%d' ) );
				++$result['overwritten'];
			} else {
				// The return was discarded, so a rejected insert was reported to
				// the user as an import that worked.
				if ( false === $wpdb->insert( $target, $data, $formats ) ) {
					++$result['failed'];
				} else {
					++$result['imported'];
				}
			}
		}

		if ( ! $dry_run ) {
			// Keep AUTO_INCREMENT above the highest imported ID so the next
			// block created in the plugin cannot collide with a dropin row.
			$max = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$target}`" );
			if ( $max > 0 ) {
				$wpdb->query( "ALTER TABLE `{$target}` AUTO_INCREMENT = " . ( $max + 1 ) );
			}

			if ( class_exists( 'gt_pb_db' ) ) {
				( new gt_pb_db() )->bump_asset_version();
			}
		}

		$result['ok'] = true;
		$result['message'] = sprintf(
			/* translators: 1: imported count, 2: overwritten count, 3: skipped count, 4: total rows, 5: failed count */
			__( 'Imported %1$d, replaced %2$d, skipped %3$d of %4$d dropin block(s). Failed: %5$d.', 'page-blocks-builder' ),
			$result['imported'],
			$result['overwritten'],
			$result['skipped'],
			$result['legacy'],
			$result['failed']
		);

		return $result;
	}

	/**
	 * WP-CLI: wp gt-pb migrate-library [--dry-run] [--overwrite]
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 *
	 * [--overwrite]
	 * : Replace plugin rows whose ID already exists. Destructive.
	 */
	public function cli_migrate_library( $args, $assoc_args ): void {
		$dry_run   = ! empty( $assoc_args['dry-run'] );
		$overwrite = ! empty( $assoc_args['overwrite'] );

		$result = $this->migrate_library( $dry_run, $overwrite );

		if ( ! $result['ok'] ) {
			\WP_CLI::error( $result['message'] );
			return;
		}

		foreach ( $result['remapped'] as $id => $change ) {
			\WP_CLI::log( sprintf( 'Block %d: position remapped (%s)', $id, $change ) );
		}
		foreach ( $result['cleared'] as $id => $was ) {
			\WP_CLI::warning( sprintf( 'Block %d: position "%s" has no plugin equivalent — cleared to shortcode/block only.', $id, $was ) );
		}

		if ( $dry_run ) {
			\WP_CLI::log( 'Dry run — nothing written.' );
			\WP_CLI::log( $result['message'] );
			return;
		}

		\WP_CLI::success( $result['message'] );
	}

	/**
	 * Admin tool handler (Settings → Tools → Import from dropin).
	 */
	public function handle_admin_migrate_library(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the migration.', 'page-blocks-builder' ) );
		}

		check_admin_referer( 'gt_pb_migrate_library' );

		$dry_run   = ! empty( $_POST['dry_run'] );
		$overwrite = ! empty( $_POST['overwrite'] );

		$result = $this->migrate_library( $dry_run, $overwrite );

		set_transient( 'gt_pb_library_migration_notice', $result, 60 );

		wp_safe_redirect( add_query_arg(
			array(
				'page'          => 'gt_pb_settings',
				'pbb_lib'       => 1,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Admin tool handler (Settings → Tools → Migrate blocks).
	 */
	public function handle_admin_migrate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the migration.', 'page-blocks-builder' ) );
		}

		check_admin_referer( 'gt_pb_migrate_blocks' );

		$dry_run = ! empty( $_POST['dry_run'] );
		$result = $this->migrate( $dry_run );

		wp_safe_redirect( add_query_arg(
			array(
				'page'        => 'gt_pb_settings',
				'pbb_migrated' => (int) $result['migrated'],
				'pbb_dry'      => $dry_run ? 1 : 0,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
