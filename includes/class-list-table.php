<?php
/**
 * Page Blocks List Table
 *
 * WP_List_Table subclass for displaying page blocks in the admin.
 *
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class gt_pb_list_table extends WP_List_Table {

	private gt_pb_db $db;

	public function __construct( gt_pb_db $db ) {
		$this->db = $db;

		parent::__construct( array(
			'singular' => 'page-block',
			'plural'   => 'page-blocks',
			'ajax'     => false,
		) );
	}

	/**
	 * Get table columns.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox">',
			'title'      => __( 'Title', 'md' ),
			'slug'       => __( 'Slug', 'md' ),
			'shortcode'  => __( 'Shortcode', 'md' ),
			'position'   => __( 'Position', 'md' ),
			'status'     => __( 'Status', 'md' ),
			'author'     => __( 'Author', 'md' ),
			'updated_at' => __( 'Last Modified', 'md' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns(): array {
		return array(
			'title'      => array( 'title', false ),
			'slug'       => array( 'slug', false ),
			'updated_at' => array( 'updated_at', true ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions(): array {
		$status = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : '';

		if ( $status === 'trash' ) {
			return array(
				'restore' => __( 'Restore', 'md' ),
				'delete'  => __( 'Delete Permanently', 'md' ),
			);
		}

		return array(
			'trash' => __( 'Move to Trash', 'md' ),
		);
	}

	/**
	 * Status filter views.
	 *
	 * @return array
	 */
	protected function get_views(): array {
		$current = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : '';
		$base_url = admin_url( 'admin.php?page=gt_page_blocks' );

		$counts = array(
			''        => $this->db->count( array( 'status' => '' ) ), // All non-trash
			'publish' => $this->db->count( array( 'status' => 'publish' ) ),
			'draft'   => $this->db->count( array( 'status' => 'draft' ) ),
			'trash'   => $this->db->count( array( 'status' => 'trash' ) ),
		);

		// "All" counts everything except trash
		$counts[''] = $counts['publish'] + $counts['draft'];

		$views = array();

		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( $base_url ),
			$current === '' ? 'current' : '',
			__( 'All', 'md' ),
			$counts['']
		);

		if ( $counts['publish'] > 0 ) {
			$views['publish'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', 'publish', $base_url ) ),
				$current === 'publish' ? 'current' : '',
				__( 'Published', 'md' ),
				$counts['publish']
			);
		}

		if ( $counts['draft'] > 0 ) {
			$views['draft'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', 'draft', $base_url ) ),
				$current === 'draft' ? 'current' : '',
				__( 'Draft', 'md' ),
				$counts['draft']
			);
		}

		if ( $counts['trash'] > 0 ) {
			$views['trash'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', 'trash', $base_url ) ),
				$current === 'trash' ? 'current' : '',
				__( 'Trash', 'md' ),
				$counts['trash']
			);
		}

		return $views;
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items(): void {
		$per_page = 100;
		$current_page = $this->get_pagenum();
		$status = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : '';
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( $_REQUEST['orderby'] ) : 'updated_at';
		$order = isset( $_REQUEST['order'] ) ? sanitize_text_field( $_REQUEST['order'] ) : 'DESC';

		$query_args = array(
			'per_page' => $per_page,
			'page'     => $current_page,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		// Default view excludes trash
		if ( $status === '' ) {
			// We need to query publish + draft
			// The DB layer doesn't support OR status, so we'll get all and filter
			// Actually, let's just not filter by status for the "all" view
			// and let the query return everything except trash
		} else {
			$query_args['status'] = $status;
		}

		if ( $search ) {
			$query_args['search'] = $search;
		}

		// For the "all" view, we need a custom approach since we want to exclude trash
		if ( $status === '' ) {
			// Get publish + draft items
			$this->items = $this->query_non_trash( $query_args );
			$total_items = $this->count_non_trash( $query_args );
		} else {
			$this->items = $this->db->query( $query_args );
			$total_items = $this->db->count( $query_args );
		}

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total_items / $per_page ),
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	/**
	 * Query non-trash items (for the "All" view).
	 */
	private function query_non_trash( array $args ): array {
		global $wpdb;
		$table = $this->db->get_table_name();

		$where = array( "status != 'trash'" );
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(title LIKE %s OR slug LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$allowed = array( 'id', 'title', 'slug', 'status', 'updated_at', 'created_at' );
		$orderby = in_array( $args['orderby'] ?? 'updated_at', $allowed, true ) ? $args['orderby'] : 'updated_at';
		$order = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );

		$sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$values[] = $per_page;
		$values[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Count non-trash items.
	 */
	private function count_non_trash( array $args ): int {
		global $wpdb;
		$table = $this->db->get_table_name();

		$where = array( "status != 'trash'" );
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(title LIKE %s OR slug LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";

		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Checkbox column.
	 */
	public function column_cb( $item ): string {
		$id = is_object( $item ) ? (int) $item->id : (int) ( $item['id'] ?? 0 );
		return sprintf( '<input type="checkbox" name="block_ids[]" value="%d">', $id );
	}

	/**
	 * Title column with row actions.
	 */
	public function column_title( $item ): string {
		$edit_url = admin_url( 'admin.php?page=gt_pb_edit&id=' . $item->id );
		$status = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : '';

		$title = '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $item->title ?: '(no title)' ) . '</a></strong>';

		$actions = array();

		if ( $item->status === 'trash' ) {
			$restore_url = wp_nonce_url(
				admin_url( 'admin.php?page=gt_page_blocks&action=restore&id=' . $item->id . '&status=' . $status ),
				'md_pb_restore_' . $item->id
			);
			$delete_url = wp_nonce_url(
				admin_url( 'admin.php?page=gt_page_blocks&action=delete&id=' . $item->id . '&status=' . $status ),
				'md_pb_delete_' . $item->id
			);
			$actions['restore'] = '<a href="' . esc_url( $restore_url ) . '">' . __( 'Restore', 'md' ) . '</a>';
			$actions['delete'] = '<a href="' . esc_url( $delete_url ) . '" class="submitdelete" onclick="return confirm(\'' . esc_attr__( 'Delete permanently?', 'md' ) . '\')">' . __( 'Delete Permanently', 'md' ) . '</a>';
		} else {
			$trash_url = wp_nonce_url(
				admin_url( 'admin.php?page=gt_page_blocks&action=trash&id=' . $item->id . '&status=' . $status ),
				'md_pb_trash_' . $item->id
			);
			$duplicate_url = wp_nonce_url(
				admin_url( 'admin.php?page=gt_page_blocks&action=duplicate&id=' . $item->id ),
				'md_pb_duplicate_' . $item->id
			);
			$actions['edit'] = '<a href="' . esc_url( $edit_url ) . '">' . __( 'Edit', 'md' ) . '</a>';
			$actions['duplicate'] = '<a href="' . esc_url( $duplicate_url ) . '">' . __( 'Duplicate', 'md' ) . '</a>';
			$actions['trash'] = '<a href="' . esc_url( $trash_url ) . '" class="submitdelete">' . __( 'Trash', 'md' ) . '</a>';
		}

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Slug column.
	 */
	public function column_slug( $item ): string {
		return '<code>' . esc_html( $item->slug ) . '</code>';
	}

	/**
	 * Shortcode column.
	 */
	public function column_shortcode( $item ): string {
		$shortcode = '[page_block id="' . $item->id . '"]';
		return '<code style="cursor: pointer; user-select: all;" title="' . esc_attr__( 'Click to select', 'md' ) . '">' . esc_html( $shortcode ) . '</code>';
	}

	/**
	 * Position column.
	 */
	public function column_position( $item ): string {
		if ( empty( $item->position ) ) {
			return '<span class="dashicons dashicons-minus" style="color: #999;"></span>';
		}

		$positions = gt_pb_get_positions();
		$label = $positions[ $item->position ] ?? $item->position;

		return esc_html( $label );
	}

	/**
	 * Status column.
	 */
	public function column_status( $item ): string {
		$labels = array(
			'publish' => __( 'Published', 'md' ),
			'draft'   => __( 'Draft', 'md' ),
			'trash'   => __( 'Trash', 'md' ),
		);

		return esc_html( $labels[ $item->status ] ?? $item->status );
	}

	/**
	 * Author column.
	 */
	public function column_author( $item ): string {
		$user = get_userdata( $item->author );
		return $user ? esc_html( $user->display_name ) : '—';
	}

	/**
	 * Updated at column.
	 */
	public function column_updated_at( $item ): string {
		return esc_html( human_time_diff( strtotime( $item->updated_at ), current_time( 'timestamp' ) ) ) . ' ' . __( 'ago', 'md' );
	}

	/**
	 * Message when no items are found.
	 */
	public function no_items(): void {
		$status = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : '';

		if ( $status === 'trash' ) {
			esc_html_e( 'No page blocks in the trash.', 'md' );
		} else {
			echo esc_html__( 'No page blocks found.', 'md' ) . ' ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=gt_pb_edit&action=new' ) ) . '">';
			esc_html_e( 'Create your first page block', 'md' );
			echo '</a>';
		}
	}
}
