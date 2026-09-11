<?php
/**
 * Front-end request handling: match the incoming URL against the rules and act.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTR_Redirector {

	/**
	 * Attach to the right hook, or to nothing when the master switch is off.
	 */
	public static function boot() {
		if ( ! TTR_Settings::get_key( 'enabled' ) ) {
			return;
		}

		if ( self::is_ignored_context() ) {
			return;
		}

		if ( '404' === TTR_Settings::get_key( 'run_on' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'handle_404' ), 0 );
			return;
		}

		add_action( 'init', array( __CLASS__, 'handle' ), 1 );
	}

	/**
	 * Requests the plugin never touches.
	 *
	 * @return bool
	 */
	protected static function is_ignored_context() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		return false;
	}

	/**
	 * The raw request path, or '' when it should be skipped.
	 *
	 * @return string
	 */
	protected static function request_uri() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Never intercept the admin, login, REST or cron entry points.
		$skip = array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php', '/xmlrpc.php', '/wp-content/', '/wp-includes/' );

		foreach ( $skip as $prefix ) {
			if ( 0 === stripos( $uri, $prefix ) ) {
				return '';
			}
		}

		return $uri;
	}

	/**
	 * Only act when WordPress has already decided the URL is a 404.
	 */
	public static function handle_404() {
		if ( ! is_404() ) {
			return;
		}

		self::handle();
	}

	/**
	 * Match the current request and execute the rule that wins.
	 */
	public static function handle() {
		$uri = self::request_uri();

		if ( '' === $uri ) {
			return;
		}

		$parts = TTR_Util::split( $uri );

		if ( '' === $parts['path'] ) {
			return;
		}

		$with_query = '' === $parts['query'] ? '' : $parts['path'] . '?' . $parts['query'];

		// A rule stored with a query string is more specific, so it is tried first.
		$candidates = array();

		if ( '' !== $with_query ) {
			$candidates[] = TTR_Util::hash( $with_query );
		}

		$candidates[] = TTR_Util::hash( $parts['path'] );

		$rule    = TTR_DB::find_by_hashes( $candidates );
		$matches = array();

		if ( ! $rule ) {
			$subject = '' === $with_query ? $parts['path'] : $with_query;
			$rule    = self::match_regex( $subject, $matches );
		}

		if ( ! $rule ) {
			return;
		}

		/**
		 * Filter the rule about to be applied. Return null to skip it.
		 *
		 * @param object $rule    Matched rule row.
		 * @param string $uri     Raw request URI.
		 * @param array  $matches Regex captures, empty for exact matches.
		 */
		$rule = apply_filters( 'ttr_matched_rule', $rule, $uri, $matches );

		if ( ! $rule ) {
			return;
		}

		self::execute( $rule, $matches );
	}

	/**
	 * Try the regex rules in ID order; first hit wins.
	 *
	 * @param string $subject Normalised request path (with query when present).
	 * @param array  $matches Filled with the captures of the winning pattern.
	 * @return object|null
	 */
	protected static function match_regex( $subject, array &$matches ) {
		foreach ( TTR_DB::get_regex_rules() as $rule ) {
			$found = array();

			// Patterns are validated on save; a broken one is skipped rather than fatal.
			if ( ! TTR_Util::is_valid_regex( $rule->source_path ) ) {
				continue;
			}

			if ( preg_match( $rule->source_path, $subject, $found ) ) {
				$matches = $found;
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Send the response for a matched rule. Always exits.
	 *
	 * @param object $rule    Matched rule.
	 * @param array  $matches Regex captures.
	 */
	protected static function execute( $rule, array $matches = array() ) {
		if ( TTR_Settings::get_key( 'log_hits' ) ) {
			TTR_DB::record_hit( $rule->id );
		}

		$code = (int) $rule->status_code;

		do_action( 'ttr_before_response', $rule, $matches );

		if ( TTR_Util::needs_target( $code ) && '' !== trim( (string) $rule->target ) ) {
			$target = self::expand_target( $rule->target, $matches );
			$target = TTR_Util::absolutize( $target );

			if ( '' !== $target ) {
				nocache_headers();
				wp_redirect( $target, $code ); // phpcs:ignore WordPress.Security.SafeRedirect -- destinations are set by an administrator and may be external.
				exit;
			}
		}

		self::render_status( $code, $rule );
	}

	/**
	 * Substitute $1..$9 backreferences from a regex match into the target.
	 *
	 * @param string $target  Stored target.
	 * @param array  $matches Regex captures.
	 * @return string
	 */
	protected static function expand_target( $target, array $matches ) {
		if ( empty( $matches ) ) {
			return $target;
		}

		foreach ( $matches as $index => $value ) {
			$target = str_replace( array( '$' . $index, '\\' . $index ), $value, $target );
		}

		return $target;
	}

	/**
	 * Send a non-redirect status (410, 403, 451, 404) with a body.
	 *
	 * A theme may override the page by shipping a 410.php template.
	 *
	 * @param int    $code Status code.
	 * @param object $rule Matched rule.
	 */
	protected static function render_status( $code, $rule ) {
		nocache_headers();
		status_header( $code );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}

		$template = '';

		if ( function_exists( 'locate_template' ) ) {
			$template = locate_template( array( $code . '.php', 'ttr-gone.php' ) );
		}

		if ( ! $template ) {
			$template = TTR_DIR . 'templates/gone.php';
		}

		/**
		 * Filter the template used for non-redirect responses.
		 *
		 * @param string $template Absolute path.
		 * @param int    $code     Status code.
		 * @param object $rule     Matched rule.
		 */
		$template = apply_filters( 'ttr_status_template', $template, $code, $rule );

		// Exposed to the template.
		$ttr_status = $code;
		$ttr_rule   = $rule;

		if ( $template && file_exists( $template ) ) {
			include $template;
		}

		exit;
	}
}
