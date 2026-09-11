<?php
/**
 * Plugin settings: defaults, reading and sanitised writing.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTR_Settings {

	const OPTION = 'ttr_settings';

	/**
	 * Cached settings for this request.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'               => 1,
			'run_on'                => 'init',   // 'init' = every request, '404' = only when WordPress would 404.
			'log_hits'              => 1,
			'default_status'        => 410,
			'ignore_trailing_slash' => 1,
			'case_insensitive'      => 1,
			'gone_title'            => __( 'Gone', 'twentytwenty-redirect' ),
			'gone_message'          => __( 'This page has been permanently removed and will not be coming back.', 'twentytwenty-redirect' ),
		);
	}

	/**
	 * Read settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value when the key is unknown.
	 * @return mixed
	 */
	public static function get_key( $key, $default = null ) {
		$settings = self::get();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Write settings after sanitising them.
	 *
	 * @param array $input Raw input.
	 * @return array The stored settings.
	 */
	public static function save( array $input ) {
		$current = self::get();
		$clean   = $current;

		$clean['enabled']               = empty( $input['enabled'] ) ? 0 : 1;
		$clean['log_hits']              = empty( $input['log_hits'] ) ? 0 : 1;
		$clean['ignore_trailing_slash'] = empty( $input['ignore_trailing_slash'] ) ? 0 : 1;
		$clean['case_insensitive']      = empty( $input['case_insensitive'] ) ? 0 : 1;

		if ( isset( $input['run_on'] ) ) {
			$clean['run_on'] = ( '404' === $input['run_on'] ) ? '404' : 'init';
		}

		if ( isset( $input['default_status'] ) ) {
			$clean['default_status'] = TTR_Util::sanitize_status( $input['default_status'], 410 );
		}

		if ( isset( $input['gone_title'] ) ) {
			$clean['gone_title'] = sanitize_text_field( wp_unslash( $input['gone_title'] ) );
		}

		if ( isset( $input['gone_message'] ) ) {
			$clean['gone_message'] = wp_kses_post( wp_unslash( $input['gone_message'] ) );
		}

		update_option( self::OPTION, $clean, true );
		self::$cache = $clean;

		// Normalisation rules changed, so every stored hash is stale.
		if ( $current['ignore_trailing_slash'] !== $clean['ignore_trailing_slash']
			|| $current['case_insensitive'] !== $clean['case_insensitive'] ) {
			TTR_DB::rehash_all();
		}

		TTR_DB::flush_cache();

		return $clean;
	}

	/**
	 * Only sets the master switch. Used by the toggle in the page header.
	 *
	 * @param bool $on Whether the plugin should act on requests.
	 */
	public static function set_enabled( $on ) {
		$settings            = self::get();
		$settings['enabled'] = $on ? 1 : 0;

		update_option( self::OPTION, $settings, true );
		self::$cache = $settings;
	}

	/**
	 * Store the defaults on activation without clobbering an existing config.
	 */
	public static function install_defaults() {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			update_option( self::OPTION, self::defaults(), true );
		}
	}
}
