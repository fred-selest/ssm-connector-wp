<?php
/**
 * Plugin Name: SSM Connector
 * Description: Connecteur SSM (Selest Site Manager) — inventaire + MAJ + logs + sécurité
 * Version: 0.2.0
 * Author: Selest Informatique
 * License: Private
 * Requires PHP: 7.4
 * Requires at least: 5.5
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SSM_CONNECTOR_VERSION', '0.2.0');
define('SSM_CONNECTOR_NAMESPACE', 'ssm/v1');
define('SSM_CONNECTOR_OPTION_TOKEN', 'ssm_connector_token');
define('SSM_CONNECTOR_OPTION_URL', 'ssm_connector_url');

class SSM_Connector {
    private static $instance = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('upgrader_process_complete', [$this, 'on_update_complete'], 10, 2);
        add_action('wp_login', [$this, 'on_user_login'], 10, 2);
        add_action('wp_login_failed', [$this, 'on_user_login_failed']);
        add_action('activated_plugin', [$this, 'on_plugin_change'], 10, 2);
        add_action('deactivated_plugin', [$this, 'on_plugin_change'], 10, 2);
        add_action('core_upgrade_preamble', [$this, 'on_core_update_check']);
        add_action('wp_loaded', [$this, 'schedule_heartbeat']);
        add_action('ssm_heartbeat_event', [$this, 'send_heartbeat']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }
    private $last_heartbeat_ok = false;
    private $last_heartbeat_at = null;
    public function activate() {
        $token = get_option(SSM_CONNECTOR_OPTION_TOKEN);
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            update_option(SSM_CONNECTOR_OPTION_TOKEN, $token);
        }
        if (!wp_next_scheduled('ssm_heartbeat_event')) {
            wp_schedule_event(time(), 'hourly', 'ssm_heartbeat_event');
        }
    }
    public function deactivate() {
        $ts = wp_next_scheduled('ssm_heartbeat_event');
        if ($ts) wp_unschedule_event($ts, 'ssm_heartbeat_event');
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
    // === Sprint 5: Admin UI, hooks événements, heartbeat ===

    public function register_admin_menu() {
        add_options_page('SSM Connector', 'SSM Connector', 'manage_options',
            'ssm-connector', [$this, 'render_admin_page']);
    }

    public function render_admin_page() {
        $url = get_option(SSM_CONNECTOR_OPTION_URL, '');
        $token = get_option(SSM_CONNECTOR_OPTION_TOKEN, '');
        $last_at = get_option('ssm_last_heartbeat_at');
        echo '<div class="wrap"><h1>SSM Connector</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('ssm_connector_settings');
        echo '<table class="form-table"><tr><th>URL SSM Core</th><td>';
        echo '<input type="url" name="ssm_connector_url" value="' . esc_attr($url)
             . '" class="regular-text" placeholder="https://ssm.selest.info" />';
        echo '</td></tr><tr><th>Token</th><td><code>' . esc_html($token ?: 'non défini')
             . '</code></td></tr></table>';
        submit_button('Enregistrer');
        echo '</form>';
        echo '<p>Statut heartbeat : <strong>'
             . ($this->last_heartbeat_ok ? 'OK' : 'Pas de ping') . '</strong>';
        if ($last_at) echo ' — dernier : ' . esc_html($last_at);
        echo '</p></div>';
    }

    public function on_update_complete($upgrader, $data) {
        $this->queue_event('update', [
            'type' => $data['type'] ?? 'unknown',
            'action' => $data['action'] ?? 'update',
            'items' => $data['packages'] ?? [],
        ]);
    }

    public function on_user_login($user_login, $user) {
        $this->queue_event('login', [
            'user' => $user_login,
            'role' => $user->roles[0] ?? 'subscriber',
        ]);
    }

    public function on_user_login_failed($user_login) {
        $this->queue_event('login_failed', ['user' => $user_login]);
    }

    public function on_plugin_change($plugin, $network_wide) {
        $action = current_action();
        $event = str_contains($action, 'activated') ? 'plugin_activated' : 'plugin_deactivated';
        $this->queue_event($event, ['plugin' => $plugin]);
    }

    public function on_core_update_check() {
        $updates = get_site_transient('update_core');
        $count = is_object($updates) && !empty($updates->updates) ? count($updates->updates) : 0;
        $this->queue_event('update_check', ['core_updates' => $count]);
    }

    public function schedule_heartbeat() {
        if (!wp_next_scheduled('ssm_heartbeat_event')) {
            wp_schedule_event(time(), 'hourly', 'ssm_heartbeat_event');
        }
    }

    public function send_heartbeat() {
        $inventory = $this->collect_inventory();
        $result = $this->post_to_ssm('/api/v1/heartbeat', $inventory);
        $this->last_heartbeat_ok = is_array($result) && ($result['status_code'] ?? 0) === 200;
        $this->last_heartbeat_at = current_time('mysql');
        update_option('ssm_last_heartbeat_at', $this->last_heartbeat_at);
    }

    public function collect_inventory() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
        $plugins = [];
        $update_plugins = get_site_transient('update_plugins');
        foreach (get_plugins() as $path => $data) {
            $slug = dirname($path);
            if ($slug === '.') $slug = $data['Name'];
            $plugins[] = [
                'type' => 'plugin', 'slug' => $slug, 'name' => $data['Name'],
                'version' => $data['Version'], 'is_active' => is_plugin_active($path),
                'update_available' => isset($update_plugins->response[$path]),
                'latest_version' => $update_plugins->response[$path]->new_version ?? null,
            ];
        }
        $themes = [];
        $update_themes = get_site_transient('update_themes');
        foreach (wp_get_themes() as $slug => $theme) {
            $themes[] = [
                'type' => 'theme', 'slug' => $slug, 'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'is_active' => $slug === get_stylesheet(),
                'update_available' => isset($update_themes->response[$slug]),
            ];
        }
        return [
            'cms' => 'wordpress',
            'cms_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'multisite' => is_multisite(),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'admin_email' => get_option('admin_email'),
            'active_theme' => get_stylesheet(),
            'plugin_count' => count($plugins),
            'plugin_active_count' => count(array_filter($plugins, fn($p) => $p['is_active'])),
            'theme_count' => count($themes),
            'users_count' => count_users()['total_users'] ?? 0,
            'plugins' => $plugins,
            'themes' => $themes,
            'connector_version' => SSM_CONNECTOR_VERSION,
            'timestamp' => current_time('c'),
        ];
    }

    private function queue_event($type, $payload) {
        $events = get_option('ssm_pending_events', []);
        $events[] = ['type' => $type, 'payload' => $payload, 'timestamp' => current_time('c')];
        if (count($events) > 100) $events = array_slice($events, -100);
        update_option('ssm_pending_events', $events);
    }

    private function post_to_ssm($path, $body) {
        $url = rtrim(get_option(SSM_CONNECTOR_OPTION_URL, ''), '/') . $path;
        if (empty($url)) return ['error' => 'URL non configurée'];
        $token = get_option(SSM_CONNECTOR_OPTION_TOKEN);
        $events = get_option('ssm_pending_events', []);
        $body['pending_events'] = $events;
        delete_option('ssm_pending_events');
        $payload = json_encode($body);
        $signature = hash_hmac('sha256', $payload, $token);
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-SSM-Token' => $token,
                'X-SSM-Signature' => $signature,
            ],
            'body' => $payload,
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) return ['error' => $response->get_error_message()];
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return ['status_code' => $code, 'body' => $data];
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
