<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Monitor_Agent_Updater {
	/**
	 * Transient key.
	 *
	 * @var string
	 */
	private static $transient_key = 'wp_monitor_agent_github_release';

	/**
	 * Bootstrap updater hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'maybe_add_github_auth' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Inject update data into WordPress plugin transient.
	 *
	 * @param stdClass $transient Update transient.
	 * @return stdClass
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( empty( $transient->checked ) || ! self::is_configured() ) {
			return $transient;
		}

		$release = self::get_release_data();

		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( ! version_compare( $release['version'], WP_MONITOR_AGENT_VERSION, '>' ) ) {
			return $transient;
		}

		$item = new stdClass();
		$item->slug        = 'wp-monitor-agent';
		$item->plugin      = WP_MONITOR_AGENT_BASENAME;
		$item->new_version = $release['version'];
		$item->url         = $release['url'];
		$item->package     = $release['package'];
		$item->icons       = array();
		$item->tested      = $release['tested'];
		$item->requires    = $release['requires'];
		$item->requires_php = $release['requires_php'];

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ WP_MONITOR_AGENT_BASENAME ] = $item;

		return $transient;
	}

	/**
	 * Provide plugin information for the update modal.
	 *
	 * @param false|object|array $result Existing result.
	 * @param string             $action Action.
	 * @param object             $args Arguments.
	 * @return false|object|array
	 */
	public static function plugins_api( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wp-monitor-agent' !== $args->slug || ! self::is_configured() ) {
			return $result;
		}

		$release = self::get_release_data();

		if ( empty( $release['version'] ) ) {
			return $result;
		}

		$response = new stdClass();
		$response->name          = 'WP Monitor Agent';
		$response->slug          = 'wp-monitor-agent';
		$response->version       = $release['version'];
		$response->author        = '<span>WP Monitor Agent</span>';
		$response->homepage      = $release['url'];
		$response->download_link = $release['package'];
		$response->requires      = $release['requires'];
		$response->requires_php  = $release['requires_php'];
		$response->tested        = $release['tested'];
		$response->sections      = array(
			'description' => 'Lightweight read-only monitoring agent for WordPress sites.',
			'changelog'   => wp_kses_post( nl2br( $release['changelog'] ) ),
		);

		return $response;
	}

	/**
	 * Add GitHub headers when a token is configured.
	 *
	 * @param array  $args Request args.
	 * @param string $url Request URL.
	 * @return array
	 */
	public static function maybe_add_github_auth( array $args, string $url ): array {
		if ( false === strpos( $url, 'github.com' ) && false === strpos( $url, 'api.github.com' ) && false === strpos( $url, 'objects.githubusercontent.com' ) ) {
			return $args;
		}

		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['User-Agent'] = 'WP-Monitor-Agent/' . WP_MONITOR_AGENT_VERSION;
		$args['headers']['Accept']     = false !== strpos( $url, '/releases/assets/' ) ? 'application/octet-stream' : 'application/vnd.github+json';

		$token = self::get_github_token();

		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		return $args;
	}

	/**
	 * Clear cached release data after upgrades.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		delete_site_transient( self::$transient_key );
	}

	/**
	 * Return release data from cache or GitHub.
	 *
	 * @return array
	 */
	private static function get_release_data(): array {
		$cached = get_site_transient( self::$transient_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$owner = self::get_config_constant( 'WP_MONITOR_AGENT_GITHUB_OWNER' );
		$repo  = self::get_config_constant( 'WP_MONITOR_AGENT_GITHUB_REPO' );

		if ( '' === $owner || '' === $repo ) {
			return array();
		}

		$url      = sprintf( 'https://api.github.com/repos/%1$s/%2$s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'User-Agent' => 'WP-Monitor-Agent/' . WP_MONITOR_AGENT_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return array();
		}

		$release = array(
			'version'      => self::normalize_version( (string) $body['tag_name'] ),
			'changelog'    => sanitize_textarea_field( (string) ( $body['body'] ?? '' ) ),
			'url'          => esc_url_raw( (string) ( $body['html_url'] ?? '' ) ),
			'package'      => self::resolve_package_url( $body ),
			'tested'       => self::extract_release_meta( (string) ( $body['body'] ?? '' ), 'Tested up to' ),
			'requires'     => self::extract_release_meta( (string) ( $body['body'] ?? '' ), 'Requires at least' ),
			'requires_php' => self::extract_release_meta( (string) ( $body['body'] ?? '' ), 'Requires PHP' ),
		);

		if ( '' === $release['package'] ) {
			return array();
		}

		set_site_transient( self::$transient_key, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Resolve the best download package URL.
	 *
	 * @param array $release Release payload.
	 * @return string
	 */
	private static function resolve_package_url( array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['name'] ) || 'wp-monitor-agent.zip' !== $asset['name'] ) {
					continue;
				}

				if ( ! empty( $asset['browser_download_url'] ) ) {
					return esc_url_raw( (string) $asset['browser_download_url'] );
				}

				if ( ! empty( $asset['url'] ) ) {
					return esc_url_raw( (string) $asset['url'] );
				}
			}
		}

		return ! empty( $release['zipball_url'] ) ? esc_url_raw( (string) $release['zipball_url'] ) : '';
	}

	/**
	 * Extract release metadata from the changelog body.
	 *
	 * @param string $body Release body.
	 * @param string $label Field label.
	 * @return string
	 */
	private static function extract_release_meta( string $body, string $label ): string {
		$pattern = sprintf( '/%s\s*:\s*([^\r\n]+)/i', preg_quote( $label, '/' ) );

		if ( preg_match( $pattern, $body, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		return '';
	}

	/**
	 * Normalize tags like v1.0.1 to 1.0.1.
	 *
	 * @param string $version Raw version.
	 * @return string
	 */
	private static function normalize_version( string $version ): string {
		return sanitize_text_field( ltrim( trim( $version ), "vV \t\n\r\0\x0B" ) );
	}

	/**
	 * Whether updater constants are configured.
	 *
	 * @return bool
	 */
	private static function is_configured(): bool {
		return '' !== self::get_config_constant( 'WP_MONITOR_AGENT_GITHUB_OWNER' ) && '' !== self::get_config_constant( 'WP_MONITOR_AGENT_GITHUB_REPO' );
	}

	/**
	 * Read configurable constants.
	 *
	 * @param string $constant_name Constant name.
	 * @return string
	 */
	private static function get_config_constant( string $constant_name ): string {
		if ( ! defined( $constant_name ) ) {
			return '';
		}

		return sanitize_text_field( (string) constant( $constant_name ) );
	}

	/**
	 * Return GitHub token if available.
	 *
	 * @return string
	 */
	private static function get_github_token(): string {
		return self::get_config_constant( 'WP_MONITOR_AGENT_GITHUB_TOKEN' );
	}
}