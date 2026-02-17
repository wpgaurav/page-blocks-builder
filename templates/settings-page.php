<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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
			</tbody>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
