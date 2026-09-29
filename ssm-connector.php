<?php
/**
 * Plugin Name: SSM Connector
 * Description: Connecteur SSM (Selest Site Manager) — inventaire + MAJ + logs + sécurité
 * Version: 0.1.0
 * Author: Selest Informatique
 * License: Private
 * Requires PHP: 7.4
 * Requires at least: 5.5
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SSM_CONNECTOR_VERSION', '0.1.0');
define('SSM_CONNECTOR_NAMESPACE', 'ssm/v1');
define('SSM_CONNECTOR_OPTION_TOKEN', 'ssm_connector_token');

class SSM_Connector {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }
    public function activate() {
        $token = get_option(SSM_CONNECTOR_OPTION_TOKEN);
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            update_option(SSM_CONNECTOR_OPTION_TOKEN, $token);
        }
    }
    public function deactivate() {}
    public function register_rest_routes() {
        $ns = SSM_CONNECTOR_NAMESPACE;
        register_rest_route($ns, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_status'],
            'permission_callback' => [$this, 'check_token'],
        ]);
        register_rest_route($ns, '/extensions', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_extensions'],
            'permission_callback' => [$this, 'check_token'],
        ]);
        register_rest_route($ns, '/heartbeat', [
            'methods' => 'POST,GET',
            'callback' => [$this, 'rest_heartbeat'],
            'permission_callback' => [$this, 'check_token'],
        ]);
    }
    public function check_token($request) {
        $expected = get_option(SSM_CONNECTOR_OPTION_TOKEN);
        if (empty($expected)) return new WP_Error('ssm_no_token', 'Token non configuré', ['status' => 500]);
        $auth = $request->get_header('authorization');
        if ($auth && preg_match('/Bearer\s+(.+)$/i', $auth, $m)) {
            if (hash_equals($expected, trim($m[1]))) return true;
        }
        $token_qs = $request->get_param('token');
        if ($token_qs && hash_equals($expected, $token_qs)) return true;
        return new WP_Error('ssm_unauthorized', 'Token invalide', ['status' => 401]);
    }
    public function rest_status($request) {
        return [
            'status' => 'ok',
            'connector_version' => SSM_CONNECTOR_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'site_url' => get_site_url(),
            'multisite' => is_multisite(),
        ];
    }
    public function rest_heartbeat($request) {
        return [
            'status' => 'ok',
            'timestamp' => current_time('c'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'db_version' => $this->get_db_version(),
            'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'site_url' => get_site_url(),
            'multisite' => is_multisite(),
            'ssl_enabled' => is_ssl(),
        ];
    }
    public function rest_extensions($request) {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        $active = get_option('active_plugins', []);
        $updates = get_option('update_plugins', []);
        $extensions = [];
        foreach ($plugins as $path => $data) {
            $slug = dirname($path);
            if ($slug === '.') $slug = $data['Name'];
            $has_update = isset($updates->response[$path]);
            $extensions[] = [
                'type' => 'plugin',
                'slug' => $slug,
                'name' => $data['Name'],
                'version' => $data['Version'],
                'is_active' => in_array($path, $active, true),
                'update_available' => (bool)$has_update,
                'latest_version' => $has_update ? ($updates->response[$path]->new_version ?? null) : null,
            ];
        }
        $themes = wp_get_themes();
        $active_theme = wp_get_theme();
        $theme_updates = get_option('update_themes', []);
        $theme_list = [];
        foreach ($themes as $slug => $theme) {
            $theme_list[] = [
                'type' => 'theme',
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'is_active' => ($active_theme->get_stylesheet() === $slug),
                'parent_theme' => $theme->get('Template') ?: null,
                'latest_version' => isset($theme_updates[$slug]) ? $theme_updates[$slug]->new_version : null,
                'update_available' => isset($theme_updates[$slug]),
            ];
        }
        return [
            'extensions' => $extensions,
            'themes' => $theme_list,
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
        ];
    }
    private function get_db_version() {
        global $wpdb;
        return $wpdb->db_version();
    }
}

SSM_Connector::instance();

if (defined('WP_CLI') && WP_CLI) {
    class SSM_Connector_CLI {
        public function status($args, $assoc_args) {
            $token = get_option(SSM_CONNECTOR_OPTION_TOKEN);
            WP_CLI::success("SSM Connector v" . SSM_CONNECTOR_VERSION);
            WP_CLI::line("Site URL : " . get_site_url());
            WP_CLI::line("WP version : " . get_bloginfo('version'));
            WP_CLI::line("PHP : " . phpversion());
            WP_CLI::line("Token défini : " . ($token ? 'oui (64 chars)' : 'NON'));
        }
        public function token($args, $assoc_args) {
            WP_CLI::line(get_option(SSM_CONNECTOR_OPTION_TOKEN) ?: 'Aucun');
        }
    }
    WP_CLI::add_command('ssm status', ['SSM_Connector_CLI', 'status']);
    WP_CLI::add_command('ssm token', ['SSM_Connector_CLI', 'token']);
}
