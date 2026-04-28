<?php
/**
 * Plugin Name: WP Monitor Agent
 * Description: Lightweight read-only monitoring agent for WordPress sites.
 * Version: 1.0.2
 * Author: WP Monitor Agent
 * Text Domain: wp-monitor-agent
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_MONITOR_AGENT_VERSION', '1.0.2' );
define( 'WP_MONITOR_AGENT_FILE', __FILE__ );
define( 'WP_MONITOR_AGENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_MONITOR_AGENT_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_MONITOR_AGENT_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'WP_MONITOR_AGENT_GITHUB_OWNER' ) ) {
	define( 'WP_MONITOR_AGENT_GITHUB_OWNER', 'marcodigitalDev' );
}

if ( ! defined( 'WP_MONITOR_AGENT_GITHUB_REPO' ) ) {
	define( 'WP_MONITOR_AGENT_GITHUB_REPO', 'WP-Monitor-Agent' );
}

if ( ! defined( 'WP_MONITOR_AGENT_GITHUB_BRANCH' ) ) {
	define( 'WP_MONITOR_AGENT_GITHUB_BRANCH', 'main' );
}

if ( ! defined( 'WP_MONITOR_AGENT_GITHUB_TOKEN' ) ) {
	define( 'WP_MONITOR_AGENT_GITHUB_TOKEN', '' );
}

if ( ! defined( 'WP_MONITOR_AGENT_API_TOKEN' ) ) {
	define( 'WP_MONITOR_AGENT_API_TOKEN', '' );
}

require_once WP_MONITOR_AGENT_PATH . 'includes/class-wp-monitor-agent-checks.php';
require_once WP_MONITOR_AGENT_PATH . 'includes/class-wp-monitor-agent-logs.php';
require_once WP_MONITOR_AGENT_PATH . 'includes/class-wp-monitor-agent-rest-controller.php';
require_once WP_MONITOR_AGENT_PATH . 'includes/class-wp-monitor-agent-updater.php';

final class WP_Monitor_Agent {
	/**
	 * Ensure plugin defaults exist.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! get_option( 'wp_monitor_agent_api_token' ) ) {
			add_option( 'wp_monitor_agent_api_token', wp_generate_password( 48, false, false ), '', false );
		}
	}

	/**
	 * Bootstrap plugin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		if ( is_admin() || wp_doing_cron() ) {
			WP_Monitor_Agent_Updater::init();
		}
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		$controller = new WP_Monitor_Agent_REST_Controller();
		$controller->register_routes();
	}
}

register_activation_hook( WP_MONITOR_AGENT_FILE, array( 'WP_Monitor_Agent', 'activate' ) );

WP_Monitor_Agent::init();