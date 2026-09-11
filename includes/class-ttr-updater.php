<?php
/**
 * Updates from GitHub releases instead of WordPress.org.
 *
 * Whenever WordPress refreshes its update_plugins transient, the latest published
 * GitHub release is compared with the installed version. The release is cached so
 * sites stay well clear of the unauthenticated GitHub API rate limit.
 *
 * Each release must carry a twentytwenty-redirect.zip asset whose top-level folder is
 * twentytwenty-redirect/. The release workflow in .github/workflows builds it.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTR_Updater {

	const CACHE_KEY = 'ttr_github_release';

	/**
	 * File name of the installable zip attached to each release.
	 */
	const ASSET = 'twentytwenty-redirect.zip';

	/**
	 * Release looked up during this request: array, false when none, null when not yet looked up.
	 *
	 * @var array|false|null
	 */
	protected static $release = null;

	/**
	 * Hook into the update check and the "View details" modal.
	 */
	public static function boot() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
	}

	/**
	 * Report the latest release to WordPress as it saves the update_plugins transient.
	 *
	 * Listing the plugin under no_update when it is current is what lets WordPress
	 * offer the auto-update toggle for it.
	 *
	 * @param object $transient Update data about to be stored.
	 * @return object
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::release();

		if ( ! $release ) {
			return $transient;
		}

		$basename = plugin_basename( TTR_FILE );
		$item     = (object) array(
			'id'          => 'github.com/' . TTR_GITHUB_REPO,
			'slug'        => TTR_SLUG,
			'plugin'      => $basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
		);

		if ( version_compare( TTR_VERSION, $release['version'], '<' ) ) {
			$transient->response[ $basename ] = $item;
			unset( $transient->no_update[ $basename ] );
		} else {
			$transient->no_update[ $basename ] = $item;
			unset( $transient->response[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Fill the plugin details modal, which would otherwise ask WordPress.org and find nothing.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || TTR_SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::release();

		if ( ! $release ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin = get_plugin_data( TTR_FILE, false, false );

		return (object) array(
			'name'          => $plugin['Name'],
			'slug'          => TTR_SLUG,
			'version'       => $release['version'],
			'author'        => $plugin['Author'],
			'homepage'      => $release['url'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => $plugin['Description'],
				'changelog'   => '' === $release['notes']
					? sprintf( '<p><a href="%s">%s</a></p>', esc_url( $release['url'] ), esc_html__( 'See the release on GitHub.', 'twentytwenty-redirect' ) )
					: wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * Latest release, from cache when possible.
	 *
	 * "Check again" on Dashboard → Updates bypasses the cache.
	 *
	 * @return array|false
	 */
	protected static function release() {
		if ( null !== self::$release ) {
			return self::$release;
		}

		$force  = ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cached = $force ? false : get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			self::$release = empty( $cached ) ? false : $cached;
			return self::$release;
		}

		self::$release = self::fetch();

		// Failures are cached too, for less time, so an outage or rate limit is not retried on every load.
		set_site_transient(
			self::CACHE_KEY,
			self::$release ? self::$release : array(),
			self::$release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS
		);

		return self::$release;
	}

	/**
	 * Ask the GitHub API for the latest published, non-prerelease release.
	 *
	 * @return array|false
	 */
	protected static function fetch() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . TTR_GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => TTR_SLUG . '/' . TTR_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['tag_name'] ) || empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return false;
		}

		$package = '';

		foreach ( $data['assets'] as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}

		if ( '' === $package ) {
			return false;
		}

		return array(
			'version'   => ltrim( $data['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . TTR_GITHUB_REPO,
			'notes'     => isset( $data['body'] ) ? trim( (string) $data['body'] ) : '',
			'published' => isset( $data['published_at'] ) ? $data['published_at'] : '',
		);
	}
}
