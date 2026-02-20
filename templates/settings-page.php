<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ai_openai_key    = get_option( 'gt_pb_ai_openai_key', '' );
$ai_anthropic_key = get_option( 'gt_pb_ai_anthropic_key', '' );
$ai_gemini_key    = get_option( 'gt_pb_ai_gemini_key', '' );
$ai_default_model = get_option( 'gt_pb_ai_default_model', 'gpt-5.2' );
$terminal_enabled = (bool) get_option( 'gt_pb_terminal_enabled', false );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Page Blocks Builder', 'page-blocks-builder' ); ?></h1>
	<p><?php esc_html_e( 'Configure where the frontend visual builder is available.', 'page-blocks-builder' ); ?></p>

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
			<tr>
				<th scope="row"><?php esc_html_e( 'Preview Injection Filter', 'page-blocks-builder' ); ?></th>
				<td>
					<p><?php esc_html_e( 'Use this filter to inject extra HTML, CSS, or JS into the preview iframe.', 'page-blocks-builder' ); ?></p>
					<pre style="white-space: pre-wrap; margin-top: 10px;"><code><?php echo esc_html( "add_filter('md_page_blocks_builder_preview_injection', function(\$injection, \$post_id) {\n\t\$injection['headHtml'] .= '<meta name=\"pb-preview\" content=\"1\">';\n\t\$injection['css'] .= '.pb-preview-note{display:none;}';\n\t\$injection['jsHead'] .= 'window.PB_PREVIEW=true;';\n\treturn \$injection;\n}, 10, 2);" ); ?></code></pre>
				</td>
			</tr>
			<tr><td colspan="2"><h2><?php esc_html_e( 'AI Integration', 'page-blocks-builder' ); ?></h2></td></tr>
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
			<tr><td colspan="2"><h2><?php esc_html_e( 'Terminal (Beta)', 'page-blocks-builder' ); ?></h2></td></tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable Terminal', 'page-blocks-builder' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="gt_pb_terminal_enabled" value="1" <?php checked( $terminal_enabled ); ?>>
						<?php esc_html_e( 'Enable integrated terminal in the builder (admin only)', 'page-blocks-builder' ); ?>
					</label>
				</td>
			</tr>
			</tbody>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
