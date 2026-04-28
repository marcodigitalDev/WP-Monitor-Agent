<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Monitor_Agent_REST_Controller extends WP_REST_Controller {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wp-monitor-agent/v1';
		$this->rest_base = 'status';
	}

	/**
	 * Register plugin routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'detail'          => array(
							'type'              => 'string',
							'default'           => 'full',
							'enum'              => array( 'basic', 'full' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'refresh_updates' => array(
							'type'              => 'boolean',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_refresh_flag' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Ensure only administrators can access the endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		if ( $this->is_valid_token_request( $request ) ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wp_monitor_agent_unauthorized',
				sanitize_text_field( __( 'Authentication required. Use Bearer token or a logged-in administrator.', 'wp-monitor-agent' ) ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_monitor_agent_forbidden',
				sanitize_text_field( __( 'Insufficient permissions.', 'wp-monitor-agent' ) ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate token-based authentication for machine-to-machine requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_valid_token_request( WP_REST_Request $request ): bool {
		$configured_token = $this->get_configured_api_token();

		if ( '' === $configured_token ) {
			return false;
		}

		$provided_token = $this->extract_request_token( $request );

		if ( '' === $provided_token ) {
			return false;
		}

		return hash_equals( $configured_token, $provided_token );
	}

	/**
	 * Get API token from constant or option.
	 *
	 * @return string
	 */
	private function get_configured_api_token(): string {
		if ( defined( 'WP_MONITOR_AGENT_API_TOKEN' ) && is_string( WP_MONITOR_AGENT_API_TOKEN ) && '' !== WP_MONITOR_AGENT_API_TOKEN ) {
			return sanitize_text_field( WP_MONITOR_AGENT_API_TOKEN );
		}

		$option_token = get_option( 'wp_monitor_agent_api_token', '' );

		if ( ! is_string( $option_token ) || '' === $option_token ) {
			return '';
		}

		return sanitize_text_field( $option_token );
	}

	/**
	 * Extract token from supported headers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function extract_request_token( WP_REST_Request $request ): string {
		$token = (string) $request->get_header( 'x-wp-monitor-token' );

		if ( '' !== $token ) {
			return trim( $token );
		}

		$authorization = (string) $request->get_header( 'authorization' );

		if ( preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
			return trim( (string) $matches[1] );
		}

		return '';
	}

	/**
	 * Return the current site diagnostic status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$start_time = microtime( true );
		$warnings   = array();

		$detail          = 'basic' === $request->get_param( 'detail' ) ? 'basic' : 'full';
		$refresh_updates = (bool) $request->get_param( 'refresh_updates' );

		$checks = new WP_Monitor_Agent_Checks();

		$response = array(
			'success'               => true,
			'plugin'                => array(
				'name'          => 'WP Monitor Agent',
				'version'       => WP_MONITOR_AGENT_VERSION,
				'slug'          => 'wp-monitor-agent',
				'update_source' => 'github',
			),
			'site'                  => $checks->get_site_data(),
			'availability_internal' => $this->safe_section_call( array( $checks, 'get_availability_internal' ), $warnings, 'availability_internal' ),
			'core'                  => $this->safe_section_call( array( $checks, 'get_core_data' ), $warnings, 'core', array( $refresh_updates ) ),
			'plugins'               => $this->safe_section_call( array( $checks, 'get_plugins_data' ), $warnings, 'plugins', array( $detail ) ),
			'themes'                => $this->safe_section_call( array( $checks, 'get_themes_data' ), $warnings, 'themes', array( $detail ) ),
			'health'                => $this->safe_section_call( array( $checks, 'get_health_data' ), $warnings, 'health' ),
			'performance_internal'  => array(),
			'errors_recent'         => $this->safe_section_call( array( 'WP_Monitor_Agent_Logs', 'get_recent_errors' ), $warnings, 'errors_recent' ),
			'warnings'              => array(),
			'generated_at'          => gmdate( 'c' ),
			'timezone'              => sanitize_text_field( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string', '' ) ),
		);

		$response['performance_internal'] = $checks->get_performance_data( $start_time );
		$response['warnings']             = array_values( array_unique( array_filter( $warnings ) ) );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Convert refresh flag values into booleans.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function sanitize_refresh_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( (string) $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Execute a section callback in fail-safe mode.
	 *
	 * @param callable $callback Callback.
	 * @param array    $warnings Warnings array.
	 * @param string   $section  Section name.
	 * @param array    $args     Optional args.
	 * @return array
	 */
	private function safe_section_call( callable $callback, array &$warnings, string $section, array $args = array() ): array {
		try {
			$result = call_user_func_array( $callback, $args );

			return is_array( $result ) ? $result : array();
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			$warnings[] = sprintf(
				/* translators: %s: section name. */
				sanitize_text_field( __( 'Section %s could not be completed.', 'wp-monitor-agent' ) ),
				sanitize_key( $section )
			);

			return array(
				'error' => sanitize_text_field( __( 'A controlled error occurred while building this section.', 'wp-monitor-agent' ) ),
			);
		}
	}
}