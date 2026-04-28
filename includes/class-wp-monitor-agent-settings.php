<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Monitor_Agent_Settings {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_post_wp_monitor_agent_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
		add_filter( 'plugin_action_links_' . WP_MONITOR_AGENT_BASENAME, array( __CLASS__, 'add_action_links' ) );
	}

	/**
	 * Add Settings link to the plugin action links.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'plugins.php?page=wp-monitor-agent' ) ),
			esc_html__( 'Settings', 'wp-monitor-agent' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register settings submenu under Plugins.
	 *
	 * @return void
	 */
	public static function add_menu_page(): void {
		add_plugins_page(
			__( 'WP Monitor Agent', 'wp-monitor-agent' ),
			__( 'WP Monitor Agent', 'wp-monitor-agent' ),
			'manage_options',
			'wp-monitor-agent',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle cache-clear form submission.
	 *
	 * @return void
	 */
	public static function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-monitor-agent' ), 403 );
		}

		check_admin_referer( 'wp_monitor_agent_clear_cache' );

		delete_site_transient( 'wp_monitor_agent_github_release' );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wp-monitor-agent',
					'cleared' => '1',
				),
				admin_url( 'plugins.php' )
			)
		);

		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$token   = self::get_api_token();
		$cleared = isset( $_GET['cleared'] ) && '1' === $_GET['cleared']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Monitor Agent', 'wp-monitor-agent' ); ?></h1>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Update cache cleared. WordPress will check GitHub on the next request.', 'wp-monitor-agent' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'API Token', 'wp-monitor-agent' ); ?></h2>
			<p><?php esc_html_e( 'Use this token in n8n or any external tool to authenticate requests to the monitoring endpoint.', 'wp-monitor-agent' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Token', 'wp-monitor-agent' ); ?></th>
					<td>
						<?php if ( '' !== $token ) : ?>
							<code style="font-size:13px;user-select:all;"><?php echo esc_html( $token ); ?></code>
							<p class="description">
								<?php esc_html_e( 'Add this header to your requests: Authorization: Bearer TOKEN', 'wp-monitor-agent' ); ?>
							</p>
						<?php else : ?>
							<p class="description" style="color:#b32d2e;">
								<?php esc_html_e( 'No token configured. Add WP_MONITOR_AGENT_API_TOKEN to wp-config.php or reactivate the plugin to generate one automatically.', 'wp-monitor-agent' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Endpoint', 'wp-monitor-agent' ); ?></th>
					<td>
						<code style="font-size:13px;user-select:all;"><?php echo esc_url( rest_url( 'wp-monitor-agent/v1/status' ) ); ?></code>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Updates', 'wp-monitor-agent' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: installed version number. */
					esc_html__( 'Installed version: %s', 'wp-monitor-agent' ),
					'<strong>' . esc_html( WP_MONITOR_AGENT_VERSION ) . '</strong>'
				);
				?>
			</p>
			<p><?php esc_html_e( 'If a new version is not appearing in the Plugins screen, clear the update cache to force WordPress to check GitHub again.', 'wp-monitor-agent' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_monitor_agent_clear_cache">
				<?php wp_nonce_field( 'wp_monitor_agent_clear_cache' ); ?>
				<?php submit_button( __( 'Clear update cache', 'wp-monitor-agent' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Return the configured API token.
	 *
	 * @return string
	 */
	private static function get_api_token(): string {
		if ( defined( 'WP_MONITOR_AGENT_API_TOKEN' ) && is_string( WP_MONITOR_AGENT_API_TOKEN ) && '' !== WP_MONITOR_AGENT_API_TOKEN ) {
			return sanitize_text_field( WP_MONITOR_AGENT_API_TOKEN );
		}

		$option_token = get_option( 'wp_monitor_agent_api_token', '' );

		if ( is_string( $option_token ) && '' !== $option_token ) {
			return sanitize_text_field( $option_token );
		}

		return '';
	}
}
