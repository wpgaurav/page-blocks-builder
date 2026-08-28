<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ai_openai_key       = get_option( 'gt_pb_ai_openai_key', '' );
$ai_anthropic_key    = get_option( 'gt_pb_ai_anthropic_key', '' );
$ai_gemini_key       = get_option( 'gt_pb_ai_gemini_key', '' );
$ai_default_model    = get_option( 'gt_pb_ai_default_model', 'claude-sonnet-4-6' );
$preview_css         = get_option( 'gt_pb_preview_css', '' );
$preview_head_html   = get_option( 'gt_pb_preview_head_html', '' );
$preview_js_footer   = get_option( 'gt_pb_preview_js_footer', '' );
$load_reset          = (bool) get_option( 'gt_pb_load_reset', false );
$load_typography     = (bool) get_option( 'gt_pb_load_typography', false );
$load_utilities      = (bool) get_option( 'gt_pb_load_utilities', false );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Page Blocks Builder', 'page-blocks-builder' ); ?></h1>
	<p><?php esc_html_e( 'Configure where the frontend visual builder is available, your AI providers, and preview customization.', 'page-blocks-builder' ); ?></p>

	<form method="post" action="options.php">
		<?php settings_fields( 'gt_page_blocks_builder_settings' ); ?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable Builder On', 'page-blocks-builder' ); ?></th>
				<td>
					<?php foreach ( $post_types as $post_type ) : ?>
						<?php
						if ( empty( $post_type->name ) || $post_type->name === 'attachment' ) {
							continue;
						}
						?>
						<label style="display:block;margin-bottom:6px;">
							<input type="checkbox" name="<?php echo esc_attr( GT_PB_BUILDER_OPTION_POST_TYPES ); ?>[<?php echo esc_attr( $post_type->name ); ?>]" value="1" <?php checked( in_array( $post_type->name, $enabled, true ) ); ?>>
							<?php echo esc_html( $post_type->label . ' (' . $post_type->name . ')' ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>

			<tr><td colspan="2"><h2 style="margin-top: 1em;"><?php esc_html_e( 'Frontend CSS', 'page-blocks-builder' ); ?></h2><p class="description"><?php esc_html_e( 'Optional CSS layers loaded inline on the frontend. Both are theme-agnostic and load only what\'s needed.', 'page-blocks-builder' ); ?></p></td></tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Semantic Reset', 'page-blocks-builder' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="gt_pb_load_reset" value="1" <?php checked( $load_reset ); ?>>
						<?php esc_html_e( 'Load minified semantic reset CSS in <head>', 'page-blocks-builder' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Modern reset: box-sizing, list/heading defaults, accessible images, prefers-reduced-motion. ~1KB inlined when enabled.', 'page-blocks-builder' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Typography', 'page-blocks-builder' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="gt_pb_load_typography" value="1" <?php checked( $load_typography ); ?>>
						<?php esc_html_e( 'Load minified typography defaults in <head>', 'page-blocks-builder' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'System fonts (no external requests), responsive heading sizes via clamp(), proper measure (65ch), styled lists, blockquotes, code, kbd, tables, dark-mode adjustments. ~3KB inlined when enabled.', 'page-blocks-builder' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Utility Classes', 'page-blocks-builder' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="gt_pb_load_utilities" value="1" <?php checked( $load_utilities ); ?>>
						<?php esc_html_e( 'Inline utility classes that are actually used on the page', 'page-blocks-builder' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Tailwind-inspired utilities (grid, flex, spacing, typography, colors, etc.). The plugin scans your post content for class names and inlines ONLY the matching rules — no bloat.', 'page-blocks-builder' ); ?>
						<br>
						<?php esc_html_e( 'In the builder, the full utility set is always available for autocomplete.', 'page-blocks-builder' ); ?>
					</p>
				</td>
			</tr>

			<tr><td colspan="2"><h2 style="margin-top: 1em;"><?php esc_html_e( 'AI Integration', 'page-blocks-builder' ); ?></h2><p class="description"><?php esc_html_e( 'Configure AI providers for the builder\'s code generation chat sidebar (Cmd+K).', 'page-blocks-builder' ); ?></p></td></tr>
			<tr>
				<th scope="row"><label for="gt_pb_ai_openai_key"><?php esc_html_e( 'OpenAI API Key', 'page-blocks-builder' ); ?></label></th>
				<td>
					<input type="password" id="gt_pb_ai_openai_key" name="gt_pb_ai_openai_key" value="<?php echo esc_attr( $ai_openai_key ); ?>" class="regular-text" autocomplete="off">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gt_pb_ai_anthropic_key"><?php esc_html_e( 'Anthropic API Key', 'page-blocks-builder' ); ?></label></th>
				<td>
					<input type="password" id="gt_pb_ai_anthropic_key" name="gt_pb_ai_anthropic_key" value="<?php echo esc_attr( $ai_anthropic_key ); ?>" class="regular-text" autocomplete="off">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gt_pb_ai_gemini_key"><?php esc_html_e( 'Google Gemini API Key', 'page-blocks-builder' ); ?></label></th>
				<td>
					<input type="password" id="gt_pb_ai_gemini_key" name="gt_pb_ai_gemini_key" value="<?php echo esc_attr( $ai_gemini_key ); ?>" class="regular-text" autocomplete="off">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gt_pb_ai_default_model"><?php esc_html_e( 'Default AI Model', 'page-blocks-builder' ); ?></label></th>
				<td>
					<select id="gt_pb_ai_default_model" name="gt_pb_ai_default_model">
						<optgroup label="OpenAI">
							<option value="gpt-5.2" <?php selected( $ai_default_model, 'gpt-5.2' ); ?>>GPT-5.2</option>
							<option value="gpt-5-mini" <?php selected( $ai_default_model, 'gpt-5-mini' ); ?>>GPT-5 Mini</option>
							<option value="gpt-4o-mini" <?php selected( $ai_default_model, 'gpt-4o-mini' ); ?>>GPT-4o Mini</option>
						</optgroup>
						<optgroup label="Anthropic">
							<option value="claude-sonnet-4-6" <?php selected( $ai_default_model, 'claude-sonnet-4-6' ); ?>>Claude Sonnet 4.6</option>
							<option value="claude-opus-4-6" <?php selected( $ai_default_model, 'claude-opus-4-6' ); ?>>Claude Opus 4.6</option>
							<option value="claude-haiku-4-5-20241022" <?php selected( $ai_default_model, 'claude-haiku-4-5-20241022' ); ?>>Claude Haiku 4.5</option>
						</optgroup>
						<optgroup label="Google">
							<option value="gemini-3-flash-preview" <?php selected( $ai_default_model, 'gemini-3-flash-preview' ); ?>>Gemini 3 Flash</option>
						</optgroup>
					</select>
				</td>
			</tr>

			<tr><td colspan="2"><h2 style="margin-top: 1em;"><?php esc_html_e( 'Preview Customization', 'page-blocks-builder' ); ?></h2><p class="description"><?php esc_html_e( 'Add custom CSS, HTML, or JS to the builder preview iframe. Use this for custom fonts, design tokens, or scripts the preview needs.', 'page-blocks-builder' ); ?></p></td></tr>
			<tr>
				<th scope="row"><label for="gt_pb_preview_css"><?php esc_html_e( 'Preview CSS', 'page-blocks-builder' ); ?></label></th>
				<td>
					<textarea id="gt_pb_preview_css" name="gt_pb_preview_css" rows="6" class="large-text code" placeholder="/* Custom @font-face, variables, overrides */"><?php echo esc_textarea( $preview_css ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Injected into a <style> tag in the preview <head>. Example: @font-face rules, CSS custom properties.', 'page-blocks-builder' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gt_pb_preview_head_html"><?php esc_html_e( 'Preview Head HTML', 'page-blocks-builder' ); ?></label></th>
				<td>
					<textarea id="gt_pb_preview_head_html" name="gt_pb_preview_head_html" rows="3" class="large-text code" placeholder='<link rel="preconnect" href="https://fonts.example.com">'><?php echo esc_textarea( $preview_head_html ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Raw HTML added to the preview <head>. Use for preconnect hints, external stylesheets, or meta tags.', 'page-blocks-builder' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gt_pb_preview_js_footer"><?php esc_html_e( 'Preview Footer JS', 'page-blocks-builder' ); ?></label></th>
				<td>
					<textarea id="gt_pb_preview_js_footer" name="gt_pb_preview_js_footer" rows="3" class="large-text code" placeholder="// Custom JS for preview"><?php echo esc_textarea( $preview_js_footer ); ?></textarea>
					<p class="description"><?php esc_html_e( 'JavaScript added before </body> in the preview. No <script> tags needed.', 'page-blocks-builder' ); ?></p>
				</td>
			</tr>

			<tr><td colspan="2"><h2 style="margin-top: 1em;"><?php esc_html_e( 'Preview Injection Filter (Advanced)', 'page-blocks-builder' ); ?></h2></td></tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'PHP Filter', 'page-blocks-builder' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'For dynamic/conditional injection, use this filter in functions.php. Settings above feed the defaults.', 'page-blocks-builder' ); ?></p>
					<pre style="white-space: pre-wrap; margin-top: 10px; background: #f6f7f7; padding: 10px; border-radius: 4px;"><code><?php echo esc_html( "add_filter('md_page_blocks_builder_preview_injection', function(\$injection, \$post_id) {\n\t\$injection['headHtml'] .= '<meta name=\"pb-preview\" content=\"1\">';\n\t\$injection['css'] .= '.pb-preview-note{display:none;}';\n\treturn \$injection;\n}, 10, 2);" ); ?></code></pre>
				</td>
			</tr>
			</tbody>
		</table>
		<?php submit_button(); ?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Tools', 'page-blocks-builder' ); ?></h2>

	<?php if ( isset( $_GET['pbb_migrated'] ) ) : ?>
		<div class="notice notice-success inline"><p>
			<?php
			$pbb_migrated = (int) $_GET['pbb_migrated'];
			if ( ! empty( $_GET['pbb_dry'] ) ) {
				/* translators: %d: number of posts */
				printf( esc_html__( 'Dry run: %d post(s) contain legacy page blocks and would be migrated.', 'page-blocks-builder' ), $pbb_migrated );
			} else {
				/* translators: %d: number of posts */
				printf( esc_html__( 'Migrated %d post(s) to the gt-page-block/page-block block.', 'page-blocks-builder' ), $pbb_migrated );
			}
			?>
		</p></div>
	<?php endif; ?>

	<?php
	$pbb_lib_notice = isset( $_GET['pbb_lib'] ) ? get_transient( 'gt_pb_library_migration_notice' ) : false;
	if ( is_array( $pbb_lib_notice ) ) :
		delete_transient( 'gt_pb_library_migration_notice' );
		?>
		<div class="notice notice-<?php echo empty( $pbb_lib_notice['ok'] ) ? 'error' : 'success'; ?> inline">
			<p><?php echo esc_html( (string) $pbb_lib_notice['message'] ); ?></p>
			<?php if ( ! empty( $pbb_lib_notice['remapped'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Positions remapped:', 'page-blocks-builder' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<?php foreach ( $pbb_lib_notice['remapped'] as $pbb_id => $pbb_change ) : ?>
						<li><?php printf( 'Block %d: %s', (int) $pbb_id, esc_html( (string) $pbb_change ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $pbb_lib_notice['cleared'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Positions cleared (no plugin equivalent — these blocks are now shortcode/block only):', 'page-blocks-builder' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<?php foreach ( $pbb_lib_notice['cleared'] as $pbb_id => $pbb_was ) : ?>
						<li><?php printf( 'Block %d: %s', (int) $pbb_id, esc_html( (string) $pbb_was ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php $pbb_pending = class_exists( 'gt_pb_migration' ) ? ( new gt_pb_migration() )->count_pending() : 0; ?>
	<table class="form-table" role="presentation">
		<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Block migration', 'page-blocks-builder' ); ?></th>
			<td>
				<p class="description" style="margin-bottom: 8px;">
					<?php
					/* translators: %d: number of posts */
					printf( esc_html__( 'Rewrites marketers-delight/page-block to gt-page-block/page-block in stored content. %d post(s) currently contain the legacy block.', 'page-blocks-builder' ), (int) $pbb_pending );
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right: 8px;">
					<?php wp_nonce_field( 'gt_pb_migrate_blocks' ); ?>
					<input type="hidden" name="action" value="gt_pb_migrate_blocks">
					<input type="hidden" name="dry_run" value="1">
					<?php submit_button( __( 'Dry run', 'page-blocks-builder' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<?php wp_nonce_field( 'gt_pb_migrate_blocks' ); ?>
					<input type="hidden" name="action" value="gt_pb_migrate_blocks">
					<?php submit_button( __( 'Migrate blocks', 'page-blocks-builder' ), 'primary', 'submit', false, $pbb_pending ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
				<p class="description" style="margin-top: 8px;">
					<?php esc_html_e( 'Also available via WP-CLI: wp gt-pb migrate-blocks [--dry-run]', 'page-blocks-builder' ); ?>
				</p>
			</td>
		</tr>

		<?php
		$pbb_mig = class_exists( 'gt_pb_migration' ) ? new gt_pb_migration() : null;
		$pbb_lib = $pbb_mig ? $pbb_mig->count_pending_library() : array( 'legacy' => 0, 'importable' => 0, 'conflicts' => 0 );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Import from dropin', 'page-blocks-builder' ); ?></th>
			<td>
				<?php if ( ! $pbb_mig || ! $pbb_mig->has_legacy_table() ) : ?>
					<p class="description">
						<?php esc_html_e( 'No Marketers Delight page-blocks table found in this database. Nothing to import.', 'page-blocks-builder' ); ?>
					</p>
				<?php else : ?>
					<p class="description" style="margin-bottom: 8px;">
						<?php
						printf(
							/* translators: 1: total dropin rows, 2: importable count, 3: conflicting count */
							esc_html__( 'Copies the dropin library into this plugin, keeping the original block IDs so existing shortcodes and blocks keep resolving. Found %1$d dropin block(s): %2$d new, %3$d already present here.', 'page-blocks-builder' ),
							(int) $pbb_lib['legacy'],
							(int) $pbb_lib['importable'],
							(int) $pbb_lib['conflicts']
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right: 8px;">
						<?php wp_nonce_field( 'gt_pb_migrate_library' ); ?>
						<input type="hidden" name="action" value="gt_pb_migrate_library">
						<input type="hidden" name="dry_run" value="1">
						<?php submit_button( __( 'Dry run', 'page-blocks-builder' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right: 8px;">
						<?php wp_nonce_field( 'gt_pb_migrate_library' ); ?>
						<input type="hidden" name="action" value="gt_pb_migrate_library">
						<?php submit_button( __( 'Import blocks', 'page-blocks-builder' ), 'primary', 'submit', false, $pbb_lib['importable'] ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
					<?php if ( ! empty( $pbb_lib['conflicts'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"
							onsubmit="return confirm('<?php echo esc_js( __( 'This overwrites blocks in this plugin that share an ID with a dropin block. Continue?', 'page-blocks-builder' ) ); ?>');">
							<?php wp_nonce_field( 'gt_pb_migrate_library' ); ?>
							<input type="hidden" name="action" value="gt_pb_migrate_library">
							<input type="hidden" name="overwrite" value="1">
							<?php submit_button( __( 'Import and overwrite', 'page-blocks-builder' ), 'delete', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<p class="description" style="margin-top: 8px;">
						<?php esc_html_e( 'Also available via WP-CLI: wp gt-pb migrate-library [--dry-run] [--overwrite]', 'page-blocks-builder' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		</tbody>
	</table>
</div>
