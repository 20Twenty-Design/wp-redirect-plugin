<?php
/**
 * Admin screen: menu, form handling and rendering.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTR_Admin {

	const CAP    = 'manage_options';
	const NONCE  = 'ttr_admin';
	const NOTICE = 'ttr_notices_';

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	protected static $hook = '';

	/**
	 * Register admin hooks.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TTR_FILE ), array( __CLASS__, 'plugin_action_links' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_ttr_rules_per_page', array( __CLASS__, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Add the page under Settings.
	 */
	public static function register_page() {
		self::$hook = add_options_page(
			__( '20Twenty Redirect', 'twentytwenty-redirect' ),
			__( '20Twenty Redirect', 'twentytwenty-redirect' ),
			self::CAP,
			TTR_SLUG,
			array( __CLASS__, 'render' )
		);

		if ( ! self::$hook ) {
			return;
		}

		// Runs before any output, so it can redirect and stream downloads.
		add_action( 'load-' . self::$hook, array( __CLASS__, 'handle_actions' ) );
		add_action( 'load-' . self::$hook, array( __CLASS__, 'add_screen_options' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * "Settings" link on the plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'twentytwenty-redirect' ) )
		);

		return $links;
	}

	/**
	 * Per-page screen option.
	 */
	public static function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Rules per page', 'twentytwenty-redirect' ),
				'default' => 25,
				'option'  => 'ttr_rules_per_page',
			)
		);
	}

	/**
	 * Persist the per-page option.
	 *
	 * @param mixed  $status Default return.
	 * @param string $option Option name.
	 * @param mixed  $value  Chosen value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		return ( 'ttr_rules_per_page' === $option ) ? (int) $value : $status;
	}

	/**
	 * URL of the settings page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => TTR_SLUG ), $args ),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Load styles and the small edit-row script.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'ttr-admin', TTR_URL . 'assets/admin.css', array(), TTR_VERSION );
		wp_enqueue_script( 'ttr-admin', TTR_URL . 'assets/admin.js', array(), TTR_VERSION, true );

		wp_localize_script(
			'ttr-admin',
			'ttrL10n',
			array(
				'confirmDelete'  => __( 'Delete this rule?', 'twentytwenty-redirect' ),
				'confirmReplace' => __( 'This deletes every existing rule before importing. Continue?', 'twentytwenty-redirect' ),
				'redirectCodes'  => TTR_Util::redirect_codes(),
			)
		);
	}

	/**
	 * Queue an admin notice for the current user, shown after the redirect.
	 *
	 * @param string $message Notice text (already translated).
	 * @param string $type    success|error|warning|info.
	 */
	protected static function notice( $message, $type = 'success' ) {
		$key      = self::NOTICE . get_current_user_id();
		$notices  = get_transient( $key );
		$notices  = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);

		set_transient( $key, $notices, 60 );
	}

	/**
	 * Print and clear the queued notices.
	 */
	protected static function print_notices() {
		$key     = self::NOTICE . get_current_user_id();
		$notices = get_transient( $key );

		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				wp_kses_post( $notice['message'] )
			);
		}
	}

	/**
	 * Send the browser back to the page, keeping the current view.
	 *
	 * @param array $args Extra query args.
	 */
	protected static function redirect_back( array $args = array() ) {
		$keep = array();

		foreach ( array( 'tab', 'filter', 'paged', 's', 'orderby', 'order' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification -- view state only; the action itself is nonce-checked.
			if ( isset( $_REQUEST[ $key ] ) && '' !== $_REQUEST[ $key ] ) {
				$keep[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		wp_safe_redirect( self::page_url( array_merge( $keep, $args ) ) );
		exit;
	}

	/**
	 * Route every form submission and row action for this screen.
	 */
	public static function handle_actions() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Single-rule GET actions (enable / disable / delete from the row links).
		if ( isset( $_GET['ttr_row_action'], $_GET['rule'] ) ) {
			$id = absint( $_GET['rule'] );
			check_admin_referer( 'ttr_row_' . $id );
			self::handle_row_action( sanitize_key( wp_unslash( $_GET['ttr_row_action'] ) ), $id );
			return;
		}

		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		// Inline row save: the button carries the rule ID.
		if ( ! empty( $_POST['ttr_update_id'] ) ) {
			self::handle_update( absint( $_POST['ttr_update_id'] ) );
			return;
		}

		$action = isset( $_POST['ttr_action'] ) ? sanitize_key( wp_unslash( $_POST['ttr_action'] ) ) : '';

		switch ( $action ) {
			case 'add_rule':
				self::handle_add();
				return;

			case 'save_settings':
				TTR_Settings::save( isset( $_POST['ttr_settings'] ) ? (array) wp_unslash( $_POST['ttr_settings'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in TTR_Settings::save().
				self::notice( __( 'Settings saved.', 'twentytwenty-redirect' ) );
				self::redirect_back( array( 'tab' => 'settings' ) );
				return;

			case 'toggle_switch':
				$on = ! empty( $_POST['ttr_enabled'] );
				TTR_Settings::set_enabled( $on );
				self::notice(
					$on
						? __( 'Rules are live. Listed URLs now return their configured response.', 'twentytwenty-redirect' )
						: __( 'Rules are paused. Every URL is served normally again.', 'twentytwenty-redirect' ),
					$on ? 'success' : 'warning'
				);
				self::redirect_back();
				return;

			case 'import':
				self::handle_import();
				return;

			case 'export':
				TTR_CSV::export(); // Exits.
				return;

			case 'reset_hits':
				$count = TTR_DB::reset_hits();
				/* translators: %s: number of rules. */
				self::notice( sprintf( __( 'Hit counters reset on %s rules.', 'twentytwenty-redirect' ), number_format_i18n( $count ) ) );
				self::redirect_back( array( 'tab' => 'tools' ) );
				return;

			case 'delete_all':
				$count = TTR_DB::truncate();
				/* translators: %s: number of rules. */
				self::notice( sprintf( __( 'Deleted all %s rules.', 'twentytwenty-redirect' ), number_format_i18n( $count ) ), 'warning' );
				self::redirect_back( array( 'tab' => 'tools' ) );
				return;
		}

		self::handle_bulk();
	}

	/**
	 * Enable, disable or delete one rule.
	 *
	 * @param string $action Row action.
	 * @param int    $id     Rule ID.
	 */
	protected static function handle_row_action( $action, $id ) {
		switch ( $action ) {
			case 'activate':
				TTR_DB::set_active( array( $id ), true );
				self::notice( __( 'Rule enabled.', 'twentytwenty-redirect' ) );
				break;

			case 'deactivate':
				TTR_DB::set_active( array( $id ), false );
				self::notice( __( 'Rule disabled.', 'twentytwenty-redirect' ) );
				break;

			case 'delete':
				TTR_DB::delete( array( $id ) );
				self::notice( __( 'Rule deleted.', 'twentytwenty-redirect' ) );
				break;
		}

		self::redirect_back();
	}

	/**
	 * Add a rule from the "Add a rule" form.
	 */
	protected static function handle_add() {
		$raw = isset( $_POST['ttr_new'] ) ? (array) wp_unslash( $_POST['ttr_new'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in TTR_DB::prepare_rule().

		$result = TTR_DB::insert(
			array(
				'source'      => isset( $raw['source'] ) ? $raw['source'] : '',
				'target'      => isset( $raw['target'] ) ? $raw['target'] : '',
				'status_code' => isset( $raw['status_code'] ) ? $raw['status_code'] : '',
				'notes'       => isset( $raw['notes'] ) ? $raw['notes'] : '',
				'match_type'  => isset( $raw['match_type'] ) ? $raw['match_type'] : 'exact',
				'is_active'   => 1,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message(), 'error' );
		} else {
			self::notice( __( 'Rule added.', 'twentytwenty-redirect' ) );
		}

		self::redirect_back();
	}

	/**
	 * Save one inline-edited row.
	 *
	 * @param int $id Rule ID.
	 */
	protected static function handle_update( $id ) {
		$all = isset( $_POST['ttr_edit'] ) ? (array) wp_unslash( $_POST['ttr_edit'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in TTR_DB::prepare_rule().
		$raw = isset( $all[ $id ] ) ? (array) $all[ $id ] : array();

		if ( empty( $raw ) ) {
			self::notice( __( 'That rule no longer exists.', 'twentytwenty-redirect' ), 'error' );
			self::redirect_back();
		}

		$result = TTR_DB::update(
			$id,
			array(
				'source'      => isset( $raw['source'] ) ? $raw['source'] : '',
				'target'      => isset( $raw['target'] ) ? $raw['target'] : '',
				'status_code' => isset( $raw['status_code'] ) ? $raw['status_code'] : '',
				'notes'       => isset( $raw['notes'] ) ? $raw['notes'] : '',
				'match_type'  => isset( $raw['match_type'] ) ? $raw['match_type'] : 'exact',
				'is_active'   => isset( $raw['is_active'] ) ? 1 : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message(), 'error' );
		} else {
			self::notice( __( 'Rule updated.', 'twentytwenty-redirect' ) );
		}

		self::redirect_back();
	}

	/**
	 * Apply a bulk action to the checked rules.
	 */
	protected static function handle_bulk() {
		$action = '';

		foreach ( array( 'action', 'action2' ) as $field ) {
			if ( isset( $_POST[ $field ] ) && '-1' !== $_POST[ $field ] ) {
				$action = sanitize_key( wp_unslash( $_POST[ $field ] ) );
				break;
			}
		}

		if ( '' === $action ) {
			return;
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			self::notice( __( 'No rules were selected.', 'twentytwenty-redirect' ), 'warning' );
			self::redirect_back();
		}

		switch ( $action ) {
			case 'activate':
				$count = TTR_DB::set_active( $ids, true );
				/* translators: %s: number of rules. */
				self::notice( sprintf( __( '%s rules enabled.', 'twentytwenty-redirect' ), number_format_i18n( $count ) ) );
				break;

			case 'deactivate':
				$count = TTR_DB::set_active( $ids, false );
				/* translators: %s: number of rules. */
				self::notice( sprintf( __( '%s rules disabled.', 'twentytwenty-redirect' ), number_format_i18n( $count ) ) );
				break;

			case 'set_410':
			case 'set_404':
			case 'set_451':
				$code  = (int) substr( $action, 4 );
				$count = TTR_DB::set_status( $ids, $code );
				self::notice(
					sprintf(
						/* translators: 1: number of rules, 2: status code. */
						__( '%1$s rules now return %2$d.', 'twentytwenty-redirect' ),
						number_format_i18n( $count ),
						$code
					)
				);
				break;

			case 'reset_hits':
				$count = TTR_DB::reset_hits( $ids );
				/* translators: %s: number of rules. */
				self::notice( sprintf( __( 'Hit counters reset on %s rules.', 'twentytwenty-redirect' ), number_format_i18n( $count ) ) );
				break;

			case 'delete':
				$count = TTR_DB::delete( $ids );
				/* translators: %s: number of rules. */
				self::notice( sprintf( __( '%s rules deleted.', 'twentytwenty-redirect' ), number_format_i18n( $count ) ) );
				break;

			default:
				return;
		}

		self::redirect_back();
	}

	/**
	 * Import an uploaded CSV or a pasted list.
	 */
	protected static function handle_import() {
		$options = array(
			'default_status' => TTR_Util::sanitize_status(
				isset( $_POST['ttr_import']['default_status'] ) ? sanitize_text_field( wp_unslash( $_POST['ttr_import']['default_status'] ) ) : '',
				410
			),
			'is_active'      => empty( $_POST['ttr_import']['is_active'] ) ? 0 : 1,
			'replace'        => ! empty( $_POST['ttr_import']['replace'] ),
		);

		$paste = isset( $_POST['ttr_import']['paste'] ) ? trim( (string) wp_unslash( $_POST['ttr_import']['paste'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed as CSV, each field sanitised downstream.
		$file  = isset( $_FILES['ttr_csv'] ) ? $_FILES['ttr_csv'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$has_upload = $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== $file['error'];

		if ( ! $has_upload && '' === $paste ) {
			self::notice( __( 'Choose a CSV file or paste a list of URLs first.', 'twentytwenty-redirect' ), 'error' );
			self::redirect_back( array( 'tab' => 'tools' ) );
		}

		if ( $has_upload ) {
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				self::notice( self::upload_error_message( (int) $file['error'] ), 'error' );
				self::redirect_back( array( 'tab' => 'tools' ) );
			}

			$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, array( 'csv', 'txt', 'tsv' ), true ) ) {
				self::notice( __( 'Only .csv, .tsv or .txt files can be imported.', 'twentytwenty-redirect' ), 'error' );
				self::redirect_back( array( 'tab' => 'tools' ) );
			}

			if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				self::notice( __( 'The upload could not be verified.', 'twentytwenty-redirect' ), 'error' );
				self::redirect_back( array( 'tab' => 'tools' ) );
			}

			// Parsed straight from the PHP temp file, so nothing lands in the uploads folder.
			$result = TTR_CSV::import_file( $file['tmp_name'], $options );
		} else {
			$result = TTR_CSV::import_text( $paste, $options );
		}

		$message = sprintf(
			/* translators: 1: imported count, 2: total rows read. */
			__( 'Imported %1$s of %2$s rows.', 'twentytwenty-redirect' ),
			number_format_i18n( $result['imported'] ),
			number_format_i18n( $result['total'] )
		);

		if ( $result['skipped'] ) {
			$message .= ' ' . sprintf(
				/* translators: %s: skipped row count. */
				__( '%s rows were skipped.', 'twentytwenty-redirect' ),
				number_format_i18n( $result['skipped'] )
			);
		}

		self::notice( $message, $result['imported'] ? 'success' : 'warning' );

		foreach ( $result['errors'] as $error ) {
			self::notice( $error, 'warning' );
		}

		self::redirect_back();
	}

	/**
	 * Human wording for a PHP upload error.
	 *
	 * @param int $code UPLOAD_ERR_* constant.
	 * @return string
	 */
	protected static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: server upload limit. */
					__( 'That file is larger than the %s upload limit on this server.', 'twentytwenty-redirect' ),
					size_format( wp_max_upload_size() )
				);

			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Try again.', 'twentytwenty-redirect' );

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the uploaded file to disk.', 'twentytwenty-redirect' );

			default:
				return __( 'The file could not be uploaded.', 'twentytwenty-redirect' );
		}
	}

	/**
	 * Match a URL against the rules without leaving the admin. Read-only.
	 *
	 * @param string $url URL to test.
	 * @return array{found:bool,message:string}
	 */
	protected static function test_url( $url ) {
		$normalized = TTR_Util::normalize( $url );

		if ( '' === $normalized ) {
			return array(
				'found'   => false,
				'message' => __( 'Enter a URL to test.', 'twentytwenty-redirect' ),
			);
		}

		$parts      = TTR_Util::split( $url );
		$candidates = array();

		if ( '' !== $parts['query'] ) {
			$candidates[] = TTR_Util::hash( $parts['path'] . '?' . $parts['query'] );
		}

		$candidates[] = TTR_Util::hash( $parts['path'] );

		$rule = TTR_DB::find_by_hashes( $candidates );

		if ( ! $rule ) {
			foreach ( TTR_DB::get_regex_rules() as $candidate ) {
				if ( TTR_Util::is_valid_regex( $candidate->source_path ) && preg_match( $candidate->source_path, $normalized ) ) {
					$rule = $candidate;
					break;
				}
			}
		}

		if ( ! $rule ) {
			return array(
				'found'   => false,
				'message' => sprintf(
					/* translators: %s: normalised URL. */
					__( 'No rule matches %s. It is served normally.', 'twentytwenty-redirect' ),
					'<code>' . esc_html( $normalized ) . '</code>'
				),
			);
		}

		if ( TTR_Util::needs_target( $rule->status_code ) ) {
			$message = sprintf(
				/* translators: 1: matched source, 2: status code, 3: destination. */
				__( '%1$s matches and returns %2$d to %3$s.', 'twentytwenty-redirect' ),
				'<code>' . esc_html( $rule->source_path ) . '</code>',
				(int) $rule->status_code,
				'<code>' . esc_html( TTR_Util::absolutize( $rule->target ) ) . '</code>'
			);
		} else {
			$message = sprintf(
				/* translators: 1: matched source, 2: status code. */
				__( '%1$s matches and returns %2$d.', 'twentytwenty-redirect' ),
				'<code>' . esc_html( $rule->source_path ) . '</code>',
				(int) $rule->status_code
			);
		}

		if ( ! TTR_Settings::get_key( 'enabled' ) ) {
			$message .= ' ' . __( '(The master switch is off, so nothing is being served right now.)', 'twentytwenty-redirect' );
		}

		return array(
			'found'   => true,
			'message' => $message,
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage redirects.', 'twentytwenty-redirect' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- tab selection only.
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'rules';
		$tab      = in_array( $tab, array( 'rules', 'tools', 'settings' ), true ) ? $tab : 'rules';
		$settings = TTR_Settings::get();
		$summary  = TTR_DB::summary();

		$tabs = array(
			'rules'    => __( 'Rules', 'twentytwenty-redirect' ),
			'tools'    => __( 'Import & export', 'twentytwenty-redirect' ),
			'settings' => __( 'Settings', 'twentytwenty-redirect' ),
		);
		?>
		<div class="wrap ttr-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( '20Twenty Redirect', 'twentytwenty-redirect' ); ?></h1>
			<p class="ttr-tagline"><?php esc_html_e( 'Retire dead URLs in bulk: serve them as 410 Gone, or point any one of them somewhere new.', 'twentytwenty-redirect' ); ?></p>

			<?php self::print_notices(); ?>
			<?php self::render_switch( $settings, $summary ); ?>

			<nav class="nav-tab-wrapper ttr-tabs">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::page_url( array( 'tab' => $key ) ) ); ?>"
						class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'tools' === $tab ) {
				self::render_tools_tab( $settings );
			} elseif ( 'settings' === $tab ) {
				self::render_settings_tab( $settings );
			} else {
				self::render_rules_tab( $settings );
			}
			?>
		</div>
		<?php
	}

	/**
	 * The master on/off switch and the headline counts.
	 *
	 * @param array $settings Settings.
	 * @param array $summary  Rule totals.
	 */
	protected static function render_switch( array $settings, array $summary ) {
		$on = ! empty( $settings['enabled'] );
		?>
		<div class="ttr-panel ttr-master <?php echo $on ? 'is-on' : 'is-off'; ?>">
			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" class="ttr-master-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="ttr_action" value="toggle_switch" />
				<input type="hidden" name="ttr_enabled" value="<?php echo $on ? '0' : '1'; ?>" />

				<label class="ttr-switch" for="ttr-master-switch">
					<input type="checkbox" id="ttr-master-switch" <?php checked( $on ); ?> />
					<span class="ttr-switch-track" aria-hidden="true"><span class="ttr-switch-thumb"></span></span>
				</label>

				<div class="ttr-master-copy">
					<strong>
						<?php
						echo $on
							? esc_html__( 'Rules are live', 'twentytwenty-redirect' )
							: esc_html__( 'Rules are paused', 'twentytwenty-redirect' );
						?>
					</strong>
					<span>
						<?php
						echo $on
							? esc_html__( 'Listed URLs return their configured response.', 'twentytwenty-redirect' )
							: esc_html__( 'Every URL is served normally. Your rules are kept.', 'twentytwenty-redirect' );
						?>
					</span>
				</div>

				<button type="submit" class="button">
					<?php
					echo $on
						? esc_html__( 'Turn off', 'twentytwenty-redirect' )
						: esc_html__( 'Turn on', 'twentytwenty-redirect' );
					?>
				</button>
			</form>

			<ul class="ttr-stats">
				<li><strong><?php echo esc_html( number_format_i18n( $summary['all'] ) ); ?></strong><span><?php esc_html_e( 'rules', 'twentytwenty-redirect' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( $summary['gone'] ) ); ?></strong><span><?php esc_html_e( '410 Gone', 'twentytwenty-redirect' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( $summary['redirect'] ) ); ?></strong><span><?php esc_html_e( 'redirects', 'twentytwenty-redirect' ); ?></span></li>
				<li><strong><?php echo esc_html( number_format_i18n( $summary['hits'] ) ); ?></strong><span><?php esc_html_e( 'hits', 'twentytwenty-redirect' ); ?></span></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Rules tab: add form plus the list table.
	 *
	 * @param array $settings Settings.
	 */
	protected static function render_rules_tab( array $settings ) {
		require_once TTR_DIR . 'includes/class-ttr-list-table.php';

		$table = new TTR_List_Table();
		$table->prepare_items();
		?>
		<div class="ttr-panel">
			<h2><?php esc_html_e( 'Add a rule', 'twentytwenty-redirect' ); ?></h2>
			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" class="ttr-add-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="ttr_action" value="add_rule" />

				<p class="ttr-field ttr-field-wide">
					<label for="ttr-new-source"><?php esc_html_e( 'Source URL', 'twentytwenty-redirect' ); ?></label>
					<input type="text" id="ttr-new-source" name="ttr_new[source]" class="regular-text code"
						placeholder="/retired-page" required />
					<span class="description"><?php esc_html_e( 'Paste a full URL or just the path. The domain is ignored.', 'twentytwenty-redirect' ); ?></span>
				</p>

				<p class="ttr-field">
					<label for="ttr-new-status"><?php esc_html_e( 'Response', 'twentytwenty-redirect' ); ?></label>
					<select id="ttr-new-status" name="ttr_new[status_code]" class="ttr-status-select">
						<?php foreach ( TTR_Util::statuses() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (int) $settings['default_status'], $code ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="ttr-field ttr-field-wide">
					<label for="ttr-new-target"><?php esc_html_e( 'Destination', 'twentytwenty-redirect' ); ?></label>
					<input type="text" id="ttr-new-target" name="ttr_new[target]" class="regular-text code"
						placeholder="<?php esc_attr_e( 'Only needed for redirects', 'twentytwenty-redirect' ); ?>" />
				</p>

				<p class="ttr-field ttr-field-wide">
					<label for="ttr-new-notes"><?php esc_html_e( 'Note', 'twentytwenty-redirect' ); ?></label>
					<input type="text" id="ttr-new-notes" name="ttr_new[notes]" class="regular-text"
						placeholder="<?php esc_attr_e( 'Optional, for your own reference', 'twentytwenty-redirect' ); ?>" />
				</p>

				<p class="ttr-field ttr-field-check">
					<label>
						<input type="checkbox" name="ttr_new[match_type]" value="regex" />
						<?php esc_html_e( 'Source is a regular expression', 'twentytwenty-redirect' ); ?>
					</label>
					<span class="description"><?php esc_html_e( 'Include delimiters, e.g. #^/old/(.+)$#. Use $1 in the destination.', 'twentytwenty-redirect' ); ?></span>
				</p>

				<p class="ttr-field ttr-field-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add rule', 'twentytwenty-redirect' ); ?></button>
				</p>
			</form>
		</div>

		<?php $table->views(); ?>

		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="ttr-search-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( TTR_SLUG ); ?>" />
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification -- view state only.
			if ( isset( $_GET['filter'] ) && '' !== $_GET['filter'] ) {
				printf(
					'<input type="hidden" name="filter" value="%s" />',
					esc_attr( sanitize_key( wp_unslash( $_GET['filter'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
				);
			}

			$table->search_box( __( 'Search rules', 'twentytwenty-redirect' ), 'ttr-search' );
			?>
		</form>

		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" class="ttr-rules-form">
			<?php
			wp_nonce_field( self::NONCE );

			// Carry the current view through the bulk submit so the redirect lands back here.
			foreach ( array( 'filter', 'paged', 'orderby', 'order', 's' ) as $key ) {
				// phpcs:ignore WordPress.Security.NonceVerification
				if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
					printf(
						'<input type="hidden" name="%s" value="%s" />',
						esc_attr( $key ),
						esc_attr( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
					);
				}
			}

			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * Import, export and the URL tester.
	 *
	 * @param array $settings Settings.
	 */
	protected static function render_tools_tab( array $settings ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only tester.
		$test_url    = isset( $_GET['ttr_test'] ) ? sanitize_text_field( wp_unslash( $_GET['ttr_test'] ) ) : '';
		$test_result = ( '' !== $test_url ) ? self::test_url( $test_url ) : null;
		?>
		<div class="ttr-panel">
			<h2><?php esc_html_e( 'Import a CSV', 'twentytwenty-redirect' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Columns: source, target, status, notes. A header row is detected automatically; a plain list of one URL per line works too. Rows whose source already exists are updated in place.', 'twentytwenty-redirect' ); ?>
			</p>

			<details class="ttr-sample">
				<summary><?php esc_html_e( 'Show an example file', 'twentytwenty-redirect' ); ?></summary>
				<pre><?php echo esc_html( TTR_CSV::sample() ); ?></pre>
			</details>

			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" enctype="multipart/form-data" class="ttr-import-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="ttr_action" value="import" />

				<p class="ttr-field ttr-field-wide">
					<label for="ttr-csv"><?php esc_html_e( 'CSV file', 'twentytwenty-redirect' ); ?></label>
					<input type="file" id="ttr-csv" name="ttr_csv" accept=".csv,.tsv,.txt,text/csv,text/plain" />
					<span class="description">
						<?php
						printf(
							/* translators: %s: maximum upload size. */
							esc_html__( 'Maximum upload size: %s.', 'twentytwenty-redirect' ),
							esc_html( size_format( wp_max_upload_size() ) )
						);
						?>
					</span>
				</p>

				<p class="ttr-field ttr-field-wide">
					<label for="ttr-paste"><?php esc_html_e( 'Or paste the list', 'twentytwenty-redirect' ); ?></label>
					<textarea id="ttr-paste" name="ttr_import[paste]" rows="6" class="large-text code"
						placeholder="/old-page-one&#10;/old-page-two&#10;/moved-page,/new-page,301"></textarea>
				</p>

				<p class="ttr-field">
					<label for="ttr-import-status"><?php esc_html_e( 'Response for rows without one', 'twentytwenty-redirect' ); ?></label>
					<select id="ttr-import-status" name="ttr_import[default_status]">
						<?php foreach ( TTR_Util::statuses() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (int) $settings['default_status'], $code ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="ttr-field ttr-field-check">
					<label>
						<input type="checkbox" name="ttr_import[is_active]" value="1" checked="checked" />
						<?php esc_html_e( 'Enable the imported rules straight away', 'twentytwenty-redirect' ); ?>
					</label>
				</p>

				<p class="ttr-field ttr-field-check">
					<label>
						<input type="checkbox" name="ttr_import[replace]" value="1" class="ttr-replace-toggle" />
						<?php esc_html_e( 'Delete all existing rules first', 'twentytwenty-redirect' ); ?>
					</label>
				</p>

				<p class="ttr-field ttr-field-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'twentytwenty-redirect' ); ?></button>
				</p>
			</form>
		</div>

		<div class="ttr-panel">
			<h2><?php esc_html_e( 'Test a URL', 'twentytwenty-redirect' ); ?></h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="ttr-test-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( TTR_SLUG ); ?>" />
				<input type="hidden" name="tab" value="tools" />
				<label class="screen-reader-text" for="ttr-test"><?php esc_html_e( 'URL to test', 'twentytwenty-redirect' ); ?></label>
				<input type="text" id="ttr-test" name="ttr_test" class="regular-text code"
					value="<?php echo esc_attr( $test_url ); ?>" placeholder="/some-old-page" />
				<button type="submit" class="button"><?php esc_html_e( 'Check', 'twentytwenty-redirect' ); ?></button>
			</form>

			<?php if ( $test_result ) : ?>
				<p class="ttr-test-result <?php echo $test_result['found'] ? 'is-match' : 'is-miss'; ?>">
					<?php echo wp_kses( $test_result['message'], array( 'code' => array() ) ); ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="ttr-panel">
			<h2><?php esc_html_e( 'Export and reset', 'twentytwenty-redirect' ); ?></h2>
			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" class="ttr-tools-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p>
					<button type="submit" class="button" name="ttr_action" value="export">
						<?php esc_html_e( 'Download all rules as CSV', 'twentytwenty-redirect' ); ?>
					</button>
					<button type="submit" class="button" name="ttr_action" value="reset_hits">
						<?php esc_html_e( 'Reset every hit counter', 'twentytwenty-redirect' ); ?>
					</button>
					<button type="submit" class="button ttr-danger ttr-confirm-delete-all" name="ttr_action" value="delete_all">
						<?php esc_html_e( 'Delete all rules', 'twentytwenty-redirect' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Settings tab.
	 *
	 * @param array $settings Settings.
	 */
	protected static function render_settings_tab( array $settings ) {
		?>
		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" class="ttr-panel">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="ttr_action" value="save_settings" />
			<input type="hidden" name="ttr_settings[enabled]" value="<?php echo esc_attr( $settings['enabled'] ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'When to check', 'twentytwenty-redirect' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="ttr_settings[run_on]" value="init" <?php checked( $settings['run_on'], 'init' ); ?> />
								<?php esc_html_e( 'Every request', 'twentytwenty-redirect' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'One indexed lookup per page load. Rules win even when a page with the same URL still exists.', 'twentytwenty-redirect' ); ?></p>
							<br />
							<label>
								<input type="radio" name="ttr_settings[run_on]" value="404" <?php checked( $settings['run_on'], '404' ); ?> />
								<?php esc_html_e( 'Only when WordPress would show a 404', 'twentytwenty-redirect' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Lighter, but a rule for a URL that still resolves to real content is ignored.', 'twentytwenty-redirect' ); ?></p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Matching', 'twentytwenty-redirect' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="ttr_settings[ignore_trailing_slash]" value="1" <?php checked( $settings['ignore_trailing_slash'], 1 ); ?> />
								<?php esc_html_e( 'Treat /page and /page/ as the same URL', 'twentytwenty-redirect' ); ?>
							</label>
							<br />
							<label>
								<input type="checkbox" name="ttr_settings[case_insensitive]" value="1" <?php checked( $settings['case_insensitive'], 1 ); ?> />
								<?php esc_html_e( 'Ignore letter case', 'twentytwenty-redirect' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Changing either option rebuilds the lookup keys of every stored rule.', 'twentytwenty-redirect' ); ?></p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Hit tracking', 'twentytwenty-redirect' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ttr_settings[log_hits]" value="1" <?php checked( $settings['log_hits'], 1 ); ?> />
							<?php esc_html_e( 'Count how often each rule fires', 'twentytwenty-redirect' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Adds one small write per matched request. Turn it off on very high-traffic sites.', 'twentytwenty-redirect' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="ttr-default-status"><?php esc_html_e( 'Default response', 'twentytwenty-redirect' ); ?></label>
					</th>
					<td>
						<select id="ttr-default-status" name="ttr_settings[default_status]">
							<?php foreach ( TTR_Util::statuses() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (int) $settings['default_status'], $code ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Used for imported rows and new rules that do not name one.', 'twentytwenty-redirect' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="ttr-gone-title"><?php esc_html_e( 'Gone page heading', 'twentytwenty-redirect' ); ?></label>
					</th>
					<td>
						<input type="text" id="ttr-gone-title" name="ttr_settings[gone_title]" class="regular-text"
							value="<?php echo esc_attr( $settings['gone_title'] ); ?>" />
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="ttr-gone-message"><?php esc_html_e( 'Gone page message', 'twentytwenty-redirect' ); ?></label>
					</th>
					<td>
						<textarea id="ttr-gone-message" name="ttr_settings[gone_message]" rows="3" class="large-text"><?php echo esc_textarea( $settings['gone_message'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Shown on 410, 403, 451 and 404 responses. A theme can take over completely by adding a 410.php template.', 'twentytwenty-redirect' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}
}
