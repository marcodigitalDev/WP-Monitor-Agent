<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Monitor_Agent_Checks {
	/**
	 * Return base site data.
	 *
	 * @return array
	 */
	public function get_site_data(): array {
		return array(
			'name'             => sanitize_text_field( get_bloginfo( 'name' ) ),
			'site_url'         => esc_url_raw( site_url() ),
			'home_url'         => esc_url_raw( home_url( '/' ) ),
			'admin_url'        => esc_url_raw( admin_url() ),
			'rest_url'         => esc_url_raw( rest_url() ),
			'environment_type' => sanitize_text_field( $this->get_environment_type() ),
			'multisite'        => is_multisite(),
		);
	}

	/**
	 * Return internal availability data.
	 *
	 * @return array
	 */
	public function get_availability_internal(): array {
		$rest_probe = $this->probe_url( rest_url( '/' ) );
		$site_probe = $this->probe_url( home_url( '/' ) );

		return array(
			'site_url'                    => esc_url_raw( site_url() ),
			'home_url'                    => esc_url_raw( home_url( '/' ) ),
			'admin_url'                   => esc_url_raw( admin_url() ),
			'rest_url'                    => esc_url_raw( rest_url() ),
			'rest_api_internal_available' => $rest_probe['available'],
			'rest_api_internal_status'    => sanitize_text_field( $rest_probe['status'] ),
			'rest_api_response_code'      => $rest_probe['response_code'],
			'rest_api_response_time_ms'   => $rest_probe['response_time_ms'],
			'loopback_available'          => $site_probe['available'],
			'loopback_status'             => sanitize_text_field( $site_probe['status'] ),
			'loopback_response_code'      => $site_probe['response_code'],
			'loopback_response_time_ms'   => $site_probe['response_time_ms'],
		);
	}

	/**
	 * Return core update status.
	 *
	 * @param bool $refresh_updates Whether to force refresh.
	 * @return array
	 */
	public function get_core_data( bool $refresh_updates = false ): array {
		if ( $refresh_updates ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
			wp_version_check();
		}

		$installed_version = (string) get_bloginfo( 'version' );
		$latest_version    = null;
		$update_available  = false;
		$update_type       = 'unknown';
		$update_core       = get_site_transient( 'update_core' );

		if ( is_object( $update_core ) && ! empty( $update_core->updates ) && is_array( $update_core->updates ) ) {
			foreach ( $update_core->updates as $update_item ) {
				if ( ! is_object( $update_item ) || empty( $update_item->current ) ) {
					continue;
				}

				$candidate = (string) $update_item->current;

				if ( null === $latest_version || version_compare( $candidate, $latest_version, '>' ) ) {
					$latest_version = $candidate;
				}

				if ( ! empty( $update_item->response ) && 'latest' !== $update_item->response ) {
					$update_available = true;
				}
			}
		}

		if ( $latest_version && version_compare( $latest_version, $installed_version, '>' ) ) {
			$update_available = true;
			$update_type      = $this->detect_update_type( $installed_version, $latest_version );
		} elseif ( $latest_version ) {
			$update_type = 'minor';
		}

		return array(
			'installed_version' => sanitize_text_field( $installed_version ),
			'latest_version'    => $latest_version ? sanitize_text_field( $latest_version ) : null,
			'update_available'  => (bool) $update_available,
			'update_type'       => sanitize_key( $update_type ),
		);
	}

	/**
	 * Return plugin data.
	 *
	 * @param string $detail Response detail level.
	 * @return array
	 */
	public function get_plugins_data( string $detail = 'full' ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$all_plugins     = get_plugins();
		$plugin_updates  = get_site_transient( 'update_plugins' );
		$update_response = ( is_object( $plugin_updates ) && isset( $plugin_updates->response ) && is_array( $plugin_updates->response ) ) ? $plugin_updates->response : array();
		$items           = array();
		$total_active    = 0;

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$is_active = is_plugin_active( $plugin_file );

			if ( $is_active ) {
				++$total_active;
			}

			if ( 'full' !== $detail ) {
				continue;
			}

			$update_item = $update_response[ $plugin_file ] ?? null;

			$items[] = array(
				'name'             => sanitize_text_field( $plugin_data['Name'] ?? $plugin_file ),
				'slug'             => sanitize_title( $this->get_plugin_slug( $plugin_file ) ),
				'plugin_file'      => sanitize_text_field( $plugin_file ),
				'version'          => sanitize_text_field( $plugin_data['Version'] ?? '' ),
				'active'           => (bool) $is_active,
				'update_available' => null !== $update_item,
				'latest_version'   => isset( $update_item->new_version ) ? sanitize_text_field( (string) $update_item->new_version ) : null,
				'requires_wp'      => isset( $update_item->requires ) ? sanitize_text_field( (string) $update_item->requires ) : null,
				'requires_php'     => isset( $update_item->requires_php ) ? sanitize_text_field( (string) $update_item->requires_php ) : null,
			);
		}

		$data = array(
			'total_installed'   => count( $all_plugins ),
			'total_active'      => $total_active,
			'updates_available' => count( $update_response ),
		);

		if ( 'full' === $detail ) {
			$data['items'] = $items;
		}

		return $data;
	}

	/**
	 * Return theme data.
	 *
	 * @param string $detail Response detail level.
	 * @return array
	 */
	public function get_themes_data( string $detail = 'full' ): array {
		$active_theme   = wp_get_theme();
		$all_themes     = wp_get_themes();
		$theme_updates  = get_site_transient( 'update_themes' );
		$update_response = ( is_object( $theme_updates ) && isset( $theme_updates->response ) && is_array( $theme_updates->response ) ) ? $theme_updates->response : array();
		$items          = array();

		foreach ( $all_themes as $stylesheet => $theme ) {
			if ( 'full' !== $detail ) {
				continue;
			}

			$update_item = $update_response[ $stylesheet ] ?? null;

			$items[] = array(
				'name'             => sanitize_text_field( $theme->get( 'Name' ) ),
				'stylesheet'       => sanitize_text_field( $stylesheet ),
				'version'          => sanitize_text_field( $theme->get( 'Version' ) ),
				'active'           => $active_theme->get_stylesheet() === $stylesheet,
				'update_available' => null !== $update_item,
				'latest_version'   => isset( $update_item['new_version'] ) ? sanitize_text_field( (string) $update_item['new_version'] ) : null,
			);
		}

		$data = array(
			'active_theme'      => array(
				'name'           => sanitize_text_field( $active_theme->get( 'Name' ) ),
				'stylesheet'     => sanitize_text_field( $active_theme->get_stylesheet() ),
				'template'       => sanitize_text_field( $active_theme->get_template() ),
				'version'        => sanitize_text_field( $active_theme->get( 'Version' ) ),
				'is_child_theme' => $active_theme->parent() instanceof WP_Theme,
				'parent_theme'   => $active_theme->parent() ? sanitize_text_field( $active_theme->parent()->get( 'Name' ) ) : null,
			),
			'total_installed'   => count( $all_themes ),
			'updates_available' => count( $update_response ),
		);

		if ( 'full' === $detail ) {
			$data['items'] = $items;
		}

		return $data;
	}

	/**
	 * Return health data.
	 *
	 * @return array
	 */
	public function get_health_data(): array {
		$timezone_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string', '' );

		return array(
			'php_version'        => sanitize_text_field( PHP_VERSION ),
			'memory_limit'       => sanitize_text_field( (string) ini_get( 'memory_limit' ) ),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'upload_max_filesize' => sanitize_text_field( (string) ini_get( 'upload_max_filesize' ) ),
			'post_max_size'      => sanitize_text_field( (string) ini_get( 'post_max_size' ) ),
			'wp_debug'           => $this->get_defined_bool_constant( 'WP_DEBUG', false ),
			'wp_debug_log'       => $this->get_defined_bool_constant( 'WP_DEBUG_LOG', false, true ),
			'wp_debug_display'   => $this->get_defined_bool_constant( 'WP_DEBUG_DISPLAY', false ),
			'disallow_file_edit' => $this->get_defined_bool_constant( 'DISALLOW_FILE_EDIT', false ),
			'disable_wp_cron'    => $this->get_defined_bool_constant( 'DISABLE_WP_CRON', false ),
			'wp_cron_disabled'   => $this->get_defined_bool_constant( 'DISABLE_WP_CRON', false ),
			'environment_type'   => sanitize_text_field( $this->get_environment_type() ),
			'timezone_string'    => sanitize_text_field( $timezone_string ),
			'gmt_offset'         => (float) get_option( 'gmt_offset', 0 ),
			'multisite'          => is_multisite(),
		);
	}

	/**
	 * Return endpoint-local performance data.
	 *
	 * @param float $start_time Endpoint start time.
	 * @return array
	 */
	public function get_performance_data( float $start_time ): array {
		$memory_usage      = memory_get_usage( true );
		$peak_memory_usage = memory_get_peak_usage( true );

		return array(
			'endpoint_execution_time_ms' => round( ( microtime( true ) - $start_time ) * 1000, 2 ),
			'memory_usage_mb'           => round( $memory_usage / 1048576, 2 ),
			'peak_memory_usage_mb'      => round( $peak_memory_usage / 1048576, 2 ),
			'object_cache_enabled'      => function_exists( 'wp_cache_get' ),
			'external_object_cache'     => wp_using_ext_object_cache(),
			'page_cache_note'           => 'Validate page cache externally via response headers such as cf-cache-status, x-litespeed-cache, x-cache, or cache-control.',
		);
	}

	/**
	 * Probe an internal URL with a small timeout.
	 *
	 * @param string $url URL to probe.
	 * @return array
	 */
	private function probe_url( string $url ): array {
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'available'        => false,
				'status'           => sanitize_text_field( $response->get_error_message() ),
				'response_code'    => null,
				'response_time_ms' => round( ( microtime( true ) - $start ) * 1000, 2 ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'available'        => $code >= 200 && $code < 400,
			'status'           => sanitize_text_field( wp_remote_retrieve_response_message( $response ) ?: 'ok' ),
			'response_code'    => $code,
			'response_time_ms' => round( ( microtime( true ) - $start ) * 1000, 2 ),
		);
	}

	/**
	 * Get a plugin slug from plugin file.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return string
	 */
	private function get_plugin_slug( string $plugin_file ): string {
		$parts = explode( '/', $plugin_file );

		if ( count( $parts ) > 1 ) {
			return (string) reset( $parts );
		}

		return basename( $plugin_file, '.php' );
	}

	/**
	 * Determine the update type.
	 *
	 * @param string $installed Installed version.
	 * @param string $latest Latest version.
	 * @return string
	 */
	private function detect_update_type( string $installed, string $latest ): string {
		$installed_parts = array_map( 'intval', explode( '.', $installed ) );
		$latest_parts    = array_map( 'intval', explode( '.', $latest ) );

		if ( ( $latest_parts[0] ?? 0 ) !== ( $installed_parts[0] ?? 0 ) ) {
			return 'major';
		}

		return 'minor';
	}

	/**
	 * Get a normalized environment type.
	 *
	 * @return string
	 */
	private function get_environment_type(): string {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return (string) wp_get_environment_type();
		}

		return '';
	}

	/**
	 * Return boolean-like constants safely.
	 *
	 * @param string $constant_name Constant name.
	 * @param bool   $default Default value.
	 * @param bool   $string_truthy Treat string constants as enabled.
	 * @return bool
	 */
	private function get_defined_bool_constant( string $constant_name, bool $default = false, bool $string_truthy = false ): bool {
		if ( ! defined( $constant_name ) ) {
			return $default;
		}

		$value = constant( $constant_name );

		if ( $string_truthy && is_string( $value ) ) {
			return '' !== $value;
		}

		return (bool) $value;
	}
}