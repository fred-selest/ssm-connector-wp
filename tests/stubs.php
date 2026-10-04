<?php
// Faux appels WordPress, juste de quoi charger ssm-connector.php et l'exécuter sans WordPress.
// Les tests sur un vrai WordPress se font à part ; ceux-ci servent à repérer vite une erreur de code ou de contrat.

define('ABSPATH', sys_get_temp_dir() . '/ssm-fake-wp/');

$GLOBALS['ssm_opts'] = [];        // options
$GLOBALS['ssm_hooks'] = [];       // [hook => [callbacks]]
$GLOBALS['ssm_transients'] = [];  // transients
$GLOBALS['ssm_plugins'] = [];     // retour de get_plugins()
$GLOBALS['ssm_themes'] = [];      // retour de wp_get_themes()
$GLOBALS['ssm_http'] = null;      // function ($url, $args) : array|WP_Error simulant la réponse de SSM Core
$GLOBALS['ssm_http_calls'] = [];
$GLOBALS['ssm_settings_errors'] = [];
$GLOBALS['ssm_scheduled'] = [];

function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['ssm_hooks'][$hook][] = $cb; }
function register_activation_hook($file, $cb) {}
function register_deactivation_hook($file, $cb) {}
function register_setting($group, $name, $args = []) { $GLOBALS['ssm_registered'][$name] = $args; }
function add_settings_error($setting, $code, $message, $type = 'error') { $GLOBALS['ssm_settings_errors'][] = $code; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['ssm_opts']) ? $GLOBALS['ssm_opts'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['ssm_opts'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['ssm_opts'][$key]); return true; }
function get_site_transient($key) { return array_key_exists($key, $GLOBALS['ssm_transients']) ? $GLOBALS['ssm_transients'][$key] : false; }
function get_plugins() { return $GLOBALS['ssm_plugins']; }
function is_plugin_active($path) { return strpos($path, 'inactive') === false; }
function wp_get_themes() { return $GLOBALS['ssm_themes']; }
function get_stylesheet() { return 'child'; }
function get_bloginfo($what) { return '6.6.1'; }
function is_multisite() { return false; }
function is_ssl() { return true; }
function get_site_url() { return 'https://exemple.test'; }
function get_home_url() { return 'https://exemple.test'; }
function current_time($type) { return $type === 'mysql' ? '2026-10-04 21:00:00' : '2026-10-04T21:00:00+00:00'; }
function wp_next_scheduled($hook) { return $GLOBALS['ssm_scheduled'][$hook] ?? false; }
function wp_schedule_event($ts, $recurrence, $hook) { $GLOBALS['ssm_scheduled'][$hook] = $ts; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['ssm_scheduled'][$hook]); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($s) { return $s; }
function wp_json_encode($data) { return json_encode($data); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url, $protocols = null) {
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($protocols !== null && !in_array($scheme, $protocols, true)) {
        return '';
    }
    return $url;
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_remote_post($url, $args) {
    $GLOBALS['ssm_http_calls'][] = ['url' => $url, 'args' => $args];
    return call_user_func($GLOBALS['ssm_http'], $url, $args);
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
}

class FakeWpdb {
    public $users = 'wp_users';
    public function db_version() { return '8.0.36'; }
    public function get_var($sql) { return '7'; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

class FakeTheme {
    private $d;
    public function __construct($name, $version, $template) { $this->d = ['Name' => $name, 'Version' => $version]; $this->template = $template; }
    public $template;
    public function get($k) { return $this->d[$k]; }   // comme WordPress, get('Template') n'est pas utilisé ici : on passe par get_template()
    public function get_template() { return $this->template; }
}

class FakeRequest {
    private $headers;
    private $params;
    public function __construct(array $headers = [], array $params = []) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->params = $params;
    }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? null; }
    public function get_param($name) { return $this->params[$name] ?? null; }
}

/** Réinitialise l'état entre deux tests. */
function ssm_reset() {
    $GLOBALS['ssm_opts'] = [];
    $GLOBALS['ssm_transients'] = [];
    $GLOBALS['ssm_http'] = null;
    $GLOBALS['ssm_http_calls'] = [];
    $GLOBALS['ssm_settings_errors'] = [];
    $GLOBALS['ssm_scheduled'] = [];
    $GLOBALS['ssm_plugins'] = [
        'akismet/akismet.php' => ['Name' => 'Akismet Anti-spam', 'Version' => '5.3'],
        'woocommerce/woocommerce.php' => ['Name' => 'WooCommerce', 'Version' => '9.1.2'],
        'inactive-demo/inactive-demo.php' => ['Name' => 'Démo inactive', 'Version' => '1.0'],
        'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7.2'],
    ];
    $GLOBALS['ssm_themes'] = [
        'twentytwentyfour' => new FakeTheme('Twenty Twenty-Four', '1.2', 'twentytwentyfour'),
        'child' => new FakeTheme('Mon thème enfant', '0.5', 'twentytwentyfour'),
    ];
}
