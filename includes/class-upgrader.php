<?php
/**
 * Versioned upgrade router.
 *
 * Before this existed there was no register_activation_hook anywhere in the
 * plugin, so schema creation rode on admin_init: it ran on whichever admin
 * request happened to arrive first after the files were swapped, several
 * concurrent requests could each enter create_table() at once, and a container
 * that never loads wp-admin - WP-CLI, cron, an integration test - never got a
 * table at all.
 *
 * Everything that has to happen once per version goes through here.
 *
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_upgrader {

	const VERSION        = '1.2';
	const VERSION_OPTION = 'gt_pb_table_version';
	const LOCK_OPTION    = 'gt_pb_upgrading';
	const CURSOR_OPTION  = 'gt_pb_upgrade_cursor';
	const CRON_HOOK      = 'gt_pb_continue_upgrade';

	/** Seconds a single batch may spend before yielding. */
	const BUDGET = 8;

	/** Rows per query in a batched step. */
	const BATCH = 200;

	/** Seconds after which a lock is assumed to be from a crashed request. */
	const LOCK_TTL = 600;

	private gt_pb_db $db;

	public function __construct( gt_pb_db $db ) {
		$this->db = $db;
	}

	/**
	 * Run any pending upgrade steps.
	 *
	 * Safe to call on every request: the common case is one option read.
	 *
	 * @param bool $dry_run Report what would run without writing.
	 * @return array<string,mixed> Report.
	 */
	public function maybe_upgrade( bool $dry_run = false ): array {
		$from = (string) get_option( self::VERSION_OPTION, '0' );

		if ( ! $dry_run && version_compare( $from, self::VERSION, '>=' ) ) {
			return array( 'ran' => false, 'reason' => 'current', 'from' => $from, 'to' => self::VERSION );
		}

		$steps = $this->pending_steps( $from );

		if ( $dry_run ) {
			return array( 'ran' => false, 'reason' => 'dry-run', 'from' => $from, 'to' => self::VERSION, 'steps' => $steps );
		}

		if ( ! $this->acquire_lock() ) {
			return array( 'ran' => false, 'reason' => 'locked', 'from' => $from, 'to' => self::VERSION );
		}

		$done = array();

		try {
			foreach ( $steps as $step ) {
				$complete = $this->run_step( $step );
				$done[]   = array( 'step' => $step, 'complete' => $complete );

				if ( ! $complete ) {
					// Out of budget. Come back for the rest rather than
					// timing out the request that happened to trigger this.
					if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
						wp_schedule_single_event( time() + 30, self::CRON_HOOK );
					}
					return array( 'ran' => true, 'reason' => 'partial', 'from' => $from, 'to' => self::VERSION, 'steps' => $done );
				}
			}

			update_option( self::VERSION_OPTION, self::VERSION, false );
			delete_option( self::CURSOR_OPTION );
		} finally {
			$this->release_lock();
		}

		return array( 'ran' => true, 'reason' => 'complete', 'from' => $from, 'to' => self::VERSION, 'steps' => $done );
	}

	/**
	 * Steps still owed, in order, for a site currently at $from.
	 *
	 * Routed with version_compare rather than the exact string equality the
	 * table check used, so a files-only rollback followed by a re-upgrade is
	 * forward-tolerant instead of skipping everything.
	 *
	 * @param string $from Stored version.
	 * @return string[]
	 */
	public function pending_steps( string $from ): array {
		$steps = array();

		// Always cheap, always safe: dbDelta is a no-op when the schema matches.
		$steps[] = 'schema';

		if ( version_compare( $from, '1.2', '<' ) ) {
			$steps[] = 'rekey_php_checksums';
			$steps[] = 'disable_broken_utilities';
		}

		return $steps;
	}

	/**
	 * @param string $step Step name.
	 * @return bool True when the step finished; false when it needs another pass.
	 */
	private function run_step( string $step ): bool {
		switch ( $step ) {
			case 'schema':
				$this->db->create_table();
				return true;

			case 'rekey_php_checksums':
				return $this->rekey_php_checksums();

			case 'disable_broken_utilities':
				return $this->disable_broken_utilities();
		}

		return true;
	}

	/**
	 * Rewrite legacy unkeyed md5 checksums to the keyed value.
	 *
	 * Reads accept both, so nothing breaks while this is pending; running it
	 * just stops the legacy branch from firing. Batched with a wall-clock
	 * budget and a resume cursor, because the 1.1 back-fill did this in one
	 * unindexed pass inside whichever admin request arrived first.
	 *
	 * @return bool True when there is nothing left to do.
	 */
	private function rekey_php_checksums(): bool {
		global $wpdb;

		$table  = $this->db->get_table_name();
		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );
		$until  = time() + self::BUDGET;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
					"SELECT id, content, php_checksum FROM {$table}
					 WHERE php_exec = 1 AND id > %d AND php_checksum <> ''
					 ORDER BY id ASC LIMIT %d",
					$cursor,
					self::BATCH
				)
			);

			if ( ! $rows ) {
				delete_option( self::CURSOR_OPTION );
				return true;
			}

			foreach ( $rows as $row ) {
				$content = (string) $row->content;

				// Only rewrite a checksum that actually vouches for this
				// content under the old scheme. Anything else is of unknown
				// provenance and must keep failing closed.
				if ( hash_equals( md5( $content ), (string) $row->php_checksum ) ) {
					$wpdb->update(
						$table,
						array( 'php_checksum' => gt_pb_php_checksum( $content ) ),
						array( 'id' => (int) $row->id ),
						array( '%s' ),
						array( '%d' )
					);
				}

				$cursor = (int) $row->id;
			}

			update_option( self::CURSOR_OPTION, $cursor, false );
		} while ( time() < $until );

		return false;
	}

	/**
	 * Turn the utility-class output off, once, on upgrade.
	 *
	 * The scanner has never emitted a single rule for this plugin's own blocks:
	 * it regexed class="..." out of raw post_content, where a page block's
	 * markup sits inside a JSON attribute with every quote escaped. 3.0.0 fixes
	 * that, which means a site with the option on would suddenly start
	 * receiving CSS it has never received - on pages that look correct today.
	 *
	 * Turning it off is therefore the zero-visual-change upgrade: the feature
	 * was emitting nothing, so nothing is lost. Re-enabling it becomes the
	 * user's deliberate act, with a notice explaining why.
	 *
	 * @return bool
	 */
	private function disable_broken_utilities(): bool {
		if ( get_option( 'gt_pb_load_utilities' ) ) {
			update_option( 'gt_pb_load_utilities', false );
			update_option( 'gt_pb_utilities_auto_disabled', 1, false );
		}

		return true;
	}

	/**
	 * Atomic mutex.
	 *
	 * add_option() returns false when the row already exists, which is the
	 * cheapest compare-and-set WordPress offers. A lock older than LOCK_TTL is
	 * treated as abandoned so a crashed request cannot wedge upgrades forever.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held && ( time() - $held ) > self::LOCK_TTL ) {
			$this->release_lock();
			return (bool) add_option( self::LOCK_OPTION, time(), '', false );
		}

		return false;
	}

	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
