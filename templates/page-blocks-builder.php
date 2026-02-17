<?php
/**
 * Template Name: Page Blocks Builder
 * Template Post Type: post, page, product, snippet, ebook, study_notes, deal, fluent-products, landing_page, portfolio, event, course, lesson
 *
 * Keeps header and footer, removes page title and layout constraints.
 * Designed for full-width section-based pages built with Page Blocks.
 *
 * @package GT_Page_Blocks_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="main" class="site-main page-blocks-main" role="main">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>

<?php
get_footer();
