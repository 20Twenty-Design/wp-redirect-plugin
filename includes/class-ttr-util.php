<?php
/**
 * Shared helpers: URL normalisation and status-code vocabulary.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTR_Util {

	/**
	 * Status codes an editor may pick, keyed by code.
	 *
	 * @return array<int,string>
	 */
	public static function statuses() {
		return array(
			410 => __( '410 Gone', 'twentytwenty-redirect' ),
			301 => __( '301 Moved Permanently', 'twentytwenty-redirect' ),
			302 => __( '302 Found (temporary)', 'twentytwenty-redirect' ),
			307 => __( '307 Temporary Redirect', 'twentytwenty-redirect' ),
			308 => __( '308 Permanent Redirect', 'twentytwenty-redirect' ),
			451 => __( '451 Unavailable For Legal Reasons', 'twentytwenty-redirect' ),
			403 => __( '403 Forbidden', 'twentytwenty-redirect' ),
			404 => __( '404 Not Found', 'twentytwenty-redirect' ),
		);
	}

	/**
	 * Codes that move the visitor somewhere else and therefore need a target.
	 *
	 * @return int[]
	 */
	public static function redirect_codes() {
		return array( 301, 302, 307, 308 );
	}

	/**
	 * Whether a status code needs a target URL.
	 *
	 * @param int $code Status code.
	 * @return bool
	 */
	public static function needs_target( $code ) {
		return in_array( (int) $code, self::redirect_codes(), true );
	}

	/**
	 * Coerce loose user input ("gone", "permanent", "301") into a supported code.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Code to use when the value is empty or unknown.
	 * @return int
	 */
	public static function sanitize_status( $value, $fallback = 410 ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : $value;

		$aliases = array(
			'gone'      => 410,
			'410'       => 410,
			'permanent' => 301,
			'perm'      => 301,
			'temporary' => 302,
			'temp'      => 302,
			'redirect'  => 301,
			'legal'     => 451,
			'forbidden' => 403,
			'notfound'  => 404,
			'not found' => 404,
		);

		if ( is_string( $value ) && isset( $aliases[ $value ] ) ) {
			$value = $aliases[ $value ];
		}

		$code     = (int) $value;
		$statuses = self::statuses();

		return isset( $statuses[ $code ] ) ? $code : (int) $fallback;
	}

	/**
	 * Split a URL or path into a normalised path and a normalised query string.
	 *
	 * Host and scheme are discarded: rules always match host-relative paths.
	 *
	 * @param string $url Raw URL or path.
	 * @return array{path:string,query:string}
	 */
	public static function split( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return array(
				'path'  => '',
				'query' => '',
			);
		}

		// Drop a fragment; it never reaches the server anyway.
		$url = explode( '#', $url, 2 )[0];

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) || 0 === strpos( $url, '//' ) ) {
			$parts = wp_parse_url( $url );
			$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
			$query = isset( $parts['query'] ) ? $parts['query'] : '';
		} else {
			$bits  = explode( '?', $url, 2 );
			$path  = $bits[0];
			$query = isset( $bits[1] ) ? $bits[1] : '';
		}

		$settings = TTR_Settings::get();

		$path = rawurldecode( $path );
		$path = preg_replace( '#/{2,}#', '/', $path );

		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		if ( ! empty( $settings['ignore_trailing_slash'] ) && '/' !== $path ) {
			$path = rtrim( $path, '/' );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		if ( ! empty( $settings['case_insensitive'] ) ) {
			$path = strtolower( $path );
		}

		return array(
			'path'  => $path,
			'query' => self::normalize_query( $query ),
		);
	}

	/**
	 * Sort query arguments so ?b=2&a=1 and ?a=1&b=2 hash identically.
	 *
	 * @param string $query Raw query string.
	 * @return string
	 */
	public static function normalize_query( $query ) {
		$query = ltrim( (string) $query, '?' );

		if ( '' === $query ) {
			return '';
		}

		$args = array();
		wp_parse_str( $query, $args );

		if ( empty( $args ) ) {
			return '';
		}

		ksort( $args );

		return build_query( $args );
	}

	/**
	 * Canonical string form of a rule source: "/path" or "/path?a=1".
	 *
	 * @param string $url Raw URL or path.
	 * @return string
	 */
	public static function normalize( $url ) {
		$parts = self::split( $url );

		if ( '' === $parts['path'] ) {
			return '';
		}

		return '' === $parts['query'] ? $parts['path'] : $parts['path'] . '?' . $parts['query'];
	}

	/**
	 * Lookup key for a normalised source.
	 *
	 * @param string $normalized Normalised source string.
	 * @return string 32-char hash.
	 */
	public static function hash( $normalized ) {
		return md5( (string) $normalized );
	}

	/**
	 * Turn a stored target into something safe to send in a Location header.
	 *
	 * Relative targets are resolved against the site home URL.
	 *
	 * @param string $target Stored target.
	 * @return string Absolute URL, or '' when the target is unusable.
	 */
	public static function absolutize( $target ) {
		$target = trim( (string) $target );

		if ( '' === $target ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $target ) ) {
			return esc_url_raw( $target );
		}

		if ( 0 === strpos( $target, '//' ) ) {
			return esc_url_raw( ( is_ssl() ? 'https:' : 'http:' ) . $target );
		}

		return esc_url_raw( home_url( '/' . ltrim( $target, '/' ) ) );
	}

	/**
	 * Validate a user-supplied regular expression before it is stored.
	 *
	 * @param string $pattern Pattern including delimiters.
	 * @return bool
	 */
	public static function is_valid_regex( $pattern ) {
		if ( '' === trim( (string) $pattern ) ) {
			return false;
		}

		// Reject the /e modifier and anything else PCRE refuses to compile.
		set_error_handler( '__return_false' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$valid = false !== preg_match( $pattern, '' );
		restore_error_handler();

		return $valid;
	}
}
