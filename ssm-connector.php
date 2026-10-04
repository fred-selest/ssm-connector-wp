<?php
/**
 * Plugin Name: SSM Connector
 * Description: Connecteur SSM (Selest Site Manager) : envoie toutes les heures l'inventaire du site (WordPress, PHP, extensions, thèmes) à SSM Core.
 * Version: 0.2.1
 * Author: Selest Informatique
 * License: Private
 * Requires PHP: 7.4
 * Requires at least: 5.5
 */

if (!defined('ABSPATH')) {
    exit;
}

// Déjà chargé (par exemple une copie dans mu-plugins ET une dans plugins) : ne pas déclarer deux fois la classe.
if (defined('SSM_CONNECTOR_VERSION')) {
    return;
}

define('SSM_CONNECTOR_VERSION', '0.2.1');
define('SSM_CONNECTOR_FILE', __FILE__);
define('SSM_CONNECTOR_NAMESPACE', 'ssm/v1');
define('SSM_CONNECTOR_OPTION_TOKEN', 'ssm_connector_token');
define('SSM_CONNECTOR_OPTION_URL', 'ssm_connector_url');
define('SSM_CONNECTOR_SETTINGS_GROUP', 'ssm_connector_settings');
define('SSM_CONNECTOR_PAGE', 'ssm-connector');

// Déclaration conditionnelle : PHP déclare d'avance les classes « simples », avant même d'exécuter le `return` ci-dessus.
// Sans ce `if`, charger le fichier une 2e fois (copie dans mu-plugins ET dans plugins) provoquerait « Cannot declare class ».
if (!class_exists('SSM_Connector', false)) :

class SSM_Connector {
    const HEARTBEAT_HOOK = 'ssm_heartbeat_event';
    const OPT_EVENTS = 'ssm_pending_events';
    const OPT_LAST_AT = 'ssm_last_heartbeat_at';
    const OPT_LAST_OK = 'ssm_last_heartbeat_ok';
    const OPT_LAST_MSG = 'ssm_last_heartbeat_message';
    const MAX_EVENTS = 100;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_ssm_connector_test', [$this, 'handle_test_request']);
        add_action('upgrader_process_complete', [$this, 'on_update_complete'], 10, 2);
        add_action('wp_login', [$this, 'on_user_login'], 10, 2);
        add_action('wp_login_failed', [$this, 'on_user_login_failed']);
        add_action('activated_plugin', [$this, 'on_plugin_activated'], 10, 2);
        add_action('deactivated_plugin', [$this, 'on_plugin_deactivated'], 10, 2);
        add_action('core_upgrade_preamble', [$this, 'on_core_update_check']);
        add_action('wp_loaded', [$this, 'schedule_heartbeat']);
        add_action(self::HEARTBEAT_HOOK, [$this, 'send_heartbeat']);
        register_activation_hook(SSM_CONNECTOR_FILE, [$this, 'activate']);
        register_deactivation_hook(SSM_CONNECTOR_FILE, [$this, 'deactivate']);
    }

    // === Activation / planification ===

    public function activate() {
        $this->ensure_token();
        $this->schedule_heartbeat();
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::HEARTBEAT_HOOK);
    }

    /** Token propre au site : créé s'il manque (aussi en mu-plugin, où le hook d'activation n'existe pas). */
    private function ensure_token() {
        $token = get_option(SSM_CONNECTOR_OPTION_TOKEN);
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            update_option(SSM_CONNECTOR_OPTION_TOKEN, $token);
        }
        return $token;
    }

    public function schedule_heartbeat() {
        $this->ensure_token();
        if (!wp_next_scheduled(self::HEARTBEAT_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HEARTBEAT_HOOK);
        }
    }

    // === API REST (token requis) ===

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
            'methods' => 'GET,POST',
            'callback' => [$this, 'rest_heartbeat'],
            'permission_callback' => [$this, 'check_token'],
        ]);
    }

    /** Token dans `X-SSM-Token` ou `Authorization: Bearer`. Jamais dans l'URL (elle finit dans les journaux). */
    public function check_token($request) {
        $expected = (string) get_option(SSM_CONNECTOR_OPTION_TOKEN, '');
        if ($expected === '') {
            return new WP_Error('ssm_no_token', 'Token non configuré', ['status' => 500]);
        }
        $given = trim((string) $request->get_header('x-ssm-token'));
        if ($given === '') {
            $auth = (string) $request->get_header('authorization');
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
                $given = trim($m[1]);
            }
        }
        if ($given !== '' && hash_equals($expected, $given)) {
            return true;
        }
        return new WP_Error('ssm_unauthorized', 'Token invalide', ['status' => 401]);
    }

    public function rest_status($request) {
        return [
            'status' => 'ok',
            'connector_version' => SSM_CONNECTOR_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => $this->php_version(),
            'site_url' => get_site_url(),
            'multisite' => is_multisite(),
        ];
    }

    public function rest_heartbeat($request) {
        return [
            'status' => 'ok',
            'timestamp' => current_time('c'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => $this->php_version(),
            'db_version' => $this->get_db_version(),
            'web_server' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'unknown',
            'site_url' => get_site_url(),
            'multisite' => is_multisite(),
            'ssl_enabled' => is_ssl(),
        ];
    }

    public function rest_extensions($request) {
        return [
            'extensions' => $this->collect_plugins(),
            'themes' => $this->collect_themes(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => $this->php_version(),
        ];
    }

    // === Inventaire ===
    // Les longueurs maximales sont celles de SSM Core (app/schemas.py) : au-delà, Core refuse tout le heartbeat (422).

    private function php_version() {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    }

    private function get_db_version() {
        global $wpdb;
        return isset($wpdb) && method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '';
    }

    private function cut($value, $max) {
        $value = (string) $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function cut_or_null($value, $max) {
        $value = $this->cut($value, $max);
        return $value === '' ? null : $value;
    }

    private function collect_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $updates = get_site_transient('update_plugins');
        $response = (is_object($updates) && isset($updates->response) && is_array($updates->response)) ? $updates->response : [];
        $list = [];
        $seen = [];
        foreach (get_plugins() as $path => $data) {
            // dossier du plugin ; pour un plugin d'un seul fichier, le nom du fichier sans .php
            $slug = dirname($path);
            if ($slug === '.') {
                $slug = basename($path, '.php');
            }
            if (isset($seen[$slug])) {
                $slug = $path; // deux plugins qui donneraient le même identifiant : Core refuserait le doublon
            }
            $seen[$slug] = true;
            $latest = null;
            if (isset($response[$path])) {
                $info = $response[$path];
                $latest = is_object($info) ? ($info->new_version ?? null) : (is_array($info) ? ($info['new_version'] ?? null) : null);
            }
            $name = wp_strip_all_tags((string) ($data['Name'] ?? ''));
            $list[] = [
                'type' => 'plugin',
                'slug' => $this->cut($slug, 255),
                'name' => $this->cut($name !== '' ? $name : $slug, 255),
                'version' => $this->cut_or_null($data['Version'] ?? '', 50),
                'is_active' => (bool) is_plugin_active($path),
                'update_available' => isset($response[$path]),
                'latest_version' => $latest === null ? null : $this->cut($latest, 50),
            ];
        }
        return array_slice($list, 0, 1000);
    }

    private function collect_themes() {
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }
        $updates = get_site_transient('update_themes');
        $response = (is_object($updates) && isset($updates->response) && is_array($updates->response)) ? $updates->response : [];
        $active = get_stylesheet();
        $list = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $latest = null;
            if (isset($response[$slug])) {
                $info = $response[$slug];
                $latest = is_object($info) ? ($info->new_version ?? null) : (is_array($info) ? ($info['new_version'] ?? null) : null);
            }
            // pour un thème autonome, le « template » est le thème lui-même : seul un thème enfant a un parent
            $template = (string) $theme->get_template();
            $list[] = [
                'type' => 'theme',
                'slug' => $this->cut($slug, 255),
                'name' => $this->cut(wp_strip_all_tags((string) $theme->get('Name')), 255),
                'version' => $this->cut_or_null($theme->get('Version'), 50),
                'is_active' => ((string) $slug === (string) $active),
                'parent_theme' => ($template !== '' && $template !== (string) $slug) ? $this->cut($template, 255) : null,
                'update_available' => isset($response[$slug]),
                'latest_version' => $latest === null ? null : $this->cut($latest, 50),
            ];
        }
        return array_slice($list, 0, 200);
    }

    public function collect_inventory() {
        global $wpdb;
        $plugins = $this->collect_plugins();
        $themes = $this->collect_themes();
        $active_plugins = 0;
        foreach ($plugins as $p) {
            if ($p['is_active']) {
                $active_plugins++;
            }
        }
        return [
            // champs lus par SSM Core
            'cms_version' => $this->cut(get_bloginfo('version'), 50),
            'php_version' => $this->php_version(),
            'db_version' => $this->cut_or_null($this->get_db_version(), 50),
            'web_server' => isset($_SERVER['SERVER_SOFTWARE'])
                ? $this->cut_or_null(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])), 50) : null,
            'hostname' => function_exists('gethostname') ? $this->cut_or_null(gethostname(), 255) : null,
            'site_path' => $this->cut_or_null(ABSPATH, 500),
            'extensions' => $plugins,
            'themes' => $themes,
            // informations complémentaires (ignorées par SSM Core pour l'instant)
            'cms' => 'wordpress',
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'multisite' => is_multisite(),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'admin_email' => get_option('admin_email'),
            'active_theme' => get_stylesheet(),
            'plugin_count' => count($plugins),
            'plugin_active_count' => $active_plugins,
            'theme_count' => count($themes),
            'users_count' => isset($wpdb) ? (int) $wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->users}") : 0,
            'connector_version' => SSM_CONNECTOR_VERSION,
            'timestamp' => current_time('c'),
        ];
    }

    // === Envoi à SSM Core ===

    public function send_heartbeat() {
        $ok = false;
        try {
            $result = $this->post_to_ssm('/api/v1/heartbeat', $this->collect_inventory());
            if (isset($result['error'])) {
                $message = $result['error'];
            } else {
                $code = (int) $result['status_code'];
                $ok = $code >= 200 && $code < 300;
                $message = $ok ? 'Inventaire accepté par SSM Core.' : $this->describe_http_error($code, $result['body']);
            }
        } catch (\Throwable $e) {
            $message = 'Erreur interne : ' . $e->getMessage();
        }
        update_option(self::OPT_LAST_AT, current_time('mysql'), false);
        update_option(self::OPT_LAST_OK, $ok ? '1' : '0', false);
        update_option(self::OPT_LAST_MSG, $this->cut($message, 300), false);
        return $ok;
    }

    private function describe_http_error($code, $body) {
        if ($code === 401) {
            return 'Token refusé par SSM Core (401) : collez ici le token généré dans SSM (Sites, bouton 🔌).';
        }
        if ($code === 404) {
            return "Adresse introuvable (404) : vérifiez l'URL de SSM Core.";
        }
        if ($code >= 300 && $code < 400) {
            return 'Redirection (' . $code . ") : saisissez l'adresse finale de SSM Core (en https://).";
        }
        if ($code === 422) {
            $detail = '';
            if (is_array($body) && isset($body['detail']) && is_array($body['detail']) && isset($body['detail'][0]) && is_array($body['detail'][0])) {
                $first = $body['detail'][0];
                $loc = isset($first['loc']) && is_array($first['loc']) ? implode('.', array_map('strval', $first['loc'])) : '';
                $detail = trim($loc . ' ' . (isset($first['msg']) ? (string) $first['msg'] : ''));
            }
            return 'Données refusées par SSM Core (422)' . ($detail !== '' ? ' : ' . $detail : '') . '.';
        }
        if ($code >= 500) {
            return 'Erreur de SSM Core (' . $code . ').';
        }
        return 'Réponse inattendue de SSM Core (HTTP ' . $code . ').';
    }

    private function post_to_ssm($path, array $body) {
        $base = rtrim((string) get_option(SSM_CONNECTOR_OPTION_URL, ''), '/');
        if ($base === '') {
            return ['error' => "URL de SSM Core non configurée (Réglages > SSM Connector)."];
        }
        $token = (string) get_option(SSM_CONNECTOR_OPTION_TOKEN, '');
        if ($token === '') {
            return ['error' => 'Token non défini.'];
        }
        $events = get_option(self::OPT_EVENTS, []);
        if (!is_array($events)) {
            $events = [];
        }
        $body['pending_events'] = $events;
        $payload = wp_json_encode($body);
        if (!is_string($payload)) {
            return ['error' => "Inventaire impossible à encoder en JSON."];
        }
        $response = wp_remote_post($base . $path, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-SSM-Token' => $token,
                'X-SSM-Signature' => hash_hmac('sha256', $payload, $token),
            ],
            'body' => $payload,
            'timeout' => 15,
            'redirection' => 0, // le token ne doit pas suivre une redirection vers une autre adresse
        ]);
        if (is_wp_error($response)) {
            return ['error' => 'SSM Core injoignable : ' . $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 200 && $code < 300 && $events) {
            // les événements envoyés ne sont retirés qu'une fois acceptés ; ceux arrivés entre-temps restent
            $current = get_option(self::OPT_EVENTS, []);
            update_option(self::OPT_EVENTS, is_array($current) ? array_slice($current, count($events)) : [], false);
        }
        return ['status_code' => $code, 'body' => $data];
    }

    // === Réglages ===

    public function register_settings() {
        register_setting(SSM_CONNECTOR_SETTINGS_GROUP, SSM_CONNECTOR_OPTION_URL, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_url'],
            'default' => '',
        ]);
        register_setting(SSM_CONNECTOR_SETTINGS_GROUP, SSM_CONNECTOR_OPTION_TOKEN, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_token'],
            'default' => '',
        ]);
    }

    /** Adresse de SSM Core : http(s) uniquement, sans identifiants, sans « / » final. Vide = désactivé. */
    public function sanitize_url($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $url = esc_url_raw($value, ['http', 'https']);
        $parts = $url !== '' ? wp_parse_url($url) : false;
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            add_settings_error(SSM_CONNECTOR_OPTION_URL, 'ssm_url', "URL de SSM Core invalide : saisissez une adresse http(s) sans identifiant, par exemple https://ssm.exemple.fr.");
            return (string) get_option(SSM_CONNECTOR_OPTION_URL, '');
        }
        return rtrim($url, '/');
    }

    /** Token du site : celui généré par SSM (Sites, bouton 🔌). Vide = conserver l'actuel. */
    public function sanitize_token($value) {
        $old = (string) get_option(SSM_CONNECTOR_OPTION_TOKEN, '');
        $value = trim((string) $value);
        if ($value === '') {
            return $old;
        }
        if (!preg_match('/^[\x21-\x7E]{32,256}$/', $value)) {
            add_settings_error(SSM_CONNECTOR_OPTION_TOKEN, 'ssm_token', "Token invalide : 32 à 256 caractères, sans espace. Token non modifié.");
            return $old;
        }
        return $value;
    }

    public function register_admin_menu() {
        add_options_page('SSM Connector', 'SSM Connector', 'manage_options', SSM_CONNECTOR_PAGE, [$this, 'render_admin_page']);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.', '', ['response' => 403]);
        }
        $url = (string) get_option(SSM_CONNECTOR_OPTION_URL, '');
        $token = (string) get_option(SSM_CONNECTOR_OPTION_TOKEN, '');
        $hint = $token !== '' ? '•••• ' . substr($token, -4) : 'aucun';
        $last_at = get_option(self::OPT_LAST_AT);
        $last_ok = get_option(self::OPT_LAST_OK);
        $last_msg = (string) get_option(self::OPT_LAST_MSG, '');

        echo '<div class="wrap"><h1>SSM Connector</h1>';
        echo '<p>Version ' . esc_html(SSM_CONNECTOR_VERSION) . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields(SSM_CONNECTOR_SETTINGS_GROUP);
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="ssm_connector_url">URL de SSM Core</label></th><td>';
        echo '<input type="url" id="ssm_connector_url" name="ssm_connector_url" value="' . esc_attr($url)
            . '" class="regular-text" placeholder="https://ssm.exemple.fr" />';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="ssm_connector_token">Token du site</label></th><td>';
        echo '<input type="password" id="ssm_connector_token" name="ssm_connector_token" value="" '
            . 'autocomplete="new-password" class="regular-text" placeholder="Laisser vide pour conserver" />';
        echo '<p class="description">Token actuel : <code>' . esc_html($hint) . '</code>. '
            . 'Copiez-le depuis SSM (Sites, bouton 🔌 du site) : il n\'y est affiché qu\'une fois. '
            . 'Tant que vous n\'en collez pas, le connecteur envoie un token aléatoire que SSM ne connaît pas (erreur 401).</p>';
        echo '</td></tr></table>';
        submit_button('Enregistrer');
        echo '</form>';

        echo '<h2>État</h2>';
        if (!$last_at) {
            echo '<p>Aucun heartbeat envoyé pour l\'instant (un envoi par heure).</p>';
        } else {
            echo '<p><strong>' . ($last_ok === '1' ? 'OK' : 'Échec') . '</strong> — '
                . esc_html((string) $last_at) . ' — ' . esc_html($last_msg) . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ssm_connector_test" />';
        wp_nonce_field('ssm_connector_test');
        submit_button('Envoyer un heartbeat maintenant', 'secondary', 'submit', false);
        echo '</form></div>';
    }

    public function handle_test_request() {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.', '', ['response' => 403]);
        }
        check_admin_referer('ssm_connector_test');
        $this->send_heartbeat();
        wp_safe_redirect(admin_url('options-general.php?page=' . SSM_CONNECTOR_PAGE));
        exit;
    }

    // === Événements (mis en file, envoyés avec le prochain heartbeat) ===
    // SSM Core n'exploite pas encore ces événements : il les accepte et les ignore.

    public function on_update_complete($upgrader, $data) {
        $items = [];
        foreach (['plugins', 'themes', 'packages'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                $items = $data[$key];
                break;
            }
        }
        $this->queue_event('update', [
            'type' => $data['type'] ?? 'unknown',
            'action' => $data['action'] ?? 'update',
            'items' => $items,
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

    public function on_plugin_activated($plugin, $network_wide = false) {
        $this->queue_event('plugin_activated', ['plugin' => $plugin]);
    }

    public function on_plugin_deactivated($plugin, $network_wide = false) {
        $this->queue_event('plugin_deactivated', ['plugin' => $plugin]);
    }

    public function on_core_update_check() {
        $updates = get_site_transient('update_core');
        $count = is_object($updates) && !empty($updates->updates) ? count($updates->updates) : 0;
        $this->queue_event('update_check', ['core_updates' => $count]);
    }

    private function queue_event($type, $payload) {
        $events = get_option(self::OPT_EVENTS, []);
        if (!is_array($events)) {
            $events = [];
        }
        $events[] = ['type' => $type, 'payload' => $payload, 'timestamp' => current_time('c')];
        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -self::MAX_EVENTS);
        }
        update_option(self::OPT_EVENTS, $events, false);
    }
}

endif;

SSM_Connector::instance();

if (defined('WP_CLI') && WP_CLI) {
    class SSM_Connector_CLI {
        /** Affiche la configuration (le token n'est pas affiché en entier). */
        public function status($args, $assoc_args) {
            $token = (string) get_option(SSM_CONNECTOR_OPTION_TOKEN);
            WP_CLI::success('SSM Connector v' . SSM_CONNECTOR_VERSION);
            WP_CLI::line('Site URL : ' . get_site_url());
            WP_CLI::line('WP version : ' . get_bloginfo('version'));
            WP_CLI::line('PHP : ' . PHP_VERSION);
            WP_CLI::line('URL SSM Core : ' . (get_option(SSM_CONNECTOR_OPTION_URL) ?: 'NON DÉFINIE'));
            WP_CLI::line('Token défini : ' . ($token !== '' ? 'oui (se termine par ' . substr($token, -4) . ')' : 'NON'));
            WP_CLI::line('Dernier heartbeat : ' . (get_option(SSM_Connector::OPT_LAST_AT) ?: 'jamais')
                . ' — ' . (get_option(SSM_Connector::OPT_LAST_MSG) ?: ''));
        }

        /** Affiche le token du site (à ne pas partager). */
        public function token($args, $assoc_args) {
            WP_CLI::line(get_option(SSM_CONNECTOR_OPTION_TOKEN) ?: 'Aucun');
        }

        /** Envoie un heartbeat tout de suite et affiche le résultat. */
        public function heartbeat($args, $assoc_args) {
            $ok = SSM_Connector::instance()->send_heartbeat();
            $message = (string) get_option(SSM_Connector::OPT_LAST_MSG, '');
            if ($ok) {
                WP_CLI::success($message);
            } else {
                WP_CLI::error($message);
            }
        }
    }
    WP_CLI::add_command('ssm status', ['SSM_Connector_CLI', 'status']);
    WP_CLI::add_command('ssm token', ['SSM_Connector_CLI', 'token']);
    WP_CLI::add_command('ssm heartbeat', ['SSM_Connector_CLI', 'heartbeat']);
}
