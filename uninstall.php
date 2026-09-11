<?php
/**
 * Removes every trace of the plugin when it is deleted from the plugins screen.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Drop the rules table and options for the current site.
 */
function ttr_uninstall_site() {
	global $wpdb;

	$table = $wpdb->prefix . 'ttr_rules';

	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

	delete_option( 'ttr_settings' );
	delete_option( 'ttr_db_version' );
	delete_transient( 'ttr_regex_rules' );
}

if ( is_multisite() ) {
	$ttr_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ttr_sites as $ttr_site_id ) {
		switch_to_blog( $ttr_site_id );
		ttr_uninstall_site();
		restore_current_blog();
	}
} else {
	ttr_uninstall_site();
}

// Cached GitHub release lookup, stored network-wide.
delete_site_transient( 'ttr_github_release' );

// Per-user screen option.
delete_metadata( 'user', 0, 'ttr_rules_per_page', '', true );
