<?php
// Faux appels WordPress, juste de quoi charger ssm-connector.php et l'exécuter sans WordPress.
// Les tests sur un vrai WordPress se font à part ; ceux-ci servent à repérer vite une erreur de code ou de contrat.

define('ABSPATH', sys_get_temp_dir() . '/ssm-fake-wp/');
define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/ssm-fake-wp-plugins/');
// `queue_result` est appele par les tests : on le rend accessible.
$GLOBALS['ssm_upgrade_ok'] = true;     // l'upgrader simulé réussit-il ?
$GLOBALS['ssm_writable'] = true;        // wp-content est-il inscriptible ?

$GLOBALS['ssm_opts'] = [];        // options
$GLOBALS['ssm_hooks'] = [];       // [hook => [callbacks]]
$GLOBALS['ssm_transients'] = [];  // transients
$GLOBALS['ssm_plugins'] = [];     // retour de get_plugins()
$GLOBALS['ssm_themes'] = [];      // retour de wp_get_themes()
$GLOBALS['ssm_http'] = null;      // function ($url, $args) : array|WP_Error simulant la réponse de SSM Core
$GLOBALS['ssm_http_calls'] = [];
$GLOBALS['ssm_settings_errors'] = [];
$GLOBALS['ssm_scheduled'] = [];
$GLOBALS['ssm_get'] = null;        // function ($url, $args) : array|WP_Error simulant l'API GitHub
$GLOBALS['ssm_get_calls'] = [];
$GLOBALS['ssm_transient_ttl'] = [];
$GLOBALS['ssm_download'] = null;   // function ($url) : string (chemin) | WP_Error
$GLOBALS['ssm_salt'] = 'salt-A';        // clé de sécurité de WordPress
$GLOBALS['ssm_can'] = true;           // l'utilisateur courant peut manage_options / update_plugins
$GLOBALS['ssm_screen'] = null;        // identifiant de l'écran d'administration courant
$GLOBALS['ssm_disk_version'] = null; // version écrite dans le fichier sur disque (null = celle du fichier réel)

function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['ssm_hooks'][$hook][] = $cb; }
function add_filter($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['ssm_hooks'][$hook][] = $cb; }
function plugin_basename($file) { return 'ssm-connector/ssm-connector.php'; }
function plugins_url($path, $plugin) { return 'https://exemple.test/wp-content/plugins/ssm-connector/' . $path; }
function set_site_transient($key, $value, $ttl = 0) { $GLOBALS['ssm_transients'][$key] = $value; $GLOBALS['ssm_transient_ttl'][$key] = $ttl; return true; }
function delete_site_transient($key) { unset($GLOBALS['ssm_transients'][$key]); return true; }
function wp_salt($scheme = 'auth') { return $GLOBALS['ssm_salt']; }
function current_user_can($cap) { return $GLOBALS['ssm_can']; }
function get_current_screen() { return $GLOBALS['ssm_screen'] === null ? null : (object) ['id' => $GLOBALS['ssm_screen']]; }
function admin_url($path = '') { return 'https://exemple.test/wp-admin/' . $path; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '" />'; }
function submit_button($text = '', $type = 'primary', $name = 'submit', $wrap = true) { echo '<button type="submit" class="' . $type . '">' . $text . '</button>'; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_die($message = '', $title = '', $args = []) { throw new RuntimeException('wp_die: ' . $message); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function wp_date($format, $ts = null) { return date($format, $ts ?? time()); }
function wp_remote_get($url, $args = []) {
    $GLOBALS['ssm_get_calls'][] = ['url' => $url, 'args' => $args];
    return call_user_func($GLOBALS['ssm_get'], $url, $args);
}
function get_file_data($file, $headers) {
    if ($GLOBALS['ssm_disk_version'] !== null) {
        return ['Version' => $GLOBALS['ssm_disk_version']];
    }
    preg_match('/^ \* Version: *(.+)$/m', file_get_contents($file), $m);
    return ['Version' => isset($m[1]) ? trim($m[1]) : ''];
}
function download_url($url, $timeout = 300) { return call_user_func($GLOBALS['ssm_download'], $url); }
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

// --- Mise a jour d'extension simulee ---
// Le vrai Plugin_Upgrader télécharge un paquet et le décompresse. Ici on ne simule que ce qui
// décide du résultat : réussit ou échoue. La relecture de la version se fait sur le disque
// ($GLOBALS['ssm_disk_version']), ce qui permet de vérifier qu'on ne croit pas l'upgrader sur parole.
class Automatic_Upgrader_Skin {}
class Plugin_Upgrader {
    public function __construct($skin = null) {}
    public function upgrade($plugin) {
        if (!$GLOBALS['ssm_upgrade_ok']) {
            return new WP_Error('upgrader', 'échec simulé du paquet.');
        }
        $GLOBALS['ssm_upgraded'][] = $plugin;
        return true;
    }
}
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

class FakeFs {
    public $moves = [];
    public $ok = true;
    public function move($from, $to, $overwrite = false) { $this->moves[] = [$from, $to]; return $this->ok; }
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

/** Supprime un dossier et son contenu (les sauvegardes de test). */
function ssm_rmtree($dir) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? ssm_rmtree($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** Réinitialise l'état entre deux tests. */
function ssm_reset() {
    $GLOBALS['ssm_opts'] = [];
    $GLOBALS['ssm_transients'] = [];
    $GLOBALS['ssm_http'] = null;
    $GLOBALS['ssm_http_calls'] = [];
    $GLOBALS['ssm_settings_errors'] = [];
    $GLOBALS['ssm_scheduled'] = [];
    $GLOBALS['ssm_get'] = null;
    $GLOBALS['ssm_get_calls'] = [];
    $GLOBALS['ssm_transient_ttl'] = [];
    $GLOBALS['ssm_download'] = null;
    $GLOBALS['ssm_disk_version'] = null;
    $GLOBALS['ssm_salt'] = 'salt-A';
    $GLOBALS['ssm_can'] = true;
    $GLOBALS['ssm_screen'] = null;
    $GLOBALS['ssm_upgrade_ok'] = true;
    $GLOBALS['ssm_upgraded'] = [];
    $GLOBALS['ssm_writable'] = true;   // disque inscriptible par defaut
    // `is_writable` est un builtin et se comporte autrement sous root : on passe par la couture
    // du connecteur pour simuler un disque non inscriptible.
    SSM_Connector::$disk_check = $GLOBALS['ssm_writable']
        ? null : function ($path) { return false; };
    $GLOBALS['wp_filesystem'] = new FakeFs();
    // Un dossier d'extension minimal, pour que la sauvegarde et la restauration aient de vrai
    // fichiers a copier. Le contenu importe peu ; ce qui compte est qu'un tree existe.
    $plugins = WP_PLUGIN_DIR;
    ssm_rmtree($plugins);
    mkdir($plugins, 0755, true);
    foreach (['akismet', 'woocommerce', 'inactive-demo'] as $slug) {
        mkdir($plugins . '/' . $slug, 0755, true);
        file_put_contents($plugins . '/' . $slug . '/' . $slug . '.php', "<?php\n/* Version: 1.0 */\n");
        file_put_contents($plugins . '/' . $slug . '/index.php', "<?php\n");
    }
    mkdir($plugins . '/ssm-backups', 0755, true);
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
