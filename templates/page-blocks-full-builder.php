<?php
/**
 * Template Name: Full Page Builder
 *
 * Removes header and footer for complete layout control.
 * Retains head, body, wp_head, and wp_footer for SEO, scripts, and styles.
 *
 * @package GT_Page_Blocks_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'page-blocks-full-builder' ); ?>>
<?php wp_body_open(); ?>

<main id="main" class="page-blocks-main" role="main">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>

<?php wp_footer(); ?>
</body>
</html>
