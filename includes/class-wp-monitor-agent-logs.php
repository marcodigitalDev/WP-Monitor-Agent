<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Monitor_Agent_Logs {
	/**
	 * Return recent error summary from debug.log.
	 *
	 * @return array
	 */
	public static function get_recent_errors(): array {
		$log_max_bytes = self::get_log_max_bytes();
		$log_info      = self::get_debug_log_info();

		$data = array(
			'debug_log_exists'         => false,
			'debug_log_readable'       => false,
			'debug_log_path_type'      => sanitize_key( $log_info['path_type'] ),
			'fatal_errors_last_24h'    => 0,
			'warnings_last_24h'        => 0,
			'database_errors_last_24h' => 0,
			'last_fatal_error'         => null,
			'last_warning'             => null,
			'last_database_error'      => null,
			'log_scan_limited'         => false,
			'log_scan_bytes'           => 0,
			'date_parse_reliable'      => true,
		);

		if ( empty( $log_info['path'] ) || ! file_exists( $log_info['path'] ) ) {
			$data['debug_log_path_type'] = 'not_found';

			return $data;
		}

		$data['debug_log_exists'] = true;

		if ( ! is_readable( $log_info['path'] ) ) {
			return $data;
		}

		$data['debug_log_readable'] = true;

		$file_size      = (int) filesize( $log_info['path'] );
		$bytes_to_read  = min( $log_max_bytes, max( $file_size, 0 ) );
		$data['log_scan_bytes']   = $bytes_to_read;
		$data['log_scan_limited'] = $file_size > $bytes_to_read;

		if ( 0 === $bytes_to_read ) {
			return $data;
		}

		$handle = @fopen( $log_info['path'], 'rb' );

		if ( false === $handle ) {
			$data['debug_log_readable'] = false;

			return $data;
		}

		if ( $file_size > $bytes_to_read ) {
			fseek( $handle, -1 * $bytes_to_read, SEEK_END );
		}

		$buffer = (string) fread( $handle, $bytes_to_read );
		fclose( $handle );

		$lines          = preg_split( "/\r\n|\n|\r/", $buffer );
		$reliable_dates = false;
		$threshold      = time() - DAY_IN_SECONDS;

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$timestamp = self::extract_timestamp( $line );

			if ( null !== $timestamp ) {
				$reliable_dates = true;

				if ( $timestamp < $threshold ) {
					continue;
				}
			}

			self::apply_match( $line, '/PHP Fatal error|Fatal error/i', 'fatal_errors_last_24h', 'last_fatal_error', $data );
			self::apply_match( $line, '/PHP Warning|Warning/i', 'warnings_last_24h', 'last_warning', $data );
			self::apply_match( $line, '/WordPress database error|database error|MySQL server has gone away|Error establishing a database connection/i', 'database_errors_last_24h', 'last_database_error', $data );
		}

		$data['date_parse_reliable'] = $reliable_dates;

		return $data;
	}

	/**
	 * Match log patterns and update counters.
	 *
	 * @param string $line Log line.
	 * @param string $pattern Pattern.
	 * @param string $counter_key Counter key.
	 * @param string $message_key Message key.
	 * @param array  $data Data array.
	 * @return void
	 */
	private static function apply_match( string $line, string $pattern, string $counter_key, string $message_key, array &$data ): void {
		if ( ! preg_match( $pattern, $line ) ) {
			return;
		}

		++$data[ $counter_key ];
		$data[ $message_key ] = self::sanitize_log_excerpt( $line );
	}

	/**
	 * Find the active debug log.
	 *
	 * @return array
	 */
	private static function get_debug_log_info(): array {
		if ( defined( 'WP_DEBUG_LOG' ) ) {
			if ( true === WP_DEBUG_LOG ) {
				return array(
					'path'      => WP_CONTENT_DIR . '/debug.log',
					'path_type' => 'default',
				);
			}

			if ( is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
				return array(
					'path'      => WP_DEBUG_LOG,
					'path_type' => 'custom',
				);
			}
		}

		$default_path = WP_CONTENT_DIR . '/debug.log';

		if ( file_exists( $default_path ) ) {
			return array(
				'path'      => $default_path,
				'path_type' => 'default',
			);
		}

		return array(
			'path'      => '',
			'path_type' => 'not_found',
		);
	}

	/**
	 * Parse timestamps when possible.
	 *
	 * @param string $line Log line.
	 * @return int|null
	 */
	private static function extract_timestamp( string $line ): ?int {
		if ( preg_match( '/^\[([^\]]+)\]/', $line, $matches ) ) {
			$timestamp = strtotime( $matches[1] );

			if ( false !== $timestamp ) {
				return $timestamp;
			}
		}

		if ( preg_match( '/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $matches ) ) {
			$timestamp = strtotime( $matches[1] );

			if ( false !== $timestamp ) {
				return $timestamp;
			}
		}

		return null;
	}

	/**
	 * Sanitize a log excerpt for safe output.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private static function sanitize_log_excerpt( string $message ): string {
		$message = wp_strip_all_tags( $message );
		$message = str_replace( ABSPATH, '[ABSPATH]/', $message );
		$message = preg_replace( '#(?:[A-Za-z]:)?/(?:[^\s]+/)+([^/\s]+)#', '[path]/$1', $message );
		$message = preg_replace( '/\s+/', ' ', (string) $message );
		$message = trim( (string) $message );

		if ( strlen( $message ) > 300 ) {
			$message = substr( $message, 0, 297 ) . '...';
		}

		return sanitize_text_field( $message );
	}

	/**
	 * Determine the maximum bytes to read from the log.
	 *
	 * @return int
	 */
	private static function get_log_max_bytes(): int {
		$default = 524288;
		$value   = defined( 'WP_MONITOR_AGENT_LOG_MAX_BYTES' ) ? (int) WP_MONITOR_AGENT_LOG_MAX_BYTES : $default;

		$value = (int) apply_filters( 'wp_monitor_agent_log_max_bytes', $value );

		return max( 1024, $value );
	}
}