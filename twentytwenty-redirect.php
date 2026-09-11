<?php
/**
 * Plugin Name:       20Twenty Redirect
 * Plugin URI:        https://example.com/20twenty-redirect
 * Description:       Retire dead URLs in bulk. Import a CSV of links and serve them as 410 Gone, or flip any line item to a 301/302/307 redirect. Master on/off switch, manual add/edit/delete, hit tracking, CSV export.
 * Version:           1.1.1
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            20Twenty
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       twentytwenty-redirect
 * Update URI:        https://github.com/20Twenty-Design/wp-redirect-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TTR_VERSION', '1.1.1' );
define( 'TTR_FILE', __FILE__ );
define( 'TTR_DIR', plugin_dir_path( __FILE__ ) );
define( 'TTR_URL', plugin_dir_url( __FILE__ ) );
define( 'TTR_SLUG', 'twentytwenty-redirect' );
define( 'TTR_GITHUB_REPO', '20Twenty-Design/wp-redirect-plugin' );

require_once TTR_DIR . 'includes/class-ttr-util.php';
require_once TTR_DIR . 'includes/class-ttr-settings.php';
require_once TTR_DIR . 'includes/class-ttr-db.php';
require_once TTR_DIR . 'includes/class-ttr-csv.php';
require_once TTR_DIR . 'includes/class-ttr-redirector.php';
require_once TTR_DIR . 'includes/class-ttr-updater.php';

if ( is_admin() ) {
	require_once TTR_DIR . 'includes/class-ttr-admin.php';
}

/**
 * Boot the plugin.
 */
function ttr_bootstrap() {
	TTR_DB::maybe_upgrade();
	TTR_Redirector::boot();

	// Not admin-only: WordPress also checks for updates from cron.
	TTR_Updater::boot();

	if ( is_admin() ) {
		TTR_Admin::boot();
	}
}
add_action( 'plugins_loaded', 'ttr_bootstrap' );

/**
 * Activation: build the rules table for this site (or every site on a network activation).
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 */
function ttr_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			TTR_DB::install();
			TTR_Settings::install_defaults();
			restore_current_blog();
		}
		return;
	}

	TTR_DB::install();
	TTR_Settings::install_defaults();
}
register_activation_hook( __FILE__, 'ttr_activate' );

/**
 * Create the table on newly created sites of a network.
 *
 * @param WP_Site $site New site object.
 */
function ttr_new_site( $site ) {
	if ( ! is_plugin_active_for_network( plugin_basename( TTR_FILE ) ) ) {
		return;
	}
	switch_to_blog( (int) $site->blog_id );
	TTR_DB::install();
	TTR_Settings::install_defaults();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'ttr_new_site', 100 );

/**
 * Deactivation: drop caches only. Rules survive so a re-activation restores them.
 */
function ttr_deactivate() {
	TTR_DB::flush_cache();
}
register_deactivation_hook( __FILE__, 'ttr_deactivate' );
