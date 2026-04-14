<?php
/**
 * Page Block Edit Form
 *
 * @since 7.0.0
 * @var object|null $block Existing block or null for new.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$is_new    = ! $block;
$title     = $is_new ? '' : $block->title;
$slug      = $is_new ? '' : $block->slug;
$status    = $is_new ? 'publish' : $block->status;
$content   = $is_new ? '' : $block->content;
$css       = $is_new ? '' : $block->css;
$js        = $is_new ? '' : $block->js;
$js_loc    = $is_new ? 'footer' : $block->js_location;
$output    = $is_new ? 'inline' : $block->output;
$php_exec  = $is_new ? 0 : (int) $block->php_exec;
$format    = $is_new ? 0 : (int) $block->format;
$position  = $is_new ? '' : $block->position;
$priority  = $is_new ? 10 : (int) $block->priority;
$raw_conds = $is_new ? '' : $block->conditions;
$conditions = $raw_conds ? json_decode( $raw_conds, true ) : array();

$positions = gt_pb_get_positions();
$post_types = get_post_types( array( 'public' => true ), 'objects' );
$page_types = array(
	'front_page' => __( 'Front Page', 'md' ),
	'blog'       => __( 'Blog Page', 'md' ),
	'singular'   => __( 'Single Posts/Pages', 'md' ),
	'archive'    => __( 'Archives', 'md' ),
	'search'     => __( 'Search Results', 'md' ),
	'404'        => __( '404 Page', 'md' ),
);
?>
<div class="wrap">
	<h1>
		<?php echo $is_new ? esc_html__( 'Add New Page Block', 'md' ) : esc_html__( 'Edit Page Block', 'md' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=gt_page_blocks' ) ); ?>" class="page-title-action"><?php esc_html_e( '← All Page Blocks', 'md' ); ?></a>
	</h1>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Page block saved.', 'md' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="" id="md-pb-edit-form">
		<?php wp_nonce_field( 'gt_pb_save_block' ); ?>
		<input type="hidden" name="gt_pb_save" value="1">
		<?php if ( ! $is_new ) : ?>
			<input type="hidden" name="block_id" value="<?php echo (int) $block->id; ?>">
		<?php endif; ?>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">

				<!-- Main content area -->
				<div id="post-body-content">

					<!-- Title -->
					<div id="titlediv">
						<div id="titlewrap">
							<label class="screen-reader-text" for="block_title"><?php esc_html_e( 'Title', 'md' ); ?></label>
							<input type="text" name="block_title" id="block_title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Enter page block title', 'md' ); ?>" autocomplete="off" spellcheck="true" size="30">
						</div>
					</div>

					<!-- Slug -->
					<div class="md-pb-slug-wrap" style="margin: 8px 0 16px;">
						<label for="block_slug"><strong><?php esc_html_e( 'Slug:', 'md' ); ?></strong></label>
						<input type="text" name="block_slug" id="block_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text code" placeholder="<?php esc_attr_e( 'auto-generated-from-title', 'md' ); ?>">
					</div>

					<!-- Code Editor Tabs -->
					<div class="md-pb-editors">
						<div class="md-pb-editor-tabs">
							<button type="button" class="md-pb-tab active" data-tab="html">
								<?php esc_html_e( 'HTML', 'md' ); ?>
								<?php if ( ! empty( $content ) ) : ?><span class="md-pb-tab-dot"></span><?php endif; ?>
							</button>
							<button type="button" class="md-pb-tab" data-tab="css">
								<?php esc_html_e( 'CSS', 'md' ); ?>
								<?php if ( ! empty( $css ) ) : ?><span class="md-pb-tab-dot"></span><?php endif; ?>
							</button>
							<button type="button" class="md-pb-tab" data-tab="js">
								<?php esc_html_e( 'JavaScript', 'md' ); ?>
								<?php if ( ! empty( $js ) ) : ?><span class="md-pb-tab-dot"></span><?php endif; ?>
							</button>
						</div>

						<div class="md-pb-editor-panel active" data-panel="html">
							<textarea name="block_content" id="block_content" rows="25"><?php echo esc_textarea( $content ); ?></textarea>
						</div>

						<div class="md-pb-editor-panel" data-panel="css">
							<textarea name="block_css" id="block_css" rows="25"><?php echo esc_textarea( $css ); ?></textarea>
						</div>

						<div class="md-pb-editor-panel" data-panel="js">
							<textarea name="block_js" id="block_js" rows="25"><?php echo esc_textarea( $js ); ?></textarea>
						</div>
					</div>

					<!-- Preview Panel -->
					<div class="md-pb-preview-wrap">
						<div class="md-pb-preview-toolbar">
							<button type="button" id="md-pb-preview-btn" class="button">
								<span class="dashicons dashicons-visibility"></span>
								<?php esc_html_e( 'Preview', 'md' ); ?>
							</button>
							<span class="md-pb-preview-status" id="md-pb-preview-status"></span>
							<div class="md-pb-preview-viewports">
								<button type="button" class="md-pb-viewport active" data-width="100%" title="<?php esc_attr_e( 'Desktop', 'md' ); ?>">
									<span class="dashicons dashicons-desktop"></span>
								</button>
								<button type="button" class="md-pb-viewport" data-width="768px" title="<?php esc_attr_e( 'Tablet', 'md' ); ?>">
									<span class="dashicons dashicons-tablet"></span>
								</button>
								<button type="button" class="md-pb-viewport" data-width="375px" title="<?php esc_attr_e( 'Mobile', 'md' ); ?>">
									<span class="dashicons dashicons-smartphone"></span>
								</button>
							</div>
						</div>
						<div class="md-pb-preview-container" id="md-pb-preview-container" style="display: none;">
							<iframe id="md-pb-preview-iframe" sandbox="allow-scripts allow-same-origin" title="<?php esc_attr_e( 'Page Block Preview', 'md' ); ?>"></iframe>
						</div>
					</div>

				</div>

				<!-- Sidebar -->
				<div id="postbox-container-1" class="postbox-container">

					<!-- Publish box -->
					<div class="postbox">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Publish', 'md' ); ?></h2>
						</div>
						<div class="inside">
							<div class="misc-pub-section">
								<label for="block_status"><strong><?php esc_html_e( 'Status:', 'md' ); ?></strong></label>
								<select name="block_status" id="block_status">
									<option value="publish" <?php selected( $status, 'publish' ); ?>><?php esc_html_e( 'Published', 'md' ); ?></option>
									<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'md' ); ?></option>
								</select>
							</div>

							<?php if ( ! $is_new ) : ?>
								<div class="misc-pub-section">
									<span class="dashicons dashicons-calendar-alt"></span>
									<?php
									printf(
										esc_html__( 'Created: %s', 'md' ),
										esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $block->created_at ) ) )
									);
									?>
								</div>
							<?php endif; ?>

							<div id="major-publishing-actions">
								<?php if ( ! $is_new ) : ?>
									<div id="delete-action">
										<?php
										$trash_url = wp_nonce_url(
											admin_url( 'admin.php?page=gt_page_blocks&action=trash&id=' . $block->id ),
											'md_pb_trash_' . $block->id
										);
										?>
										<a href="<?php echo esc_url( $trash_url ); ?>" class="submitdelete"><?php esc_html_e( 'Move to Trash', 'md' ); ?></a>
									</div>
								<?php endif; ?>
								<div id="publishing-action">
									<input type="submit" class="button button-primary button-large" value="<?php echo $is_new ? esc_attr__( 'Create', 'md' ) : esc_attr__( 'Update', 'md' ); ?>">
								</div>
								<div class="clear"></div>
							</div>
						</div>
					</div>

					<!-- Usage box -->
					<?php if ( ! $is_new ) : ?>
						<div class="postbox">
							<div class="postbox-header">
								<h2><?php esc_html_e( 'Usage', 'md' ); ?></h2>
							</div>
							<div class="inside md-pb-usage-box">
								<div class="md-pb-usage-item">
									<span class="md-pb-usage-label"><?php esc_html_e( 'Shortcode', 'md' ); ?></span>
									<code class="md-pb-usage-code">[page_block id="<?php echo (int) $block->id; ?>"]</code>
								</div>
								<div class="md-pb-usage-item">
									<span class="md-pb-usage-label"><?php esc_html_e( 'By slug', 'md' ); ?></span>
									<code class="md-pb-usage-code">[page_block slug="<?php echo esc_attr( $slug ); ?>"]</code>
								</div>
								<div class="md-pb-usage-item">
									<span class="md-pb-usage-label"><?php esc_html_e( 'PHP', 'md' ); ?></span>
									<code class="md-pb-usage-code md-pb-usage-code--small">do_shortcode('[page_block id="<?php echo (int) $block->id; ?>"]');</code>
								</div>
								<div class="md-pb-usage-item">
									<span class="md-pb-usage-label"><?php esc_html_e( 'REST API', 'md' ); ?></span>
									<code class="md-pb-usage-code md-pb-usage-code--small">/wp-json/md/v1/page-blocks/<?php echo (int) $block->id; ?></code>
								</div>
							</div>
						</div>
					<?php endif; ?>

					<!-- Settings box -->
					<div class="postbox">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Settings', 'md' ); ?></h2>
						</div>
						<div class="inside md-pb-settings-box">
							<div class="md-pb-setting-row">
								<label for="block_output"><?php esc_html_e( 'CSS/JS Output', 'md' ); ?></label>
								<select name="block_output" id="block_output">
									<option value="inline" <?php selected( $output, 'inline' ); ?>><?php esc_html_e( 'Inline', 'md' ); ?></option>
									<option value="file" <?php selected( $output, 'file' ); ?>><?php esc_html_e( 'External File', 'md' ); ?></option>
								</select>
							</div>
							<div class="md-pb-setting-row">
								<label for="block_js_location"><?php esc_html_e( 'JS Location', 'md' ); ?></label>
								<select name="block_js_location" id="block_js_location">
									<option value="footer" <?php selected( $js_loc, 'footer' ); ?>><?php esc_html_e( 'Footer', 'md' ); ?></option>
									<option value="inline" <?php selected( $js_loc, 'inline' ); ?>><?php esc_html_e( 'Inline', 'md' ); ?></option>
								</select>
							</div>
							<div class="md-pb-setting-row md-pb-setting-row--checks">
								<label>
									<input type="checkbox" name="block_php_exec" value="1" <?php checked( $php_exec, 1 ); ?>>
									<?php esc_html_e( 'Execute PHP', 'md' ); ?>
								</label>
								<label>
									<input type="checkbox" name="block_format" value="1" <?php checked( $format, 1 ); ?>>
									<?php esc_html_e( 'Auto-format (wpautop)', 'md' ); ?>
								</label>
							</div>
						</div>
					</div>

					<!-- Position box -->
					<div class="postbox">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Hook Position', 'md' ); ?></h2>
						</div>
						<div class="inside">
							<p>
								<label for="block_position"><strong><?php esc_html_e( 'Position:', 'md' ); ?></strong></label><br>
								<select name="block_position" id="block_position" style="width: 100%;">
									<?php foreach ( $positions as $hook => $label ) : ?>
										<option value="<?php echo esc_attr( $hook ); ?>" <?php selected( $position, $hook ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>

							<p>
								<label for="block_priority"><strong><?php esc_html_e( 'Priority:', 'md' ); ?></strong></label><br>
								<input type="number" name="block_priority" id="block_priority" value="<?php echo (int) $priority; ?>" min="0" max="999" step="1" class="small-text">
								<span class="description"><?php esc_html_e( 'Lower = earlier', 'md' ); ?></span>
							</p>

							<!-- Conditions (shown when position is set) -->
							<div id="md-pb-conditions" style="<?php echo empty( $position ) ? 'display: none;' : ''; ?>">
								<hr>
								<p><strong><?php esc_html_e( 'Display Conditions', 'md' ); ?></strong></p>
								<p class="description"><?php esc_html_e( 'Leave empty to display everywhere.', 'md' ); ?></p>

								<p><strong><?php esc_html_e( 'Post Types:', 'md' ); ?></strong></p>
								<?php foreach ( $post_types as $pt ) : ?>
									<label style="display: block; margin-bottom: 4px;">
										<input type="checkbox" name="block_condition_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
											<?php checked( in_array( $pt->name, $conditions['post_types'] ?? array(), true ) ); ?>>
										<?php echo esc_html( $pt->label ); ?>
									</label>
								<?php endforeach; ?>

								<p style="margin-top: 12px;"><strong><?php esc_html_e( 'Page Types:', 'md' ); ?></strong></p>
								<?php foreach ( $page_types as $key => $label ) : ?>
									<label style="display: block; margin-bottom: 4px;">
										<input type="checkbox" name="block_condition_page_types[]" value="<?php echo esc_attr( $key ); ?>"
											<?php checked( in_array( $key, $conditions['page_types'] ?? array(), true ) ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>

								<p style="margin-top: 12px;">
									<label for="block_condition_post_ids"><strong><?php esc_html_e( 'Specific Post IDs:', 'md' ); ?></strong></label><br>
									<input type="text" name="block_condition_post_ids" id="block_condition_post_ids"
										value="<?php echo esc_attr( implode( ', ', $conditions['post_ids'] ?? array() ) ); ?>"
										class="regular-text" placeholder="<?php esc_attr_e( '123, 456, 789', 'md' ); ?>">
								</p>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>
	</form>
</div>
