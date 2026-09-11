<?php
/**
 * Fallback body for non-redirect responses (410, 403, 451, 404).
 *
 * A theme can replace this entirely by adding a 410.php (or ttr-gone.php)
 * template. This file runs early, before the theme is loaded, so it stays
 * self-contained and does not call get_header().
 *
 * @var int    $ttr_status Status code being sent.
 * @var object $ttr_rule   The matched rule.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ttr_status  = isset( $ttr_status ) ? (int) $ttr_status : 410;
$ttr_title   = TTR_Settings::get_key( 'gone_title', __( 'Gone', 'twentytwenty-redirect' ) );
$ttr_message = TTR_Settings::get_key( 'gone_message', '' );

$ttr_headings = array(
	410 => $ttr_title,
	404 => __( 'Not found', 'twentytwenty-redirect' ),
	403 => __( 'Forbidden', 'twentytwenty-redirect' ),
	451 => __( 'Unavailable for legal reasons', 'twentytwenty-redirect' ),
);

$ttr_heading = isset( $ttr_headings[ $ttr_status ] ) ? $ttr_headings[ $ttr_status ] : $ttr_title;
?>
<!DOCTYPE html>
<html <?php echo esc_attr( is_rtl() ? 'dir="rtl"' : '' ); ?> lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, follow" />
	<title><?php echo esc_html( $ttr_status . ' ' . $ttr_heading . ' — ' . get_bloginfo( 'name' ) ); ?></title>
	<style>
		:root { color-scheme: light dark; }
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			background: #fff;
			color: #1d2327;
			font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
		}
		.ttr-gone {
			max-width: 34rem;
			padding: 2rem 1.5rem;
			text-align: center;
		}
		.ttr-gone-code {
			display: block;
			font-size: 0.75rem;
			letter-spacing: 0.18em;
			text-transform: uppercase;
			color: #757575;
			margin-bottom: 0.75rem;
		}
		.ttr-gone h1 { font-size: 1.75rem; margin: 0 0 0.75rem; }
		.ttr-gone p { margin: 0 0 1.5rem; color: #3c434a; }
		.ttr-gone a {
			display: inline-block;
			padding: 0.6rem 1.2rem;
			border: 1px solid currentColor;
			border-radius: 3px;
			color: inherit;
			text-decoration: none;
		}
		.ttr-gone a:hover, .ttr-gone a:focus { background: #1d2327; color: #fff; border-color: #1d2327; }
		@media ( prefers-color-scheme: dark ) {
			body { background: #14161a; color: #f0f0f1; }
			.ttr-gone p { color: #c3c4c7; }
			.ttr-gone a:hover, .ttr-gone a:focus { background: #f0f0f1; color: #14161a; border-color: #f0f0f1; }
		}
	</style>
</head>
<body>
	<main class="ttr-gone">
		<span class="ttr-gone-code"><?php echo esc_html( $ttr_status ); ?></span>
		<h1><?php echo esc_html( $ttr_heading ); ?></h1>
		<?php if ( '' !== trim( (string) $ttr_message ) ) : ?>
			<p><?php echo wp_kses_post( $ttr_message ); ?></p>
		<?php endif; ?>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php
			printf(
				/* translators: %s: site name. */
				esc_html__( 'Go to %s', 'twentytwenty-redirect' ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</a>
	</main>
</body>
</html>
