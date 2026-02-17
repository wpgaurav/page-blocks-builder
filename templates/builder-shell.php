<?php
/**
 * Page Blocks Visual Builder Shell
 *
 * Frontend shell rendered only for /?build=page-blocks requests.
 * Header/footer templates are intentionally skipped.
 *
 * @since 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Page Blocks Builder', 'page-blocks-builder' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="md-page-blocks-builder-shell">
<?php wp_body_open(); ?>
<div id="md-page-block-builder-app" class="md-page-block-builder-root">
	<div class="md-page-block-builder-loading"><?php esc_html_e( 'Loading Page Blocks Builder...', 'page-blocks-builder' ); ?></div>
</div>
<?php wp_footer(); ?>
</body>
</html>
