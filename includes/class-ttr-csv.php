<?php
/**
 * CSV import and export.
 *
 * @package TwentyTwenty_Redirect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TTR_CSV {

	const MAX_ROWS = 100000;

	/**
	 * Column header aliases, mapped to the field they fill.
	 *
	 * @return array<string,string>
	 */
	protected static function header_map() {
		return array(
			'source'      => 'source',
			'source_url'  => 'source',
			'url'         => 'source',
			'from'        => 'source',
			'old'         => 'source',
			'old_url'     => 'source',
			'link'        => 'source',
			'path'        => 'source',
			'request'     => 'source',
			'target'      => 'target',
			'target_url'  => 'target',
			'to'          => 'target',
			'new'         => 'target',
			'new_url'     => 'target',
			'destination' => 'target',
			'redirect'    => 'target',
			'redirect_to' => 'target',
			'status'      => 'status',
			'status_code' => 'status',
			'code'        => 'status',
			'type'        => 'status',
			'action'      => 'status',
			'notes'       => 'notes',
			'note'        => 'notes',
			'comment'     => 'notes',
			'label'       => 'notes',
		);
	}

	/**
	 * Guess the delimiter from the header line.
	 *
	 * @param string $line First line of the file.
	 * @return string
	 */
	protected static function sniff_delimiter( $line ) {
		$counts = array(
			','  => substr_count( $line, ',' ),
			';'  => substr_count( $line, ';' ),
			"\t" => substr_count( $line, "\t" ),
			'|'  => substr_count( $line, '|' ),
		);

		arsort( $counts );
		$best = key( $counts );

		return $counts[ $best ] > 0 ? $best : ',';
	}

	/**
	 * Read a header row and work out which column holds what.
	 *
	 * Returns null when the first row is data rather than a header.
	 *
	 * @param array $row First row of the file.
	 * @return array<string,int>|null
	 */
	protected static function map_columns( array $row ) {
		$map     = self::header_map();
		$columns = array();

		foreach ( $row as $index => $cell ) {
			$key = strtolower( trim( preg_replace( '/[^a-z0-9_ ]/i', '', (string) $cell ) ) );
			$key = str_replace( ' ', '_', $key );

			if ( isset( $map[ $key ] ) && ! isset( $columns[ $map[ $key ] ] ) ) {
				$columns[ $map[ $key ] ] = $index;
			}
		}

		// A header must at least name the source column, otherwise treat row one as data.
		return isset( $columns['source'] ) ? $columns : null;
	}

	/**
	 * Import rules from an uploaded CSV file.
	 *
	 * @param string $path    Path to a readable CSV file.
	 * @param array  $options default_status, is_active, replace.
	 * @return array{imported:int,skipped:int,errors:string[],total:int}
	 */
	public static function import_file( $path, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'default_status' => (int) TTR_Settings::get_key( 'default_status', 410 ),
				'is_active'      => 1,
				'replace'        => false,
			)
		);

		$result = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'total'    => 0,
		);

		if ( ! is_readable( $path ) ) {
			$result['errors'][] = __( 'The uploaded file could not be read.', 'twentytwenty-redirect' );
			return $result;
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			$result['errors'][] = __( 'The uploaded file could not be opened.', 'twentytwenty-redirect' );
			return $result;
		}

		$first = fgets( $handle );

		if ( false === $first ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$result['errors'][] = __( 'The file is empty.', 'twentytwenty-redirect' );
			return $result;
		}

		// Strip a UTF-8 BOM so the first header cell still matches.
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
		$delim = self::sniff_delimiter( $first );

		rewind( $handle );

		if ( $options['replace'] ) {
			TTR_DB::truncate();
		}

		$columns = null;
		$batch   = array();
		$line_no = 0;
		$seen    = array();
		$index   = 0;

		while ( false !== ( $row = fgetcsv( $handle, 0, $delim ) ) ) {
			$line_no++;

			if ( $line_no > self::MAX_ROWS ) {
				$result['errors'][] = sprintf(
					/* translators: %s: maximum row count. */
					__( 'Stopped after %s rows. Split the file and import the rest separately.', 'twentytwenty-redirect' ),
					number_format_i18n( self::MAX_ROWS )
				);
				break;
			}

			if ( 1 === $line_no ) {
				$row[0]  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
				$columns = self::map_columns( $row );

				if ( null !== $columns ) {
					continue; // Row one was a header.
				}

				$columns = array(
					'source' => 0,
					'target' => 1,
					'status' => 2,
					'notes'  => 3,
				);
			}

			if ( ! is_array( $row ) || '' === trim( implode( '', array_map( 'strval', $row ) ) ) ) {
				continue; // Blank line.
			}

			$result['total']++;

			$get = static function ( $field ) use ( $row, $columns ) {
				if ( ! isset( $columns[ $field ] ) || ! isset( $row[ $columns[ $field ] ] ) ) {
					return '';
				}
				return trim( (string) $row[ $columns[ $field ] ] );
			};

			$source = $get( 'source' );

			if ( '' === $source || 0 === stripos( $source, '#' ) ) {
				$result['skipped']++;
				continue;
			}

			$status = $get( 'status' );
			$target = $get( 'target' );

			// A destination with no status means the author wanted a redirect.
			if ( '' === $status ) {
				$status = ( '' !== $target ) ? 301 : $options['default_status'];
			}

			$prepared = TTR_DB::prepare_rule(
				array(
					'source'      => $source,
					'target'      => $target,
					'status_code' => $status,
					'notes'       => $get( 'notes' ),
					'is_active'   => $options['is_active'],
					'match_type'  => self::looks_like_regex( $source ) ? 'regex' : 'exact',
				)
			);

			if ( is_wp_error( $prepared ) ) {
				$result['skipped']++;

				if ( count( $result['errors'] ) < 10 ) {
					$result['errors'][] = sprintf(
						/* translators: 1: line number, 2: error message. */
						__( 'Line %1$d: %2$s', 'twentytwenty-redirect' ),
						$line_no,
						$prepared->get_error_message()
					);
				}
				continue;
			}

			// Later duplicates inside one file win; drop the earlier copy from this batch.
			if ( isset( $seen[ $prepared['source_hash'] ] ) ) {
				unset( $batch[ $seen[ $prepared['source_hash'] ] ] );
				$result['skipped']++;
			}

			$batch[ $index ] = $prepared;
			$seen[ $prepared['source_hash'] ] = $index;
			$index++;

			if ( count( $batch ) >= 500 ) {
				$result['imported'] += TTR_DB::bulk_upsert( array_values( $batch ) );
				$batch               = array();
				$seen                = array();
				$index               = 0;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! empty( $batch ) ) {
			$result['imported'] += TTR_DB::bulk_upsert( array_values( $batch ) );
		}

		return $result;
	}

	/**
	 * Import from a pasted list: one URL per line, optional trailing columns.
	 *
	 * @param string $text    Pasted text.
	 * @param array  $options Same as import_file().
	 * @return array
	 */
	public static function import_text( $text, array $options = array() ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'Nothing to import.', 'twentytwenty-redirect' ) ),
				'total'    => 0,
			);
		}

		$tmp = wp_tempnam( 'ttr-paste.csv' );

		if ( ! $tmp ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'Could not create a temporary file.', 'twentytwenty-redirect' ) ),
				'total'    => 0,
			);
		}

		file_put_contents( $tmp, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = self::import_file( $tmp, $options );

		wp_delete_file( $tmp );

		return $result;
	}

	/**
	 * Whether a source cell is a regular expression rather than a path.
	 *
	 * @param string $source Source cell.
	 * @return bool
	 */
	protected static function looks_like_regex( $source ) {
		return (bool) preg_match( '/^(#|~|%|\|).+\1[imsuxADSUXJ]*$/', trim( $source ) );
	}

	/**
	 * Stream every rule to the browser as a CSV download. Exits.
	 */
	public static function export() {
		$filename = 'twentytwenty-redirect-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// BOM so Excel opens UTF-8 paths correctly.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		fputcsv( $out, array( 'source', 'target', 'status', 'match_type', 'active', 'hits', 'last_hit', 'notes' ) );

		foreach ( TTR_DB::each() as $rule ) {
			fputcsv(
				$out,
				array(
					$rule->source_path,
					$rule->target,
					$rule->status_code,
					$rule->match_type,
					$rule->is_active ? 1 : 0,
					$rule->hits,
					$rule->last_hit,
					$rule->notes,
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * A small sample file so an editor can see the expected shape.
	 *
	 * @return string
	 */
	public static function sample() {
		return "source,target,status,notes\n"
			. "/old-campaign-2019,,410,Killed after the campaign ended\n"
			. "https://example.com/discontinued-product,,410,\n"
			. "/blog/old-post,/blog/new-post,301,Rewritten\n"
			. "/shop?item=42,/shop/item-42,301,Query strings are matched too\n";
	}
}
