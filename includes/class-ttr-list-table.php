<?php
/**
 * The rules table on the settings screen.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class TTR_List_Table extends WP_List_Table {

	/**
	 * Rule totals used by the view links.
	 *
	 * @var array
	 */
	protected $summary = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'rule',
				'plural'   => 'rules',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'source_path' => __( 'Source URL', 'twentytwenty-redirect' ),
			'status_code' => __( 'Response', 'twentytwenty-redirect' ),
			'target'      => __( 'Destination', 'twentytwenty-redirect' ),
			'hits'        => __( 'Hits', 'twentytwenty-redirect' ),
			'last_hit'    => __( 'Last hit', 'twentytwenty-redirect' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'source_path' => array( 'source_path', false ),
			'status_code' => array( 'status_code', false ),
			'hits'        => array( 'hits', true ),
			'last_hit'    => array( 'last_hit', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'activate'   => __( 'Enable', 'twentytwenty-redirect' ),
			'deactivate' => __( 'Disable', 'twentytwenty-redirect' ),
			'set_410'    => __( 'Change to 410 Gone', 'twentytwenty-redirect' ),
			'set_404'    => __( 'Change to 404 Not Found', 'twentytwenty-redirect' ),
			'set_451'    => __( 'Change to 451 Legal', 'twentytwenty-redirect' ),
			'reset_hits' => __( 'Reset hit counters', 'twentytwenty-redirect' ),
			'delete'     => __( 'Delete', 'twentytwenty-redirect' ),
		);
	}

	/**
	 * Filter links above the table.
	 *
	 * @return array
	 */
	protected function get_views() {
		$base    = TTR_Admin::page_url();
		$current = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification

		$views = array(
			'all'      => array( __( 'All', 'twentytwenty-redirect' ), $this->summary['all'] ),
			'active'   => array( __( 'Enabled', 'twentytwenty-redirect' ), $this->summary['active'] ),
			'inactive' => array( __( 'Disabled', 'twentytwenty-redirect' ), $this->summary['inactive'] ),
			'gone'     => array( __( '410 Gone', 'twentytwenty-redirect' ), $this->summary['gone'] ),
			'redirect' => array( __( 'Redirects', 'twentytwenty-redirect' ), $this->summary['redirect'] ),
		);

		$out = array();

		foreach ( $views as $key => $view ) {
			$url = ( 'all' === $key ) ? $base : add_query_arg( 'filter', $key, $base );

			$out[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $view[0] ),
				esc_html( number_format_i18n( $view[1] ) )
			);
		}

		return $out;
	}

	/**
	 * Load rules for the current page.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'ttr_rules_per_page', 25 );
		$paged    = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification -- read-only listing state.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$filter  = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable

		$args = array(
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'page'     => $paged,
		);

		switch ( $filter ) {
			case 'active':
				$args['is_active'] = 1;
				break;
			case 'inactive':
				$args['is_active'] = 0;
				break;
			case 'gone':
				$args['status'] = 410;
				break;
			case 'redirect':
				$args['status'] = '';
				break;
		}

		$this->summary = TTR_DB::summary();

		if ( 'redirect' === $filter ) {
			// Redirect codes are a set, so filter after the fact.
			$args['per_page'] = 10000;
			$args['page']     = 1;
			$all              = TTR_DB::query( $args );
			$all              = array_values(
				array_filter(
					$all,
					static function ( $rule ) {
						return TTR_Util::needs_target( $rule->status_code );
					}
				)
			);

			$total       = count( $all );
			$this->items = array_slice( $all, ( $paged - 1 ) * $per_page, $per_page );
		} else {
			$this->items = TTR_DB::query( $args );
			$total       = TTR_DB::count( $args );
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'source_path' );
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		esc_html_e( 'No rules yet. Add one below, or import a CSV.', 'twentytwenty-redirect' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Rule.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Source column, with the row actions.
	 *
	 * @param object $item Rule.
	 * @return string
	 */
	protected function column_source_path( $item ) {
		$is_regex = ( 'regex' === $item->match_type );
		$label    = esc_html( $item->source_path );

		if ( ! $is_regex ) {
			$label = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
				esc_url( TTR_Util::absolutize( $item->source_path ) ),
				esc_attr__( 'Open this URL in a new tab', 'twentytwenty-redirect' ),
				esc_html( $item->source_path )
			);
		}

		$badges = '';

		if ( $is_regex ) {
			$badges .= ' <span class="ttr-badge ttr-badge-regex">' . esc_html__( 'regex', 'twentytwenty-redirect' ) . '</span>';
		}

		if ( ! $item->is_active ) {
			$badges .= ' <span class="ttr-badge ttr-badge-off">' . esc_html__( 'disabled', 'twentytwenty-redirect' ) . '</span>';
		}

		$notes = '';

		if ( '' !== $item->notes ) {
			$notes = '<div class="ttr-notes">' . esc_html( $item->notes ) . '</div>';
		}

		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'ttr_row_action' => $item->is_active ? 'deactivate' : 'activate',
					'rule'           => (int) $item->id,
				),
				TTR_Admin::page_url()
			),
			'ttr_row_' . $item->id
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'ttr_row_action' => 'delete',
					'rule'           => (int) $item->id,
				),
				TTR_Admin::page_url()
			),
			'ttr_row_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf(
				'<a href="#" class="ttr-edit-toggle" data-rule="%d" aria-expanded="false">%s</a>',
				(int) $item->id,
				esc_html__( 'Edit', 'twentytwenty-redirect' )
			),
			'toggle' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $toggle_url ),
				$item->is_active ? esc_html__( 'Disable', 'twentytwenty-redirect' ) : esc_html__( 'Enable', 'twentytwenty-redirect' )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete ttr-confirm-delete">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'twentytwenty-redirect' )
			),
		);

		return '<strong>' . $label . '</strong>' . $badges . $notes . $this->row_actions( $actions );
	}

	/**
	 * Response column.
	 *
	 * @param object $item Rule.
	 * @return string
	 */
	protected function column_status_code( $item ) {
		$code  = (int) $item->status_code;
		$class = TTR_Util::needs_target( $code ) ? 'ttr-code-redirect' : 'ttr-code-gone';

		return sprintf(
			'<span class="ttr-code %s">%d</span>',
			esc_attr( $class ),
			$code
		);
	}

	/**
	 * Destination column.
	 *
	 * @param object $item Rule.
	 * @return string
	 */
	protected function column_target( $item ) {
		if ( ! TTR_Util::needs_target( $item->status_code ) ) {
			return '<span class="ttr-muted">' . esc_html__( '— nothing served —', 'twentytwenty-redirect' ) . '</span>';
		}

		if ( '' === $item->target ) {
			return '<span class="ttr-warn">' . esc_html__( 'missing destination', 'twentytwenty-redirect' ) . '</span>';
		}

		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( TTR_Util::absolutize( $item->target ) ),
			esc_html( $item->target )
		);
	}

	/**
	 * Hits column.
	 *
	 * @param object $item Rule.
	 * @return string
	 */
	protected function column_hits( $item ) {
		return esc_html( number_format_i18n( (int) $item->hits ) );
	}

	/**
	 * Last hit column.
	 *
	 * @param object $item Rule.
	 * @return string
	 */
	protected function column_last_hit( $item ) {
		if ( empty( $item->last_hit ) || '0000-00-00 00:00:00' === $item->last_hit ) {
			return '<span class="ttr-muted">' . esc_html__( 'never', 'twentytwenty-redirect' ) . '</span>';
		}

		$timestamp = mysql2date( 'U', $item->last_hit, false );

		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->last_hit ) ),
			sprintf(
				/* translators: %s: human readable time difference. */
				esc_html__( '%s ago', 'twentytwenty-redirect' ),
				esc_html( human_time_diff( $timestamp, current_time( 'timestamp' ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			)
		);
	}

	/**
	 * Fallback column renderer.
	 *
	 * @param object $item        Rule.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Render the row plus its hidden inline edit form.
	 *
	 * The form fields live inside the surrounding bulk-action form, so saving a
	 * row is a normal submit rather than a nested form.
	 *
	 * @param object $item Rule.
	 */
	public function single_row( $item ) {
		echo '<tr id="ttr-row-' . (int) $item->id . '" class="' . ( $item->is_active ? '' : 'ttr-row-off' ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';

		$this->edit_row( $item );
	}

	/**
	 * The inline edit row.
	 *
	 * @param object $item Rule.
	 */
	protected function edit_row( $item ) {
		$id      = (int) $item->id;
		$colspan = count( $this->get_columns() );
		$name    = 'ttr_edit[' . $id . ']';
		?>
		<tr class="ttr-edit-row" id="ttr-edit-<?php echo esc_attr( $id ); ?>" hidden>
			<td colspan="<?php echo esc_attr( $colspan ); ?>">
				<div class="ttr-edit-grid">
					<p class="ttr-field ttr-field-wide">
						<label for="ttr-source-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Source URL', 'twentytwenty-redirect' ); ?></label>
						<input type="text" id="ttr-source-<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( $name ); ?>[source]"
							value="<?php echo esc_attr( $item->source_path ); ?>" class="regular-text code" />
					</p>
					<p class="ttr-field">
						<label for="ttr-status-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Response', 'twentytwenty-redirect' ); ?></label>
						<select id="ttr-status-<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[status_code]" class="ttr-status-select">
							<?php foreach ( TTR_Util::statuses() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (int) $item->status_code, $code ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="ttr-field ttr-field-wide">
						<label for="ttr-target-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Destination', 'twentytwenty-redirect' ); ?></label>
						<input type="text" id="ttr-target-<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( $name ); ?>[target]"
							value="<?php echo esc_attr( $item->target ); ?>" class="regular-text code"
							placeholder="<?php esc_attr_e( '/new-page or https://example.com/new-page', 'twentytwenty-redirect' ); ?>" />
					</p>
					<p class="ttr-field ttr-field-wide">
						<label for="ttr-notes-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Note', 'twentytwenty-redirect' ); ?></label>
						<input type="text" id="ttr-notes-<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( $name ); ?>[notes]"
							value="<?php echo esc_attr( $item->notes ); ?>" class="regular-text" />
					</p>
					<p class="ttr-field ttr-field-check">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[is_active]" value="1" <?php checked( (int) $item->is_active, 1 ); ?> />
							<?php esc_html_e( 'Enabled', 'twentytwenty-redirect' ); ?>
						</label>
					</p>
					<p class="ttr-field ttr-field-actions">
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[match_type]" value="<?php echo esc_attr( $item->match_type ); ?>" />
						<button type="submit" class="button button-primary" name="ttr_update_id" value="<?php echo esc_attr( $id ); ?>">
							<?php esc_html_e( 'Save rule', 'twentytwenty-redirect' ); ?>
						</button>
						<button type="button" class="button ttr-edit-cancel" data-rule="<?php echo esc_attr( $id ); ?>">
							<?php esc_html_e( 'Cancel', 'twentytwenty-redirect' ); ?>
						</button>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}
}
