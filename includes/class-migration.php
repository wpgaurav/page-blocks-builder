<?php
/**
 * Migration: marketers-delight/page-block → gt-page-block/page-block.
 *
 * Rewrites serialized block comments in post_content across all public
 * post types (skipping revisions). Available as:
 *
 *   - WP-CLI:  wp gt-pb migrate-blocks [--dry-run]
 *   - Admin:   Page Blocks → Settings → Tools → "Migrate blocks"
 *
 * The legacy block name stays registered server-side so un-migrated
 * content keeps rendering; this migration makes the editor use the new
 * block everywhere and lets the legacy registration be dropped later.
 *
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_migration {

	const OLD_NAME = 'marketers-delight/page-block';
	const NEW_NAME = 'gt-page-block/page-block';

	public function __construct() {
		add_action( 'admin_post_gt_pb_migrate_blocks', array( $this, 'handle_admin_migrate' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'gt-pb migrate-blocks', array( $this, 'cli_migrate' ) );
		}
	}

	/**
	 * Count posts still containing the legacy block.
	 */
	public function count_pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type NOT IN ( 'revision', 'auto-draft' )
			   AND post_content LIKE %s",
			'%' . $wpdb->esc_like( 'wp:' . self::OLD_NAME ) . '%'
		) );
	}

	/**
	 * Rewrite legacy block delimiters in a content string.
	 */
	public function migrate_content( string $content ): string {
		// Opening, self-closing, and closing delimiters; exact block name
		// bounded by whitespace or the comment terminator so longer names
		// are never clipped.
		return (string) preg_replace(
			'#(<!--\s*/?wp:)' . preg_quote( self::OLD_NAME, '#' ) . '(?=[\s{]|\s*/?-->)#',
			'$1' . self::NEW_NAME,
			$content
		);
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
	public function migrate( bool $dry_run = false ): array {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type NOT IN ( 'revision', 'auto-draft' )
			   AND post_content LIKE %s",
			'%' . $wpdb->esc_like( 'wp:' . self::OLD_NAME ) . '%'
		) );

		$migrated = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;
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
			'scanned'  => count( $ids ),
			'migrated' => count( $migrated ),
			'ids'      => $migrated,
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
