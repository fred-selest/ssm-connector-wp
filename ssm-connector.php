<?php
/**
 * Plugin Name: SSM Connector
 * Description: Connecteur SSM (Selest Site Manager) : envoie toutes les heures l'inventaire du site (WordPress, PHP, extensions, thèmes) à SSM Core. N'ouvre aucune porte sur le site. Se met à jour depuis les releases GitHub.
 * Version: 0.3.0
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

define('SSM_CONNECTOR_VERSION', '0.3.0');
define('SSM_CONNECTOR_FILE', __FILE__);
define('SSM_CONNECTOR_PAGE', 'ssm-connector');

// Déclaration conditionnelle : PHP déclare d'avance les classes « simples », avant même d'exécuter le `return` ci-dessus.
// Sans ce `if`, charger le fichier une 2e fois (copie dans mu-plugins ET dans plugins) provoquerait « Cannot declare class ».
if (!class_exists('SSM_Connector', false)) :

/**
 * Envoie l'inventaire du site à SSM Core. Sortant uniquement : l'extension n'expose aucune route et n'écoute rien.
 *
 * Réglages : adresse de SSM Core et token du site (celui que SSM affiche une seule fois dans Sites › 🔌).
 * Ils peuvent aussi être fixés dans wp-config.php (SSM_CONNECTOR_URL, SSM_CONNECTOR_TOKEN) : le token n'est alors
 * jamais écrit dans la base de données.
 */
class SSM_Connector {
    const HEARTBEAT_HOOK = 'ssm_heartbeat_event';
    const OPT_URL = 'ssm_connector_url';
    const OPT_TOKEN = 'ssm_connector_token';
    const OPT_LAST_AT = 'ssm_last_heartbeat_at';
    const OPT_LAST_OK = 'ssm_last_heartbeat_ok';
    const OPT_LAST_MSG = 'ssm_last_heartbeat_message';
    const OPT_SCHEMA = 'ssm_connector_schema';
    const SCHEMA = '2';
    const ENC_PREFIX = 'enc:v1:';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_notices', [$this, 'maybe_notice']);
        add_action('admin_post_ssm_connector_connect', [$this, 'handle_connect_request']);
        add_action('admin_post_ssm_connector_test', [$this, 'handle_test_request']);
        add_action('wp_loaded', [$this, 'on_loaded']);
        add_action(self::HEARTBEAT_HOOK, [$this, 'send_heartbeat']);
        add_filter('plugin_action_links_' . plugin_basename(SSM_CONNECTOR_FILE), [$this, 'action_links']);
        register_activation_hook(SSM_CONNECTOR_FILE, [$this, 'activate']);
        register_deactivation_hook(SSM_CONNECTOR_FILE, [$this, 'deactivate']);
    }

    // === Activation, migration, planification ===

    public function activate() {
        $this->maybe_upgrade();
        $this->schedule_heartbeat();
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::HEARTBEAT_HOOK);
    }

    public function on_loaded() {
        $this->maybe_upgrade();
        $this->schedule_heartbeat();
    }

    public function schedule_heartbeat() {
        if (!wp_next_scheduled(self::HEARTBEAT_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HEARTBEAT_HOOK);
        }
    }

    /** Une seule fois après une mise à jour depuis la 0.2.x : chiffre le token enregistré en clair, efface l'ancienne file d'événements. */
    public function maybe_upgrade() {
        if (get_option(self::OPT_SCHEMA) === self::SCHEMA) {
            return;
        }
        $stored = get_option(self::OPT_TOKEN);
        if (is_string($stored) && $stored !== '' && strpos($stored, self::ENC_PREFIX) !== 0 && self::can_encrypt()) {
            update_option(self::OPT_TOKEN, self::protect($stored), false);
        }
        delete_option('ssm_pending_events');
        update_option(self::OPT_SCHEMA, self::SCHEMA, false);
    }

    /** Adresses des icônes livrées dans assets/ (absentes en mu-plugin : le fichier est alors seul). */
    public static function icon_urls() {
        $dir = dirname(SSM_CONNECTOR_FILE) . '/assets/';
        $icons = [];
        foreach (['1x' => 'icon-128x128.png', '2x' => 'icon-256x256.png', 'svg' => 'icon.svg'] as $key => $file) {
            if (is_file($dir . $file)) {
                $icons[$key] = plugins_url('assets/' . $file, SSM_CONNECTOR_FILE);
            }
        }
        return $icons;
    }

    // === Chiffrement du token au repos ===
    // Clé dérivée des clés de sécurité de WordPress (wp-config.php). Protège le token si seule la base de données
    // fuite (sauvegarde, injection SQL en lecture) ; ne protège pas d'un attaquant qui lit aussi wp-config.php.
    // Si ces clés changent, le token devient illisible : il suffit de le saisir à nouveau.

    public static function can_encrypt() {
        return function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open') && function_exists('random_bytes');
    }

    private static function key() {
        return hash('sha256', 'ssm-connector|' . wp_salt('auth'), true);
    }

    /** Token → valeur stockée (chiffrée si possible, sinon telle quelle). */
    public static function protect($plain) {
        if (!self::can_encrypt()) {
            return $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::ENC_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** Valeur stockée → token, ou null si illisible. Une valeur sans préfixe est un ancien token en clair. */
    public static function reveal($stored) {
        $stored = (string) $stored;
        if (strpos($stored, self::ENC_PREFIX) !== 0) {
            return $stored;
        }
        if (!self::can_encrypt()) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        );
        return $plain === false ? null : $plain;
    }

    // === Contrôle de ce que l'utilisateur colle ===

    /** Adresse de SSM Core → origine propre (https://hôte[:port]) ; l'adresse copiée depuis le navigateur est acceptée telle quelle. */
    public static function normalize_url($raw) {
        $raw = trim((string) $raw, " \t\n\r\0\x0B\"'`<>");
        if ($raw === '') {
            return ['ok' => false, 'error' => "saisissez l'adresse de SSM Core, par exemple https://ssm.exemple.fr."];
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = 'https://' . ltrim($raw, '/');
        }
        $parts = wp_parse_url($raw);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'adresse illisible.'];
        }
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['ok' => false, 'error' => 'seules les adresses http(s) sont acceptées.'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'error' => "n'incluez ni identifiant ni mot de passe dans l'adresse."];
        }
        $host = strtolower($parts['host']);
        if (!preg_match('/^[a-z0-9.\-\[\]:]+$/D', $host)) {
            return ['ok' => false, 'error' => "l'adresse contient des caractères non pris en charge."];
        }
        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return ['ok' => false, 'error' => 'numéro de port invalide.'];
        }
        if ($scheme === 'http' && !self::is_private_host($host)) {
            return ['ok' => false, 'error' => "utilisez https:// : le token ne doit pas circuler en clair sur Internet."];
        }
        return [
            'ok' => true,
            'url' => $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : ''),
            'insecure' => $scheme === 'http',
        ];
    }

    /** Réseau privé ou machine locale : le seul cas où http:// est toléré. */
    public static function is_private_host($host) {
        $h = trim(strtolower((string) $host), '[]');
        if ($h === 'localhost' || $h === '::1') {
            return true;
        }
        if (filter_var($h, FILTER_VALIDATE_IP)) {
            return filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        if (strpos($h, '.') === false) {
            return true;   // nom de machine seul (par exemple « ssm » sur un réseau local)
        }
        return (bool) preg_match('/\.(local|lan|internal|localhost|home\.arpa)$/D', $h);
    }

    /** Token collé → token propre : espaces, retours à la ligne, guillemets et préfixes « Bearer » / « X-SSM-Token: » retirés. */
    public static function normalize_token($raw) {
        $token = preg_replace('/^\s*(x-ssm-token\s*:|authorization\s*:\s*bearer|bearer)\s*/i', '', (string) $raw);
        $token = trim((string) preg_replace('/\s+/', '', $token), "\"'`<>");
        if ($token === '') {
            return ['ok' => false, 'error' => 'collez le token du site (dans SSM : Sites, bouton 🔌).'];
        }
        if (!preg_match('/^[\x21-\x7E]{32,256}$/D', $token)) {
            return ['ok' => false, 'error' => 'token invalide : 32 à 256 caractères, sans espace. Recopiez-le en entier depuis SSM.'];
        }
        return ['ok' => true, 'token' => $token];
    }

    // === Configuration ===

    /**
     * Configuration effective : constantes de wp-config.php en priorité, sinon base de données.
     * ['url', 'url_source', 'token', 'token_source', 'problem'] ; 'problem' explique pourquoi on ne peut pas envoyer.
     */
    public function config() {
        $cfg = ['url' => '', 'url_source' => 'none', 'token' => '', 'token_source' => 'none', 'problem' => ''];

        if (defined('SSM_CONNECTOR_URL') && (string) SSM_CONNECTOR_URL !== '') {
            $cfg['url_source'] = 'constant';
            $raw = (string) SSM_CONNECTOR_URL;
        } else {
            $raw = (string) get_option(self::OPT_URL, '');
            if ($raw !== '') {
                $cfg['url_source'] = 'database';
            }
        }
        if ($raw !== '') {
            $u = self::normalize_url($raw);
            if ($u['ok']) {
                $cfg['url'] = $u['url'];
            } else {
                $cfg['problem'] = "Adresse de SSM Core invalide dans les réglages : " . $u['error'];
            }
        }

        if (defined('SSM_CONNECTOR_TOKEN') && (string) SSM_CONNECTOR_TOKEN !== '') {
            $cfg['token_source'] = 'constant';
            $t = self::normalize_token((string) SSM_CONNECTOR_TOKEN);
            if ($t['ok']) {
                $cfg['token'] = $t['token'];
            } elseif ($cfg['problem'] === '') {
                $cfg['problem'] = 'SSM_CONNECTOR_TOKEN (wp-config.php) est invalide : ' . $t['error'];
            }
        } else {
            $stored = get_option(self::OPT_TOKEN, '');
            if (is_string($stored) && $stored !== '') {
                $cfg['token_source'] = 'database';
                $plain = self::reveal($stored);
                if ($plain === null) {
                    if ($cfg['problem'] === '') {
                        $cfg['problem'] = 'Le token enregistré est illisible (les clés de sécurité de WordPress ont changé) : saisissez-le à nouveau.';
                    }
                } else {
                    $cfg['token'] = $plain;
                }
            }
        }
        return $cfg;
    }

    public function is_configured() {
        $cfg = $this->config();
        return $cfg['url'] !== '' && $cfg['token'] !== '';
    }

    /** Enregistre (si besoin), envoie l'inventaire tout de suite et dit si ça marche. Vide = conserver l'actuel. */
    public function connect($url_raw, $token_raw = '') {
        $cfg = $this->config();

        $new_url = null;
        if ($cfg['url_source'] !== 'constant') {
            $u = self::normalize_url(trim((string) $url_raw) !== '' ? $url_raw : $cfg['url']);
            if (!$u['ok']) {
                return $this->record(false, 'Réglages non enregistrés : ' . $u['error']);
            }
            $new_url = $u['url'];
        }

        $new_token = null;
        if ($cfg['token_source'] !== 'constant') {
            if (trim((string) $token_raw) !== '') {
                $t = self::normalize_token($token_raw);
                if (!$t['ok']) {
                    return $this->record(false, 'Réglages non enregistrés : ' . $t['error']);
                }
                $new_token = $t['token'];
            } elseif ($cfg['token'] === '') {
                return $this->record(false, 'Réglages non enregistrés : collez le token du site (dans SSM : Sites, bouton 🔌).');
            }
        }

        if ($new_url !== null) {
            update_option(self::OPT_URL, $new_url, false);
        }
        if ($new_token !== null) {
            update_option(self::OPT_TOKEN, self::protect($new_token), false);
        }

        $ok = $this->send_heartbeat();
        if (!$ok) {
            update_option(self::OPT_LAST_MSG, $this->cut('Réglages enregistrés, mais la connexion a échoué : ' . get_option(self::OPT_LAST_MSG, ''), 300), false);
        }
        return ['ok' => $ok, 'message' => (string) get_option(self::OPT_LAST_MSG, '')];
    }

    private function record($ok, $message) {
        update_option(self::OPT_LAST_AT, current_time('mysql'), false);
        update_option(self::OPT_LAST_OK, $ok ? '1' : '0', false);
        update_option(self::OPT_LAST_MSG, $this->cut($message, 300), false);
        return ['ok' => (bool) $ok, 'message' => $this->cut($message, 300)];
    }

    // === Inventaire ===
    // Seulement ce que SSM Core lit. Les longueurs maximales sont celles de SSM Core (app/schemas.py) :
    // au-delà, Core refuse tout le heartbeat (422).

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
                'slug' => $this->cut($slug, 255),
                'name' => $this->cut(wp_strip_all_tags((string) $theme->get('Name')), 255),
                'version' => $this->cut_or_null($theme->get('Version'), 50),
                'is_active' => ((string) $slug === (string) $active),
                'parent_theme' => ($template !== '' && $template !== (string) $slug) ? $this->cut($template, 255) : null,
                'latest_version' => $latest === null ? null : $this->cut($latest, 50),
            ];
        }
        return array_slice($list, 0, 200);
    }

    public function collect_inventory() {
        return [
            'cms' => 'wordpress',
            'cms_version' => $this->cut(get_bloginfo('version'), 50),
            'php_version' => $this->php_version(),
            'db_version' => $this->cut_or_null($this->get_db_version(), 50),
            'web_server' => isset($_SERVER['SERVER_SOFTWARE'])
                ? $this->cut_or_null(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])), 50) : null,
            'hostname' => function_exists('gethostname') ? $this->cut_or_null(gethostname(), 255) : null,
            'site_path' => $this->cut_or_null(ABSPATH, 500),
            'extensions' => $this->collect_plugins(),
            'themes' => $this->collect_themes(),
            'connector_version' => SSM_CONNECTOR_VERSION,
        ];
    }

    // === Envoi à SSM Core ===

    /** Envoie l'inventaire et mémorise le résultat (affiché dans les réglages). Renvoie true si SSM Core l'a accepté. */
    public function send_heartbeat() {
        $cfg = $this->config();
        if ($cfg['problem'] !== '') {
            return $this->record(false, $cfg['problem'])['ok'];
        }
        if ($cfg['url'] === '' || $cfg['token'] === '') {
            // Pas encore connecté : ce n'est pas une panne (l'envoi horaire tourne dès l'activation). Rien n'est enregistré,
            // pour que la page de réglages montre le guide plutôt qu'un faux « Échec ».
            return false;
        }
        $ok = false;
        try {
            $result = $this->post_to_ssm($cfg['url'] . '/api/v1/heartbeat', $cfg['token'], $this->collect_inventory());
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
        $this->record($ok, $message);
        return $ok;
    }

    private function describe_http_error($code, $body) {
        if ($code === 401) {
            return 'Token refusé par SSM Core (401) : collez le token généré dans SSM (Sites, bouton 🔌). Un nouveau token invalide l\'ancien.';
        }
        if ($code === 403) {
            return "Accès refusé (403) : un pare-feu ou un filtre devant SSM Core bloque peut-être ce site.";
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

    private function post_to_ssm($url, $token, array $body) {
        $payload = wp_json_encode($body);
        if (!is_string($payload)) {
            return ['error' => "Inventaire impossible à encoder en JSON."];
        }
        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json', 'X-SSM-Token' => $token],
            'body' => $payload,
            'timeout' => 15,
            'redirection' => 0, // le token ne doit pas suivre une redirection vers une autre adresse
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            return ['error' => 'SSM Core injoignable : ' . $response->get_error_message()];
        }
        return [
            'status_code' => (int) wp_remote_retrieve_response_code($response),
            'body' => json_decode((string) wp_remote_retrieve_body($response), true),
        ];
    }

    // === Administration ===

    public function register_admin_menu() {
        add_options_page('SSM Connector', 'SSM Connector', 'manage_options', SSM_CONNECTOR_PAGE, [$this, 'render_admin_page']);
    }

    public function action_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=' . SSM_CONNECTOR_PAGE)) . '">Réglages</a>');
        return $links;
    }

    /** Rappel sur le Tableau de bord et la liste des extensions tant que le site n'est pas connecté. */
    public function maybe_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['plugins', 'dashboard'], true) || $this->is_configured()) {
            return;
        }
        echo '<div class="notice notice-warning is-dismissible"><p><strong>SSM Connector</strong> est activé mais ce site n\'est pas encore connecté à SSM. '
            . '<a href="' . esc_url(admin_url('options-general.php?page=' . SSM_CONNECTOR_PAGE)) . '">Configurer maintenant</a></p></div>';
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.', '', ['response' => 403]);
        }
        $cfg = $this->config();
        $configured = $cfg['url'] !== '' && $cfg['token'] !== '';
        $last_at = get_option(self::OPT_LAST_AT);
        $last_ok = get_option(self::OPT_LAST_OK);
        $last_msg = (string) get_option(self::OPT_LAST_MSG, '');
        $icons = self::icon_urls();

        echo '<div class="wrap"><h1>';
        if (!empty($icons['1x'])) {
            echo '<img src="' . esc_url($icons['1x']) . '" alt="" width="40" height="40" style="vertical-align:middle;margin-right:10px;border-radius:9px" />';
        }
        echo 'SSM Connector</h1>';
        echo '<p>Version ' . esc_html(SSM_CONNECTOR_VERSION) . '</p>';

        // --- État ---
        if ($cfg['problem'] !== '') {
            echo '<div class="notice notice-error inline"><p><strong>✘ Configuration à corriger</strong> — ' . esc_html($cfg['problem']) . '</p></div>';
        } elseif (!$last_at && !$configured) {
            echo '<div class="notice notice-warning inline"><p><strong>○ Pas encore connecté.</strong></p><ol>'
                . '<li>Dans SSM, ouvrez <em>Sites</em>, cliquez sur 🔌 à côté du site et copiez le <strong>token</strong> (il n\'est affiché qu\'une fois).</li>'
                . '<li>Collez-le ci-dessous, avec l\'adresse de SSM.</li>'
                . '<li>Cliquez sur <strong>Connecter</strong> : la connexion est testée aussitôt.</li></ol></div>';
        } elseif (!$last_at) {
            echo '<div class="notice notice-info inline"><p><strong>○ Configuré, aucun envoi pour l\'instant.</strong> Cliquez sur « Tester la connexion » (sinon, premier envoi dans l\'heure).</p></div>';
        } elseif ($last_ok === '1') {
            echo '<div class="notice notice-success inline"><p><strong>✔ Connecté</strong> — dernier envoi le ' . esc_html((string) $last_at) . ' — ' . esc_html($last_msg) . '</p></div>';
        } else {
            echo '<div class="notice notice-error inline"><p><strong>✘ Échec</strong> — ' . esc_html((string) $last_at) . ' — ' . esc_html($last_msg) . '</p></div>';
        }

        // --- Connexion ---
        echo '<h2>Connexion à SSM</h2>';
        $url_locked = $cfg['url_source'] === 'constant';
        $token_locked = $cfg['token_source'] === 'constant';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ssm_connector_connect" />';
        wp_nonce_field('ssm_connector_connect');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="ssm_url">Adresse de SSM</label></th><td>';
        echo '<input type="text" id="ssm_url" name="ssm_url" value="' . esc_attr($cfg['url']) . '" class="regular-text" '
            . 'placeholder="https://ssm.exemple.fr" autocomplete="off"' . ($url_locked ? ' disabled' : '') . ' />';
        echo $url_locked ? '<p class="description">Définie dans wp-config.php (<code>SSM_CONNECTOR_URL</code>).</p>'
            : '<p class="description">Copiez-la depuis la barre d\'adresse de votre navigateur : seule la partie « https://nom-de-domaine » est conservée.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="ssm_token">Token du site</label></th><td>';
        $hint = $cfg['token'] !== '' ? '•••• ' . substr($cfg['token'], -4) : '';
        echo '<input type="password" id="ssm_token" name="ssm_token" value="" class="regular-text" autocomplete="new-password" '
            . 'placeholder="' . esc_attr($token_locked ? 'défini dans wp-config.php' : ($hint !== '' ? $hint . ' — laisser vide pour le conserver' : 'collez le token ici')) . '"'
            . ($token_locked ? ' disabled' : '') . ' />';
        echo $token_locked ? '<p class="description">Défini dans wp-config.php (<code>SSM_CONNECTOR_TOKEN</code>) : il n\'est pas enregistré dans la base de données.</p>'
            : '<p class="description">Dans SSM : <em>Sites</em>, bouton 🔌 du site. Un nouveau token invalide l\'ancien.</p>';
        echo '</td></tr></table>';
        if (!($url_locked && $token_locked)) {
            submit_button('Connecter', 'primary', 'submit', false);
            echo ' ';
        }
        echo '</form>';

        if ($configured) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-top:8px">';
            echo '<input type="hidden" name="action" value="ssm_connector_test" />';
            wp_nonce_field('ssm_connector_test');
            submit_button('Tester la connexion', 'secondary', 'submit', false);
            echo '</form>';
        }

        // --- Sécurité ---
        echo '<h2>Sécurité</h2><ul style="list-style:disc;margin-left:20px">';
        echo '<li>Cette extension <strong>n\'ouvre aucune porte</strong> sur le site : elle envoie l\'inventaire à SSM, elle n\'écoute rien.</li>';
        if ($cfg['token_source'] === 'constant') {
            echo '<li>Token : défini dans <code>wp-config.php</code>, jamais écrit dans la base de données.</li>';
        } elseif ($cfg['token_source'] === 'database') {
            echo '<li>Token : ' . (self::can_encrypt() ? 'chiffré dans la base de données (clé dérivée des clés de sécurité de WordPress).'
                : '<strong>stocké en clair</strong> (libsodium indisponible sur ce serveur). Préférez <code>SSM_CONNECTOR_TOKEN</code> dans wp-config.php.') . '</li>';
        }
        if ($cfg['url'] !== '') {
            echo strpos($cfg['url'], 'https://') === 0
                ? '<li>Connexion chiffrée (https), certificat vérifié.</li>'
                : '<li><strong>Connexion en http</strong> sur un réseau privé : acceptable en interne, déconseillé sinon.</li>';
        }
        echo '<li>Envoyé à SSM : versions de WordPress, de PHP et de la base, serveur web, nom de la machine, chemin d\'installation, extensions et thèmes (versions, état, mises à jour). Rien d\'autre : ni utilisateurs, ni contenu, ni e-mails.</li>';
        echo '</ul>';

        $this->render_update_section();
        echo '</div>';
    }

    private function render_update_section() {
        echo '<h2>Mises à jour</h2>';
        $updater = SSM_Connector_Updater::current();
        if ($updater === null) {
            echo '<p>Indisponibles pour cette installation (fichier seul dans mu-plugins, ou désactivées dans wp-config.php).</p>';
            return;
        }
        $state = $updater->cached_state();
        if (!$state) {
            echo '<p>Aucune vérification pour l\'instant (WordPress en fait une deux fois par jour).</p>';
        } else {
            $when = esc_html(wp_date('Y-m-d H:i', (int) $state['at']));
            if (!$state['ok']) {
                echo '<p><strong>Vérification impossible</strong> (' . $when . ') : ' . esc_html((string) $state['error']) . '</p>';
            } elseif (version_compare($state['release']['version'], SSM_CONNECTOR_VERSION, '>')) {
                echo '<p><strong>Version ' . esc_html($state['release']['version']) . ' disponible</strong> (vous avez ' . esc_html(SSM_CONNECTOR_VERSION)
                    . '). <a href="' . esc_url(admin_url('plugins.php')) . '">Installer depuis Extensions</a>';
                if (!empty($state['release']['url'])) {
                    echo ' — <a href="' . esc_url($state['release']['url']) . '" target="_blank" rel="noopener noreferrer">notes de version</a>';
                }
                echo '.</p>';
            } else {
                echo '<p><strong>À jour</strong> (version ' . esc_html(SSM_CONNECTOR_VERSION) . ', vérifié le ' . $when . ').</p>';
            }
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ssm_connector_check_update" />';
        wp_nonce_field('ssm_connector_check_update');
        submit_button('Rechercher une mise à jour', 'secondary', 'submit', false);
        echo '</form>';
        echo '<p class="description">Pour installer automatiquement les nouvelles versions : Extensions › SSM Connector › « Activer les mises à jour automatiques ».</p>';
    }

    public function handle_connect_request() {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.', '', ['response' => 403]);
        }
        check_admin_referer('ssm_connector_connect');
        $url = isset($_POST['ssm_url']) ? wp_unslash($_POST['ssm_url']) : '';
        $token = isset($_POST['ssm_token']) ? wp_unslash($_POST['ssm_token']) : '';
        $this->connect($url, $token);
        wp_safe_redirect(admin_url('options-general.php?page=' . SSM_CONNECTOR_PAGE));
        exit;
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
}

endif;

if (!class_exists('SSM_Connector_Updater', false)) :

/**
 * Mises à jour de l'extension depuis les releases GitHub du dépôt (public : aucun jeton n'est stocké sur les sites).
 * Branché sur le mécanisme de WordPress : écran Extensions, Tableau de bord › Mises à jour, `wp plugin update`,
 * mises à jour automatiques. Rien n'est installé sans action de l'administrateur, sauf s'il active les mises à jour
 * automatiques pour cette extension.
 */
class SSM_Connector_Updater {
    const REPO = 'fred-selest/ssm-connector-wp';
    const ASSET = 'ssm-connector-wp.zip';
    const CACHE_KEY = 'ssm_connector_release';
    const TTL_OK = 43200;      // 12 h
    const TTL_ERROR = 3600;    // 1 h : GitHub limite à 60 requêtes par heure et par adresse sans jeton

    private static $instance = null;
    private $basename;
    private $folder;

    /** Instance active, ou null pour un fichier seul (mu-plugin) ou si SSM_CONNECTOR_DISABLE_UPDATES est vrai. */
    public static function boot() {
        if (self::$instance === null) {
            $basename = plugin_basename(SSM_CONNECTOR_FILE);
            if (!self::applies($basename)) {
                return null;
            }
            self::$instance = new self($basename);
        }
        return self::$instance;
    }

    /** Pas de mise à jour pour un fichier seul (WordPress ne les gère pas) ni si SSM_CONNECTOR_DISABLE_UPDATES est vrai. */
    public static function applies($basename) {
        if (defined('SSM_CONNECTOR_DISABLE_UPDATES') && SSM_CONNECTOR_DISABLE_UPDATES) {
            return false;
        }
        return dirname($basename) !== '.';
    }

    public static function current() {
        return self::$instance;
    }

    public function __construct($basename) {
        $this->basename = $basename;
        $this->folder = dirname($basename);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject']);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'verify_download'], 10, 4);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        add_action('admin_post_ssm_connector_check_update', [$this, 'handle_check_request']);
    }

    // --- Lecture de la dernière release ---

    /** Dernier état connu sans aucune requête réseau : ['at', 'ok', 'release', 'error'] ou null. */
    public function cached_state() {
        $state = get_site_transient(self::CACHE_KEY);
        return (is_array($state) && isset($state['ok'], $state['at'])) ? $state : null;
    }

    /** État du cache, ou nouvelle interrogation de GitHub s'il est expiré (ou si $force). */
    public function state($force = false) {
        $state = $force ? null : $this->cached_state();
        if ($state === null) {
            $state = $this->fetch();
            $state['at'] = time();
            set_site_transient(self::CACHE_KEY, $state, $state['ok'] ? self::TTL_OK : self::TTL_ERROR);
        }
        return $state;
    }

    private function fail($message) {
        return ['ok' => false, 'release' => null, 'error' => $message];
    }

    private function fetch() {
        $base = defined('SSM_CONNECTOR_UPDATE_API') ? rtrim((string) SSM_CONNECTOR_UPDATE_API, '/') : 'https://api.github.com';
        $response = wp_remote_get($base . '/repos/' . self::REPO . '/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'SSM-Connector/' . SSM_CONNECTOR_VERSION],
        ]);
        if (is_wp_error($response)) {
            return $this->fail('GitHub injoignable : ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return $this->fail('aucune release trouvée (dépôt introuvable ou privé).');
        }
        if ($code === 403 || $code === 429) {
            return $this->fail("limite de requêtes de GitHub atteinte, nouvel essai dans une heure.");
        }
        if ($code !== 200) {
            return $this->fail('réponse inattendue de GitHub (HTTP ' . $code . ').');
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return $this->fail('réponse de GitHub illisible.');
        }
        return $this->parse_release($data);
    }

    /** Release GitHub → ['ok' => true, 'release' => [...]] ; ne retient que ce qui est strictement valide. */
    public function parse_release(array $data) {
        if (!empty($data['draft']) || !empty($data['prerelease'])) {
            return $this->fail('la dernière release n\'est pas une version stable.');
        }
        $tag = isset($data['tag_name']) && is_string($data['tag_name']) ? $data['tag_name'] : '';
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $m)) {
            return $this->fail('numéro de version illisible dans la release (« ' . substr($tag, 0, 40) . ' »).');
        }
        $package = null;
        $digest = null;
        foreach ((isset($data['assets']) && is_array($data['assets'])) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && isset($asset['name']) && $asset['name'] === self::ASSET) {
                $package = isset($asset['browser_download_url']) && is_string($asset['browser_download_url']) ? $asset['browser_download_url'] : null;
                // somme absente (anciennes releases) : pas de contrôle ; présente mais illisible : on refuse
                if (isset($asset['digest']) && $asset['digest'] !== '') {
                    if (!is_string($asset['digest']) || !preg_match('/^sha256:([0-9a-f]{64})$/D', $asset['digest'], $d)) {
                        return $this->fail('somme de contrôle de la release illisible.');
                    }
                    $digest = $d[1];
                }
                break;
            }
        }
        if ($package === null) {
            return $this->fail('la release ' . $m[1] . ' ne contient pas ' . self::ASSET . '.');
        }
        if (!$this->allowed_package($package)) {
            return $this->fail('adresse de téléchargement refusée (hors du dépôt ' . self::REPO . ').');
        }
        $url = isset($data['html_url']) && is_string($data['html_url']) && strpos($data['html_url'], 'https://github.com/') === 0 ? $data['html_url'] : '';
        return ['ok' => true, 'error' => null, 'release' => [
            'version' => $m[1],
            'package' => $package,
            'sha256' => $digest,
            'url' => $url,
            'notes' => substr(isset($data['body']) && is_string($data['body']) ? $data['body'] : '', 0, 5000),
            'published' => isset($data['published_at']) && is_string($data['published_at']) ? substr($data['published_at'], 0, 25) : '',
        ]];
    }

    /** Le paquet doit venir des releases du dépôt (la constante de préfixe ne sert qu'aux tests). */
    private function allowed_package($url) {
        if (strpos($url, 'https://github.com/' . self::REPO . '/releases/download/') === 0) {
            return true;
        }
        return defined('SSM_CONNECTOR_UPDATE_PACKAGE_PREFIX') && SSM_CONNECTOR_UPDATE_PACKAGE_PREFIX !== ''
            && strpos($url, (string) SSM_CONNECTOR_UPDATE_PACKAGE_PREFIX) === 0;
    }

    // --- Intégration à WordPress ---

    /**
     * Version installée d'après l'en-tête du fichier SUR DISQUE. Pendant une mise à jour (et juste après, quand WordPress
     * refait sa liste), c'est encore l'ancien code qui tourne : SSM_CONNECTOR_VERSION indiquerait l'ancienne version
     * et la nouvelle resterait proposée pendant 12 h alors qu'elle est installée.
     */
    private function installed_version() {
        $data = get_file_data(SSM_CONNECTOR_FILE, ['Version' => 'Version']);
        return (is_array($data) && !empty($data['Version'])) ? (string) $data['Version'] : SSM_CONNECTOR_VERSION;
    }

    private function entry(array $release, $newer) {
        return (object) [
            'id' => 'github.com/' . self::REPO,
            'slug' => $this->folder,
            'plugin' => $this->basename,
            'new_version' => $newer ? $release['version'] : $this->installed_version(),
            'url' => $release['url'] !== '' ? $release['url'] : 'https://github.com/' . self::REPO,
            'package' => $newer ? $release['package'] : '',
            'icons' => SSM_Connector::icon_urls(),
            'requires' => '5.5',
            'requires_php' => '7.4',
        ];
    }

    /** Appelé quand WordPress enregistre le résultat de sa propre vérification des extensions (deux fois par jour). */
    public function inject($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        $state = $this->state();
        if (!$state['ok']) {
            return $transient;   // GitHub ne répond pas : on ne touche à rien
        }
        $release = $state['release'];
        $newer = version_compare($release['version'], $this->installed_version(), '>');
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }
        // à jour : l'entrée « no_update » permet quand même d'activer les mises à jour automatiques depuis l'écran Extensions
        if ($newer) {
            $transient->response[$this->basename] = $this->entry($release, true);
            unset($transient->no_update[$this->basename]);
        } else {
            $transient->no_update[$this->basename] = $this->entry($release, false);
            unset($transient->response[$this->basename]);
        }
        return $transient;
    }

    /** Fenêtre « Afficher les détails » de l'écran Extensions. */
    public function plugin_information($result, $action = '', $args = null) {
        if ($action !== 'plugin_information' || !is_object($args) || !isset($args->slug) || $args->slug !== $this->folder) {
            return $result;
        }
        $state = $this->cached_state();
        if (!$state || !$state['ok']) {
            return $result;
        }
        $release = $state['release'];
        return (object) [
            'name' => 'SSM Connector',
            'slug' => $this->folder,
            'version' => $release['version'],
            'author' => 'Selest Informatique',
            'homepage' => 'https://github.com/' . self::REPO,
            'requires' => '5.5',
            'requires_php' => '7.4',
            'last_updated' => $release['published'],
            'download_link' => $release['package'],
            'icons' => SSM_Connector::icon_urls(),
            'sections' => [
                'description' => '<p>Envoie à SSM Core (Selest Site Manager) l\'inventaire du site : WordPress, PHP, extensions, thèmes.</p>',
                'changelog' => '<pre>' . esc_html($release['notes']) . '</pre>',
            ],
        ];
    }

    /** Télécharge le paquet de la release et contrôle sa somme SHA-256 (fournie par GitHub) avant toute installation. */
    public function verify_download($reply, $package = '', $upgrader = null, $hook_extra = []) {
        if ($reply !== false) {
            return $reply;
        }
        $state = $this->cached_state();
        if (!$state || !$state['ok'] || $package !== $state['release']['package']) {
            return $reply;   // pas notre paquet
        }
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url($package, 300);
        if (is_wp_error($file)) {
            return $file;
        }
        $expected = $state['release']['sha256'];
        if ($expected !== null && !hash_equals($expected, (string) hash_file('sha256', $file))) {
            @unlink($file);
            return new WP_Error('ssm_checksum', 'Somme de contrôle incorrecte : mise à jour annulée.');
        }
        return $file;
    }

    /** Garde le nom du dossier installé : WordPress remplacerait sinon un autre dossier que celui de l'extension. */
    public function fix_source_dir($source, $remote_source = '', $upgrader = null, $hook_extra = []) {
        if (!is_array($hook_extra) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }
        global $wp_filesystem;
        $current = rtrim($source, '/');
        $target = rtrim($remote_source, '/') . '/' . $this->folder;
        if ($current === $target) {
            return $source;
        }
        if (!is_object($wp_filesystem) || !$wp_filesystem->move($current, $target, true)) {
            return new WP_Error('ssm_rename', 'Impossible de renommer le dossier de la mise à jour.');
        }
        return $target . '/';
    }

    /** Bouton « Rechercher une mise à jour » de la page de réglages. */
    public function handle_check_request() {
        if (!current_user_can('update_plugins')) {
            wp_die('Accès refusé.', '', ['response' => 403]);
        }
        check_admin_referer('ssm_connector_check_update');
        $this->state(true);
        delete_site_transient('update_plugins');
        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }
        wp_update_plugins();   // reconstruit la liste de WordPress : inject() y ajoute l'entrée de cette extension
        wp_safe_redirect(admin_url('options-general.php?page=' . SSM_CONNECTOR_PAGE));
        exit;
    }
}

endif;

SSM_Connector::instance();
SSM_Connector_Updater::boot();

if (defined('WP_CLI') && WP_CLI) {
    class SSM_Connector_CLI {
        /** Affiche la configuration et le dernier envoi (jamais le token en entier). */
        public function status($args, $assoc_args) {
            $cfg = SSM_Connector::instance()->config();
            WP_CLI::success('SSM Connector v' . SSM_CONNECTOR_VERSION);
            WP_CLI::line('Site URL : ' . get_site_url());
            WP_CLI::line('WP version : ' . get_bloginfo('version') . ' — PHP : ' . PHP_VERSION);
            WP_CLI::line('Adresse de SSM : ' . ($cfg['url'] !== '' ? $cfg['url'] : 'NON DÉFINIE') . ' (' . $cfg['url_source'] . ')');
            WP_CLI::line('Token : ' . ($cfg['token'] !== '' ? 'défini, se termine par ' . substr($cfg['token'], -4) . ' (' . $cfg['token_source'] . ')' : 'NON DÉFINI'));
            if ($cfg['problem'] !== '') {
                WP_CLI::warning($cfg['problem']);
            }
            WP_CLI::line('Dernier envoi : ' . (get_option(SSM_Connector::OPT_LAST_AT) ?: 'jamais') . ' — ' . (get_option(SSM_Connector::OPT_LAST_MSG) ?: ''));
        }

        /**
         * Connecte le site à SSM et teste aussitôt la connexion.
         *
         * ## OPTIONS
         *
         * <url>
         * : Adresse de SSM Core (par exemple https://ssm.exemple.fr).
         *
         * [<token>]
         * : Token du site. Absent : lu sur l'entrée standard (évite de le laisser dans l'historique du shell) ; vide, le token déjà enregistré est conservé.
         *
         * ## EXAMPLES
         *
         *     echo "$TOKEN" | wp ssm connect https://ssm.exemple.fr
         */
        public function connect($args, $assoc_args) {
            $token = isset($args[1]) ? $args[1] : '';
            // sans argument : token lu sur l'entrée standard (jamais en attendant une saisie au clavier) ; vide = on garde l'actuel
            if ($token === '' && !(function_exists('posix_isatty') && posix_isatty(STDIN))) {
                $token = (string) stream_get_contents(STDIN);
            }
            $result = SSM_Connector::instance()->connect($args[0], $token);
            if ($result['ok']) {
                WP_CLI::success($result['message']);
            } else {
                WP_CLI::error($result['message']);
            }
        }

        /** Envoie un heartbeat tout de suite et affiche le résultat. */
        public function heartbeat($args, $assoc_args) {
            if (!SSM_Connector::instance()->is_configured() && SSM_Connector::instance()->config()['problem'] === '') {
                WP_CLI::error('Pas encore connecté : wp ssm connect <adresse-de-SSM> (token sur l\'entrée standard).');
            }
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
    WP_CLI::add_command('ssm connect', ['SSM_Connector_CLI', 'connect']);
    WP_CLI::add_command('ssm heartbeat', ['SSM_Connector_CLI', 'heartbeat']);
}
