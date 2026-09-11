<?php
/**
 * Rule storage.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTR_DB {

	const DB_VERSION_OPTION = 'ttr_db_version';
	const DB_VERSION        = '1.0.0';
	const REGEX_CACHE_KEY   = 'ttr_regex_rules';

	/**
	 * Fully qualified table name for the current site.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'ttr_rules';
	}

	/**
	 * Create or update the schema.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// source_hash is indexed unique: it is the lookup key on every request.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_path varchar(500) NOT NULL DEFAULT '',
			source_hash char(32) NOT NULL DEFAULT '',
			target varchar(500) NOT NULL DEFAULT '',
			status_code smallint(5) unsigned NOT NULL DEFAULT 410,
			match_type varchar(20) NOT NULL DEFAULT 'exact',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			notes varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash),
			KEY match_active (match_type,is_active),
			KEY status_code (status_code)
		) {$charset};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Run install() when the stored schema version is behind the code.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Drop the regex rule cache.
	 */
	public static function flush_cache() {
		delete_transient( self::REGEX_CACHE_KEY );
	}

	/**
	 * Look up an exact-match rule by one or more candidate hashes.
	 *
	 * Candidates are tried in the order given, so a rule carrying a query string
	 * wins over the bare-path rule for the same request.
	 *
	 * @param string[] $hashes Candidate hashes, most specific first.
	 * @return object|null
	 */
	public static function find_by_hashes( array $hashes ) {
		global $wpdb;

		$hashes = array_values( array_unique( array_filter( $hashes ) ) );

		if ( empty( $hashes ) ) {
			return null;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$order        = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE match_type = 'exact' AND is_active = 1 AND source_hash IN ({$placeholders})
			 ORDER BY FIELD(source_hash, {$order})
			 LIMIT 1",
			array_merge( $hashes, $hashes )
		);
		// phpcs:enable

		return $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * All active regex rules, cached for an hour.
	 *
	 * @return array
	 */
	public static function get_regex_rules() {
		$cached = get_transient( self::REGEX_CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE match_type = 'regex' AND is_active = 1 ORDER BY id ASC" ); // phpcs:ignore
		$rows  = is_array( $rows ) ? $rows : array();

		set_transient( self::REGEX_CACHE_KEY, $rows, HOUR_IN_SECONDS );

		return $rows;
	}

	/**
	 * Fetch one rule.
	 *
	 * @param int $id Rule ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Build the shared WHERE clause for listing queries.
	 *
	 * @param array $args Query args.
	 * @return array{sql:string,params:array}
	 */
	protected static function build_where( array $args ) {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(source_path LIKE %s OR target LIKE %s OR notes LIKE %s)';
			$like     = '%' . $GLOBALS['wpdb']->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( isset( $args['status'] ) && '' !== $args['status'] ) {
			$where[]  = 'status_code = %d';
			$params[] = (int) $args['status'];
		}

		if ( isset( $args['is_active'] ) && '' !== $args['is_active'] ) {
			$where[]  = 'is_active = %d';
			$params[] = (int) $args['is_active'];
		}

		return array(
			'sql'    => implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * Query rules for the list table.
	 *
	 * @param array $args search, status, is_active, orderby, order, per_page, page.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'status'   => '',
				'is_active' => '',
				'orderby'  => 'id',
				'order'    => 'DESC',
				'per_page' => 25,
				'page'     => 1,
			)
		);

		$allowed_orderby = array( 'id', 'source_path', 'status_code', 'target', 'hits', 'last_hit', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$where  = self::build_where( $args );
		$table  = self::table();
		$params = array_merge( $where['params'], array( $per_page, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where['sql']} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$sql = $wpdb->prepare( $sql, $params );
		// phpcs:enable

		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	/**
	 * Count rules matching the same filters as query().
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;

		$where = self::build_where( $args );
		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}";

		if ( ! empty( $where['params'] ) ) {
			$sql = $wpdb->prepare( $sql, $where['params'] );
		}
		// phpcs:enable

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/**
	 * Totals for the status filter links.
	 *
	 * @return array{all:int,active:int,inactive:int,gone:int,redirect:int}
	 */
	public static function summary() {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( // phpcs:ignore
			"SELECT
				COUNT(*) AS all_rules,
				SUM(is_active = 1) AS active,
				SUM(is_active = 0) AS inactive,
				SUM(status_code = 410) AS gone,
				SUM(status_code IN (301,302,307,308)) AS redirect,
				SUM(hits) AS hits
			 FROM {$table}",
			ARRAY_A
		);

		return array(
			'all'      => isset( $row['all_rules'] ) ? (int) $row['all_rules'] : 0,
			'active'   => isset( $row['active'] ) ? (int) $row['active'] : 0,
			'inactive' => isset( $row['inactive'] ) ? (int) $row['inactive'] : 0,
			'gone'     => isset( $row['gone'] ) ? (int) $row['gone'] : 0,
			'redirect' => isset( $row['redirect'] ) ? (int) $row['redirect'] : 0,
			'hits'     => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
		);
	}

	/**
	 * Normalise and validate a rule payload.
	 *
	 * @param array $data Raw rule fields.
	 * @return array|WP_Error
	 */
	public static function prepare_rule( array $data ) {
		$match_type = ( isset( $data['match_type'] ) && 'regex' === $data['match_type'] ) ? 'regex' : 'exact';
		$source     = isset( $data['source'] ) ? trim( wp_unslash( (string) $data['source'] ) ) : '';
		$status     = TTR_Util::sanitize_status( isset( $data['status_code'] ) ? $data['status_code'] : '', (int) TTR_Settings::get_key( 'default_status', 410 ) );
		$target     = isset( $data['target'] ) ? trim( wp_unslash( (string) $data['target'] ) ) : '';
		$notes      = isset( $data['notes'] ) ? sanitize_text_field( wp_unslash( (string) $data['notes'] ) ) : '';
		$active     = empty( $data['is_active'] ) ? 0 : 1;

		if ( '' === $source ) {
			return new WP_Error( 'ttr_empty_source', __( 'The source URL cannot be empty.', 'twentytwenty-redirect' ) );
		}

		if ( 'regex' === $match_type ) {
			if ( ! TTR_Util::is_valid_regex( $source ) ) {
				return new WP_Error(
					'ttr_bad_regex',
					/* translators: %s: the pattern that failed to compile. */
					sprintf( __( '"%s" is not a valid regular expression. Include delimiters, e.g. #^/old/(.+)$#', 'twentytwenty-redirect' ), $source )
				);
			}
			$normalized = $source;
		} else {
			$normalized = TTR_Util::normalize( $source );

			if ( '' === $normalized ) {
				return new WP_Error( 'ttr_empty_source', __( 'The source URL cannot be empty.', 'twentytwenty-redirect' ) );
			}
		}

		if ( TTR_Util::needs_target( $status ) ) {
			if ( '' === $target ) {
				return new WP_Error( 'ttr_empty_target', __( 'A redirect needs a destination URL.', 'twentytwenty-redirect' ) );
			}

			if ( 'exact' === $match_type && TTR_Util::normalize( $target ) === $normalized ) {
				return new WP_Error( 'ttr_loop', __( 'That rule would redirect a URL to itself.', 'twentytwenty-redirect' ) );
			}
		} else {
			// Non-redirect codes ignore the target; keep it for when the editor flips back.
			$target = ( '' === $target ) ? '' : $target;
		}

		$now = current_time( 'mysql' );

		return array(
			'source_path' => mb_substr( $normalized, 0, 500 ),
			'source_hash' => TTR_Util::hash( $normalized ),
			'target'      => mb_substr( $target, 0, 500 ),
			'status_code' => $status,
			'match_type'  => $match_type,
			'is_active'   => $active,
			'notes'       => $notes,
			'updated_at'  => $now,
		);
	}

	/**
	 * Insert a rule.
	 *
	 * @param array $data Raw rule fields.
	 * @return int|WP_Error Inserted ID.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$row = self::prepare_rule( $data );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE source_hash = %s', $row['source_hash'] ) ); // phpcs:ignore

		if ( $existing ) {
			return new WP_Error(
				'ttr_duplicate',
				/* translators: %s: source URL. */
				sprintf( __( 'A rule for "%s" already exists.', 'twentytwenty-redirect' ), $row['source_path'] ),
				array( 'id' => (int) $existing )
			);
		}

		$row['created_at'] = $row['updated_at'];

		$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore

		if ( false === $ok ) {
			return new WP_Error( 'ttr_insert_failed', __( 'The rule could not be saved.', 'twentytwenty-redirect' ) );
		}

		self::flush_cache();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a rule.
	 *
	 * @param int   $id   Rule ID.
	 * @param array $data Raw rule fields.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$id  = (int) $id;
		$row = self::prepare_rule( $data );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$clash = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE source_hash = %s AND id <> %d', $row['source_hash'], $id ) ); // phpcs:ignore

		if ( $clash ) {
			return new WP_Error(
				'ttr_duplicate',
				/* translators: %s: source URL. */
				sprintf( __( 'Another rule already covers "%s".', 'twentytwenty-redirect' ), $row['source_path'] )
			);
		}

		$ok = $wpdb->update( self::table(), $row, array( 'id' => $id ) ); // phpcs:ignore

		if ( false === $ok ) {
			return new WP_Error( 'ttr_update_failed', __( 'The rule could not be updated.', 'twentytwenty-redirect' ) );
		}

		self::flush_cache();

		return true;
	}

	/**
	 * Delete rules.
	 *
	 * @param int[] $ids Rule IDs.
	 * @return int Rows removed.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$in    = implode( ',', $ids );
		$table = self::table();
		$count = (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore

		self::flush_cache();

		return $count;
	}

	/**
	 * Flip rules on or off.
	 *
	 * @param int[] $ids    Rule IDs.
	 * @param bool  $active Target state.
	 * @return int Rows touched.
	 */
	public static function set_active( array $ids, $active ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$in    = implode( ',', $ids );
		$table = self::table();
		$count = (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare( "UPDATE {$table} SET is_active = %d, updated_at = %s WHERE id IN ({$in})", $active ? 1 : 0, current_time( 'mysql' ) )
		);

		self::flush_cache();

		return $count;
	}

	/**
	 * Apply one status code to many rules. Redirect codes are refused here
	 * because each rule would need its own destination.
	 *
	 * @param int[] $ids  Rule IDs.
	 * @param int   $code Status code.
	 * @return int Rows touched.
	 */
	public static function set_status( array $ids, $code ) {
		global $wpdb;

		$ids  = array_filter( array_map( 'absint', $ids ) );
		$code = TTR_Util::sanitize_status( $code, 410 );

		if ( empty( $ids ) || TTR_Util::needs_target( $code ) ) {
			return 0;
		}

		$in    = implode( ',', $ids );
		$table = self::table();
		$count = (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare( "UPDATE {$table} SET status_code = %d, updated_at = %s WHERE id IN ({$in})", $code, current_time( 'mysql' ) )
		);

		self::flush_cache();

		return $count;
	}

	/**
	 * Reset hit counters.
	 *
	 * @param int[] $ids Rule IDs, or empty for all rules.
	 * @return int Rows touched.
	 */
	public static function reset_hits( array $ids = array() ) {
		global $wpdb;

		$table = self::table();
		$ids   = array_filter( array_map( 'absint', $ids ) );
		$sql   = "UPDATE {$table} SET hits = 0, last_hit = NULL";

		if ( ! empty( $ids ) ) {
			$sql .= ' WHERE id IN (' . implode( ',', $ids ) . ')';
		}

		return (int) $wpdb->query( $sql ); // phpcs:ignore
	}

	/**
	 * Record a match. Cheap single-statement update, no read first.
	 *
	 * @param int $id Rule ID.
	 */
	public static function record_hit( $id ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query( // phpcs:ignore
			$wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql' ), (int) $id )
		);
	}

	/**
	 * Insert or overwrite many rules at once. Used by the CSV importer.
	 *
	 * @param array $rows Rows already run through prepare_rule().
	 * @return int Rows written.
	 */
	public static function bulk_upsert( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$table   = self::table();
		$written = 0;

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s,%s,%s,%d,%s,%d,%s,%s,%s)';

				array_push(
					$values,
					$row['source_path'],
					$row['source_hash'],
					$row['target'],
					$row['status_code'],
					$row['match_type'],
					$row['is_active'],
					$row['notes'],
					$row['updated_at'],
					$row['updated_at']
				);
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "INSERT INTO {$table}
				(source_path, source_hash, target, status_code, match_type, is_active, notes, created_at, updated_at)
				VALUES " . implode( ',', $placeholders ) . '
				ON DUPLICATE KEY UPDATE
					source_path = VALUES(source_path),
					target      = VALUES(target),
					status_code = VALUES(status_code),
					match_type  = VALUES(match_type),
					is_active   = VALUES(is_active),
					notes       = VALUES(notes),
					updated_at  = VALUES(updated_at)';

			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore
			// phpcs:enable

			if ( false !== $result ) {
				// MySQL reports 1 for an insert and 2 for an update; count rows, not statements.
				$written += min( (int) $result, count( $chunk ) );
			}
		}

		self::flush_cache();

		return $written;
	}

	/**
	 * Delete every rule.
	 *
	 * @return int Rows removed.
	 */
	public static function truncate() {
		global $wpdb;

		$table = self::table();
		$count = self::count();

		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
		self::flush_cache();

		return $count;
	}

	/**
	 * Recompute every exact-match hash. Needed when the normalisation settings
	 * (trailing slash, case) change, or stored rules would stop matching.
	 *
	 * Collisions after normalisation are dropped, keeping the oldest rule.
	 */
	public static function rehash_all() {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT id, source_path FROM {$table} WHERE match_type = 'exact' ORDER BY id ASC" ); // phpcs:ignore

		if ( empty( $rows ) ) {
			return;
		}

		$seen = array();

		foreach ( $rows as $row ) {
			$normalized = TTR_Util::normalize( $row->source_path );

			if ( '' === $normalized ) {
				continue;
			}

			$hash = TTR_Util::hash( $normalized );

			if ( isset( $seen[ $hash ] ) ) {
				$wpdb->delete( $table, array( 'id' => (int) $row->id ) ); // phpcs:ignore
				continue;
			}

			$seen[ $hash ] = true;

			$wpdb->update( // phpcs:ignore
				$table,
				array(
					'source_path' => $normalized,
					'source_hash' => $hash,
				),
				array( 'id' => (int) $row->id )
			);
		}

		self::flush_cache();
	}

	/**
	 * Stream every rule for the CSV export.
	 *
	 * @param int $batch Rows per query.
	 * @return Generator
	 */
	public static function each( $batch = 500 ) {
		global $wpdb;

		$table  = self::table();
		$batch  = max( 1, (int) $batch );
		$offset = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", $batch, $offset ) ); // phpcs:ignore

			foreach ( (array) $rows as $row ) {
				yield $row;
			}

			$offset += $batch;
		} while ( ! empty( $rows ) && count( $rows ) === $batch );
	}
}
