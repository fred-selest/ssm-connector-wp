<?php
// Tests du connecteur sans WordPress : php tests/run.php  (code de sortie 1 au moindre échec)

require __DIR__ . '/stubs.php';
require dirname(__DIR__) . '/ssm-connector.php';   // une erreur fatale ici (méthode déclarée deux fois…) fait échouer toute la suite

$failures = 0;
$checks = 0;

function check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) {
        $failures++;
        echo "  ÉCHEC : $label\n";
    }
}

function same($actual, $expected, $label) {
    check($actual === $expected, $label . ' (attendu ' . json_encode($expected) . ', obtenu ' . json_encode($actual) . ')');
}

function test($name, callable $fn) {
    echo "- $name\n";
    ssm_reset();
    $fn();
}

$ssm = SSM_Connector::instance();

const TOKEN_A = 'Qx7_Lm2-9aZpR4tYv8NcB1sWe6HdKfJgU3oIyXk5MnA';   // 43 caractères, comme un token généré par SSM
const TOKEN_B = 'Zz1_Yy2-Xx3_Ww4-Vv5_Uu6-Tt7_Ss8-Rr9_Qq0-Pp1_Oo';

// Limites de SSM Core (app/schemas.py) : HeartbeatRequest, ExtensionData, ThemeData.
const CORE_LIMITS = [
    'top' => ['cms_version' => 50, 'php_version' => 20, 'db_version' => 50, 'web_server' => 50, 'hostname' => 255, 'site_path' => 500],
    'extension' => ['slug' => 255, 'name' => 255, 'version' => 50, 'latest_version' => 50],
    'theme' => ['slug' => 255, 'name' => 255, 'version' => 50, 'latest_version' => 50, 'parent_theme' => 255],
];

function check_core_limits($inv) {
    foreach (CORE_LIMITS['top'] as $field => $max) {
        if (isset($inv[$field])) {
            check(mb_strlen($inv[$field]) <= $max, "$field <= $max");
        }
    }
    check(count($inv['extensions']) <= 1000, 'au plus 1000 extensions');
    check(count($inv['themes']) <= 200, 'au plus 200 thèmes');
    foreach ($inv['extensions'] as $e) {
        check(mb_strlen($e['slug']) >= 1, 'slug non vide');
        foreach (CORE_LIMITS['extension'] as $field => $max) {
            if (isset($e[$field])) {
                check(mb_strlen($e[$field]) <= $max, "extension.$field <= $max");
            }
        }
    }
    foreach ($inv['themes'] as $t) {
        check(mb_strlen($t['slug']) >= 1, 'slug de thème non vide');
        foreach (CORE_LIMITS['theme'] as $field => $max) {
            if (isset($t[$field])) {
                check(mb_strlen($t[$field]) <= $max, "theme.$field <= $max");
            }
        }
    }
}

function core_replies($status, $body = []) {
    $GLOBALS['ssm_http'] = function ($url, $args) use ($status, $body) {
        return ['code' => $status, 'body' => is_string($body) ? $body : json_encode($body)];
    };
}

/** Configure l'extension comme après un « Connecter » réussi. */
function configure($url = 'https://ssm.exemple.fr', $token = TOKEN_A) {
    update_option(SSM_Connector::OPT_URL, $url);
    update_option(SSM_Connector::OPT_TOKEN, SSM_Connector::protect($token));
}

/** Tout ce que l'extension a écrit dans la base : le token en clair ne doit nulle part y figurer. */
function all_stored_text() {
    return json_encode($GLOBALS['ssm_opts']) . json_encode($GLOBALS['ssm_transients']);
}

function page_html($ssm) {
    ob_start();
    $ssm->render_admin_page();
    return ob_get_clean();
}

// ---------------------------------------------------------------------------------------------------------------

test('le fichier se charge et branche ses crochets', function () {
    foreach (['ssm_heartbeat_event', 'admin_menu', 'admin_notices', 'admin_post_ssm_connector_connect', 'admin_post_ssm_connector_test', 'wp_loaded'] as $hook) {
        check(!empty($GLOBALS['ssm_hooks'][$hook]), "crochet $hook");
    }
    check(!empty($GLOBALS['ssm_hooks']['plugin_action_links_ssm-connector/ssm-connector.php']), 'lien « Réglages » dans la liste des extensions');
});

test("l'extension n'ouvre aucune porte sur le site (rien d'accessible sans connexion, aucune route)", function () {
    $forbidden = '/^(rest_api_init|wp_ajax_nopriv_.*|admin_post_nopriv_.*|template_redirect|parse_request|xmlrpc_.*|init)$/';
    foreach (array_keys($GLOBALS['ssm_hooks']) as $hook) {
        check(!preg_match($forbidden, $hook), "aucun crochet public : $hook");
    }
    $source = file_get_contents(dirname(__DIR__) . '/ssm-connector.php');
    check(strpos($source, 'register_rest_route') === false, 'aucune route REST');
    check(strpos($source, 'wp_ajax_') === false, 'aucun point d\'entrée AJAX');
});

test("charger le fichier une 2e fois (mu-plugin + plugin) ne provoque pas de doublon", function () {
    $hooks_before = count($GLOBALS['ssm_hooks']['admin_menu']);
    require dirname(__DIR__) . '/ssm-connector.php';
    same(count($GLOBALS['ssm_hooks']['admin_menu']), $hooks_before, 'crochets non dupliqués');
});

test("la classe est déclarée dans un bloc conditionnel (sinon opcache la déclare d'avance : fatal au double chargement)", function () {
    // Constaté sur un vrai WordPress : « Cannot declare class SSM_Connector » quand le fichier est chargé deux fois.
    // Sans opcache (php en ligne de commande), le fatal n'apparaît pas : on contrôle donc la structure du code.
    $tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/ssm-connector.php'));
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $significant = [];
    foreach ($tokens as $t) {
        if (is_array($t) && in_array($t[0], $skip, true)) {
            continue;
        }
        $significant[] = $t;
    }
    $found = 0;
    foreach ($significant as $i => $t) {
        if (is_array($t) && $t[0] === T_CLASS && is_array($significant[$i + 1]) && in_array($significant[$i + 1][1], ['SSM_Connector', 'SSM_Connector_Updater'], true)) {
            $found++;
            $previous = $significant[$i - 1];
            check($previous === ':' || $previous === '{', 'la déclaration de ' . $significant[$i + 1][1] . ' suit « if (...) : » ou « { »');
        }
    }
    same($found, 2, 'les deux classes ont été examinées');
});

// --- Ce qui est envoyé -----------------------------------------------------------------------------------------

test("l'inventaire respecte le contrat de SSM Core", function () use ($ssm) {
    $inv = $ssm->collect_inventory();
    check(isset($inv['cms_version']) && $inv['cms_version'] !== '', 'cms_version');
    check(isset($inv['extensions']) && is_array($inv['extensions']), 'clé extensions (et non plugins)');
    same(count($inv['extensions']), 4, 'quatre extensions');
    same(count($inv['themes']), 2, 'deux thèmes');
    check(preg_match('/^\d+\.\d+\.\d+$/', $inv['php_version']) === 1, 'php_version à trois nombres');
    same($inv['db_version'], '8.0.36', 'db_version');
    check(!empty($inv['hostname']) && !empty($inv['site_path']), 'hostname et site_path');
    check_core_limits($inv);
});

test("versions publiées : celles des extensions à jour aussi, et l'état recalculé s'il a été effacé", function () use ($ssm) {
    ssm_reset();
    $GLOBALS['ssm_plugins'] = ['a/a.php' => ['Name' => 'A', 'Version' => '1.0'], 'b/b.php' => ['Name' => 'B', 'Version' => '2.0'],
                               'c/c.php' => ['Name' => 'C', 'Version' => '3.0']];
    $state = (object) ['last_checked' => time(), 'response' => ['a/a.php' => (object) ['new_version' => '1.1']],
                       'no_update' => ['b/b.php' => (object) ['new_version' => '2.0']]];
    $GLOBALS['ssm_transients']['update_plugins'] = $state;
    $by = array_column($ssm->collect_inventory()['extensions'], null, 'slug');
    same([$by['a']['latest_version'], $by['a']['update_available']], ['1.1', true], 'mise à jour en attente');
    same([$by['b']['latest_version'], $by['b']['update_available']], ['2.0', false], "à jour : sa version publiée, plus « inconnue »");
    same($by['c']['latest_version'], null, 'hors wordpress.org (premium) : inconnue, rien de deviné');
    same(in_array('plugins', $GLOBALS['ssm_refreshed'], true), false, 'état présent : pas de requête vers wordpress.org');

    unset($GLOBALS['ssm_transients']['update_plugins']);   // effacé par WordPress juste après une mise à jour
    $GLOBALS['ssm_refresh_plugins'] = $state;
    $by = array_column($ssm->collect_inventory()['extensions'], null, 'slug');
    same(in_array('plugins', $GLOBALS['ssm_refreshed'], true), true, 'état recalculé');
    same($by['b']['latest_version'], '2.0', 'et les versions publiées sont là dès cet envoi');
});

test("données minimales : rien que SSM Core lit, aucune donnée personnelle", function () use ($ssm) {
    $keys = array_keys($ssm->collect_inventory());
    sort($keys);
    $expected = ['capabilities', 'cms', 'cms_version', 'command_results', 'connector_version', 'db_version', 'extensions',
                 'hostname', 'login_enabled', 'php_errors', 'php_version', 'results', 'site_path', 'themes', 'web_server'];
    same($keys, $expected, 'clés envoyées');
    $json = json_encode($ssm->collect_inventory());
    foreach (['admin_email', 'users_count', 'pending_events', 'user_login', 'user_email', 'password', 'token'] as $forbidden) {
        check(stripos($json, $forbidden) === false, "pas de « $forbidden » dans l'inventaire");
    }
});

test("slugs d'extensions et de thèmes", function () use ($ssm) {
    $inv = $ssm->collect_inventory();
    same(array_column($inv['extensions'], 'slug'), ['akismet', 'woocommerce', 'inactive-demo', 'hello'], 'dossier, ou nom du fichier pour un plugin seul');
    $by = array_column($inv['extensions'], null, 'slug');
    same($by['inactive-demo']['is_active'], false, 'plugin inactif');
    same($by['akismet']['is_active'], true, 'plugin actif');
    $themes = array_column($inv['themes'], null, 'slug');
    same($themes['twentytwentyfour']['parent_theme'], null, 'un thème autonome n\'a pas de parent');
    same($themes['child']['parent_theme'], 'twentytwentyfour', 'un thème enfant a son parent');
    same($themes['child']['is_active'], true, 'thème actif');
});

test('un doublon de slug ne casse pas le heartbeat', function () use ($ssm) {
    $GLOBALS['ssm_plugins'] = [
        'foo/foo.php' => ['Name' => 'Foo', 'Version' => '1'],
        'foo.php' => ['Name' => 'Foo seul', 'Version' => '2'],
    ];
    $slugs = array_column($ssm->collect_inventory()['extensions'], 'slug');
    same(count(array_unique($slugs)), 2, 'identifiants distincts');
});

test('valeurs trop longues tronquées aux limites de Core', function () use ($ssm) {
    $GLOBALS['ssm_plugins'] = ['x/x.php' => ['Name' => str_repeat('é', 400), 'Version' => str_repeat('9', 80)]];
    $GLOBALS['ssm_themes'] = ['t' => new FakeTheme(str_repeat('T', 400), str_repeat('1', 90), str_repeat('p', 300))];
    $_SERVER['SERVER_SOFTWARE'] = str_repeat('Apache/', 30);
    $inv = $ssm->collect_inventory();
    unset($_SERVER['SERVER_SOFTWARE']);
    check_core_limits($inv);
    same(mb_strlen($inv['extensions'][0]['name']), 255, 'nom tronqué à 255 caractères');
    same(mb_strlen($inv['web_server']), 50, 'web_server tronqué à 50');
});

test('trop de plugins : plafonné à 1000', function () use ($ssm) {
    $GLOBALS['ssm_plugins'] = [];
    for ($i = 0; $i < 1200; $i++) {
        $GLOBALS['ssm_plugins']["p$i/p$i.php"] = ['Name' => "P$i", 'Version' => '1'];
    }
    same(count($ssm->collect_inventory()['extensions']), 1000, '1000 au maximum');
});

test('mises à jour détectées pour les plugins et les thèmes', function () use ($ssm) {
    $GLOBALS['ssm_transients']['update_plugins'] = (object) ['response' => [
        'woocommerce/woocommerce.php' => (object) ['new_version' => '9.2.0'],
    ]];
    $GLOBALS['ssm_transients']['update_themes'] = (object) ['response' => [
        'twentytwentyfour' => ['new_version' => '1.3'],   // WordPress stocke les thèmes sous forme de tableaux
    ]];
    $inv = $ssm->collect_inventory();
    $by = array_column($inv['extensions'], null, 'slug');
    same($by['woocommerce']['update_available'], true, 'plugin à mettre à jour');
    same($by['woocommerce']['latest_version'], '9.2.0', 'version proposée');
    same($by['akismet']['update_available'], false, 'plugin à jour');
    $themes = array_column($inv['themes'], null, 'slug');
    same($themes['twentytwentyfour']['latest_version'], '1.3', 'version du thème');
    same($themes['child']['latest_version'], null, 'thème à jour');
});

test("aucune donnée de mise à jour (transient absent) : pas d'erreur", function () use ($ssm) {
    foreach ($ssm->collect_inventory()['extensions'] as $e) {
        same($e['update_available'], false, 'pas de MAJ pour ' . $e['slug']);
    }
});

// --- Contrôle de ce que l'utilisateur colle -----------------------------------------------------------------------

test("adresse de SSM : l'adresse copiée du navigateur est nettoyée", function () {
    foreach ([
        'https://ssm.exemple.fr' => 'https://ssm.exemple.fr',
        'https://ssm.exemple.fr/' => 'https://ssm.exemple.fr',
        'ssm.exemple.fr' => 'https://ssm.exemple.fr',
        '  "https://ssm.exemple.fr/"  ' => 'https://ssm.exemple.fr',
        'HTTPS://SSM.Exemple.FR/Sites?x=1#/a' => 'https://ssm.exemple.fr',
        'https://ssm.exemple.fr/sites/12' => 'https://ssm.exemple.fr',
        'https://ssm.exemple.fr/api/v1/heartbeat' => 'https://ssm.exemple.fr',
        'https://ssm.exemple.fr:8443/login' => 'https://ssm.exemple.fr:8443',
        "https://ssm.exemple.fr\n" => 'https://ssm.exemple.fr',
    ] as $input => $expected) {
        $r = SSM_Connector::normalize_url($input);
        check($r['ok'] && $r['url'] === $expected, json_encode($input) . ' -> ' . json_encode($r));
    }
});

test("adresse de SSM : refusées", function () {
    foreach ([
        '', '   ', 'ftp://ssm.exemple.fr', 'javascript:alert(1)', 'https://u:p@ssm.exemple.fr', 'https://ssm exemple.fr',
        'https://ssm.exemple.fr:99999', 'https://ssm.exemple.fr:0', 'https://exé.fr', 'file:///etc/passwd', 'https://', '//',
        'http://ssm.exemple.fr', 'http://8.8.8.8:8000', 'http://172.32.0.1', 'http://example.com',
    ] as $bad) {
        $r = SSM_Connector::normalize_url($bad);
        check(!$r['ok'] && $r['error'] !== '', 'refusée : ' . json_encode($bad));
    }
    check(strpos(SSM_Connector::normalize_url('http://ssm.exemple.fr')['error'], 'https') !== false, 'http sur Internet : le message demande https');
});

test("adresse de SSM : http toléré uniquement sur un réseau privé ou en local", function () {
    foreach (['http://192.168.1.10:8000', 'http://10.0.0.5', 'http://172.20.0.1', 'http://localhost:8000', 'http://127.0.0.1:8000',
              'http://[::1]:8000', 'http://ssm', 'http://truenas.local:8000', 'http://nas.lan', 'http://srv.internal'] as $ok) {
        $r = SSM_Connector::normalize_url($ok);
        check($r['ok'] && $r['insecure'] === true, 'acceptée en http : ' . $ok . ' -> ' . json_encode($r));
    }
    same(SSM_Connector::normalize_url('https://ssm.exemple.fr')['insecure'], false, 'https n\'est pas signalé');
});

test("token : nettoyé à la saisie", function () {
    foreach ([
        TOKEN_A, " " . TOKEN_A . " \n", '"' . TOKEN_A . '"', "`" . TOKEN_A . "`", 'Bearer ' . TOKEN_A, 'X-SSM-Token: ' . TOKEN_A,
        'Authorization: Bearer ' . TOKEN_A, substr(TOKEN_A, 0, 20) . "\n" . substr(TOKEN_A, 20),   // retour à la ligne au milieu
    ] as $input) {
        $r = SSM_Connector::normalize_token($input);
        check($r['ok'] && $r['token'] === TOKEN_A, 'accepté : ' . json_encode($input) . ' -> ' . json_encode($r));
    }
});

test("token : refusés", function () {
    foreach (['', '   ', 'court', str_repeat('a', 31), str_repeat('a', 257), str_repeat('é', 40), "abc\x01" . str_repeat('a', 40)] as $bad) {
        $r = SSM_Connector::normalize_token($bad);
        check(!$r['ok'] && $r['error'] !== '', 'refusé : ' . json_encode(substr($bad, 0, 20)));
    }
    same(SSM_Connector::normalize_token(str_repeat('a', 32))['ok'], true, '32 caractères : limite basse acceptée');
    same(SSM_Connector::normalize_token(str_repeat('a', 256))['ok'], true, '256 caractères : limite haute acceptée');
});

// --- Chiffrement du token ------------------------------------------------------------------------------------------

test("token chiffré au repos : aller-retour, aléa, falsification, changement de clé", function () {
    $stored = SSM_Connector::protect(TOKEN_A);
    check(strpos($stored, 'enc:v1:') === 0, 'valeur préfixée enc:v1:');
    check(strpos($stored, TOKEN_A) === false && strpos(base64_decode(substr($stored, 7)), TOKEN_A) === false, 'le token n\'apparaît pas en clair');
    same(SSM_Connector::reveal($stored), TOKEN_A, 'aller-retour');
    check(SSM_Connector::protect(TOKEN_A) !== $stored, 'deux chiffrements du même token diffèrent (nonce aléatoire)');
    $raw = base64_decode(substr($stored, 7));
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
    same(SSM_Connector::reveal('enc:v1:' . base64_encode($raw)), null, 'chiffré modifié : illisible');
    same(SSM_Connector::reveal('enc:v1:' . base64_encode('court')), null, 'trop court : illisible');
    same(SSM_Connector::reveal('enc:v1:%%%pas-du-base64'), null, 'base64 invalide : illisible');
    same(SSM_Connector::reveal('enc:v1:'), null, 'vide : illisible');
    $GLOBALS['ssm_salt'] = 'salt-B';
    same(SSM_Connector::reveal($stored), null, 'clés de sécurité changées : illisible');
    $GLOBALS['ssm_salt'] = 'salt-A';
    same(SSM_Connector::reveal($stored), TOKEN_A, 'clés rétablies : relisible');
    same(SSM_Connector::reveal('ancien-token-en-clair'), 'ancien-token-en-clair', 'ancien token en clair (0.2.x) : repris tel quel');
});

// --- Configuration -------------------------------------------------------------------------------------------------

test("configuration : vide au départ", function () use ($ssm) {
    $cfg = $ssm->config();
    same([$cfg['url'], $cfg['token'], $cfg['url_source'], $cfg['token_source'], $cfg['problem']], ['', '', 'none', 'none', ''], 'rien');
    same($ssm->is_configured(), false, 'non configuré');
});

test("configuration : lue depuis la base, le token est déchiffré", function () use ($ssm) {
    configure();
    $cfg = $ssm->config();
    same([$cfg['url'], $cfg['token'], $cfg['url_source'], $cfg['token_source'], $cfg['problem']], ['https://ssm.exemple.fr', TOKEN_A, 'database', 'database', ''], 'base de données');
    same($ssm->is_configured(), true, 'configuré');
});

test("configuration : token illisible (clés changées) ou adresse invalide en base = problème expliqué", function () use ($ssm) {
    configure();
    $GLOBALS['ssm_salt'] = 'salt-B';
    $cfg = $ssm->config();
    check(strpos($cfg['problem'], 'illisible') !== false && $cfg['token'] === '', 'token illisible signalé : ' . $cfg['problem']);
    $GLOBALS['ssm_salt'] = 'salt-A';
    update_option(SSM_Connector::OPT_URL, 'http://ssm.exemple.fr');   // écrit à la main, contourne les contrôles
    check(strpos($ssm->config()['problem'], 'https') !== false, 'http public en base signalé');
    $cfg = $ssm->config();
    same($cfg['url'], '', 'adresse non retenue');
});

// --- Connecter -----------------------------------------------------------------------------------------------------

test("« Connecter » : enregistre, chiffre le token, teste aussitôt", function () use ($ssm) {
    core_replies(200, ['status' => 'accepted']);
    $r = $ssm->connect('https://ssm.exemple.fr/sites', "  " . TOKEN_A . " ");
    check($r['ok'] && strpos($r['message'], 'accepté') !== false, 'succès : ' . $r['message']);
    same(get_option(SSM_Connector::OPT_URL), 'https://ssm.exemple.fr', 'adresse enregistrée nettoyée');
    $stored = get_option(SSM_Connector::OPT_TOKEN);
    check(strpos($stored, 'enc:v1:') === 0 && SSM_Connector::reveal($stored) === TOKEN_A, 'token enregistré chiffré');
    check(strpos(all_stored_text(), TOKEN_A) === false, 'le token n\'est nulle part en clair dans la base');
    same(count($GLOBALS['ssm_http_calls']), 1, 'un envoi immédiat');
    $call = $GLOBALS['ssm_http_calls'][0];
    same($call['url'], 'https://ssm.exemple.fr/api/v1/heartbeat', 'adresse d\'envoi');
    same($call['args']['headers']['X-SSM-Token'], TOKEN_A, 'token envoyé dans X-SSM-Token');
    same(array_keys($call['args']['headers']), ['Content-Type', 'X-SSM-Token'], 'aucun autre en-tête');
    same($call['args']['redirection'], 0, 'aucune redirection suivie');
    same($call['args']['sslverify'], true, 'certificat vérifié');
    check(strpos($call['args']['body'], TOKEN_A) === false, 'le token n\'est pas dans le corps');
    $body = json_decode($call['args']['body'], true);
    check(isset($body['extensions']) && count($body['extensions']) === 4 && !isset($body['pending_events']), 'inventaire sans file d\'événements');
    same(get_option(SSM_Connector::OPT_LAST_OK), '1', 'statut OK');
});

test("« Connecter » : une saisie invalide n'enregistre rien et garde l'ancienne configuration", function () use ($ssm) {
    configure('https://ancien.exemple.fr', TOKEN_B);
    $before = json_encode($GLOBALS['ssm_opts']);
    foreach ([['http://ssm.exemple.fr', TOKEN_A], ['ftp://x.fr', TOKEN_A], ['https://ssm.exemple.fr', 'court']] as [$url, $token]) {
        $r = $ssm->connect($url, $token);
        check(!$r['ok'] && strpos($r['message'], 'Réglages non enregistrés') === 0, 'refusé : ' . $r['message']);
        same(get_option(SSM_Connector::OPT_URL), 'https://ancien.exemple.fr', 'adresse inchangée');
        same(SSM_Connector::reveal(get_option(SSM_Connector::OPT_TOKEN)), TOKEN_B, 'token inchangé');
    }
    same($GLOBALS['ssm_http_calls'], [], 'aucun envoi');
});

test("« Connecter » : champs vides = conserver l'existant ; rien de configuré = message clair", function () use ($ssm) {
    configure('https://ssm.exemple.fr', TOKEN_B);
    core_replies(200);
    $r = $ssm->connect('', '');
    check($r['ok'], 'rien à saisir : on reteste');
    same(SSM_Connector::reveal(get_option(SSM_Connector::OPT_TOKEN)), TOKEN_B, 'token conservé');
    $r = $ssm->connect('https://autre.exemple.fr', '');
    check($r['ok'], 'nouvelle adresse, token conservé');
    same(get_option(SSM_Connector::OPT_URL), 'https://autre.exemple.fr', 'adresse mise à jour');
    same(SSM_Connector::reveal(get_option(SSM_Connector::OPT_TOKEN)), TOKEN_B, 'token toujours conservé');
    ssm_reset();
    $r = $ssm->connect('https://ssm.exemple.fr', '');
    check(!$r['ok'] && strpos($r['message'], 'collez le token') !== false, 'sans token : ' . $r['message']);
    same(get_option(SSM_Connector::OPT_URL, 'absent'), 'absent', 'rien enregistré sans token');
    $r = $ssm->connect('', TOKEN_A);
    check(!$r['ok'] && strpos($r['message'], "saisissez l'adresse") !== false, 'sans adresse : ' . $r['message']);
});

test("« Connecter » : réglages enregistrés mais SSM refuse = le dit clairement", function () use ($ssm) {
    core_replies(401, ['detail' => 'Invalid connector token']);
    $r = $ssm->connect('https://ssm.exemple.fr', TOKEN_A);
    check(!$r['ok'] && strpos($r['message'], 'Réglages enregistrés, mais la connexion a échoué') === 0 && strpos($r['message'], '401') !== false, $r['message']);
    same(get_option(SSM_Connector::OPT_URL), 'https://ssm.exemple.fr', 'adresse tout de même enregistrée');
    check(strpos($r['message'], TOKEN_A) === false, 'le token n\'apparaît pas dans le message');
});

// --- Envoi ---------------------------------------------------------------------------------------------------------

test("envoi : sans configuration, aucune requête et aucun faux « échec » enregistré", function () use ($ssm) {
    same($ssm->send_heartbeat(), false, 'rien à envoyer');
    same($GLOBALS['ssm_http_calls'], [], 'rien envoyé');
    same(get_option(SSM_Connector::OPT_LAST_AT, 'absent'), 'absent', 'aucun état enregistré');
    $html = page_html($ssm);
    check(strpos($html, 'Pas encore connecté') !== false && strpos($html, '✘') === false, 'la page montre le guide, pas une erreur');
});

test("envoi : token illisible ou adresse refusée = aucune requête", function () use ($ssm) {
    configure();
    $GLOBALS['ssm_salt'] = 'salt-B';
    same($ssm->send_heartbeat(), false, 'token illisible : échec');
    same($GLOBALS['ssm_http_calls'], [], 'rien envoyé');
    check(strpos(get_option(SSM_Connector::OPT_LAST_MSG), 'illisible') !== false, 'message');
    $GLOBALS['ssm_salt'] = 'salt-A';
    update_option(SSM_Connector::OPT_URL, 'http://ssm.exemple.fr');
    same($ssm->send_heartbeat(), false, 'http public : échec');
    same($GLOBALS['ssm_http_calls'], [], 'le token n\'est jamais envoyé en clair sur Internet');
});

test("envoi : chaque erreur de SSM est expliquée, sans jamais citer le token", function () use ($ssm) {
    configure();
    foreach ([
        [401, ['detail' => 'x'], '401'], [403, [], '403'], [404, [], '404'], [301, [], 'Redirection'], [502, [], 'Erreur de SSM Core'],
        [422, ['detail' => [['loc' => ['body', 'php_version'], 'msg' => 'too long']]], 'php_version'],
    ] as [$status, $body, $needle]) {
        core_replies($status, $body);
        same($ssm->send_heartbeat(), false, "échec $status");
        $msg = get_option(SSM_Connector::OPT_LAST_MSG);
        check(strpos($msg, $needle) !== false, "message $status : $msg");
        check(strpos($msg, TOKEN_A) === false, "le token n'apparaît pas dans le message $status");
    }
    core_replies(502, '<html>Bad gateway</html>');
    same($ssm->send_heartbeat(), false, 'corps HTML sans erreur PHP');
    $GLOBALS['ssm_http'] = function () { return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host'); };
    same($ssm->send_heartbeat(), false, 'SSM injoignable');
    check(strpos(get_option(SSM_Connector::OPT_LAST_MSG), 'injoignable') !== false, 'message injoignable');
    core_replies(200);
    same($ssm->send_heartbeat(), true, 'et ça repart dès que SSM répond');
    check(strpos(all_stored_text(), TOKEN_A) === false, 'le token n\'est jamais en clair dans la base');
});

// --- Migration depuis la 0.2.x -------------------------------------------------------------------------------------

test("mise à jour depuis la 0.2.x : token chiffré, ancienne file d'événements effacée, une seule fois", function () use ($ssm) {
    update_option('ssm_connector_token', TOKEN_A);                       // 0.2.x : en clair
    update_option('ssm_pending_events', [['type' => 'login_failed', 'payload' => ['user' => 'pirate@exemple.fr']]]);
    $ssm->maybe_upgrade();
    $stored = get_option('ssm_connector_token');
    check(strpos($stored, 'enc:v1:') === 0 && SSM_Connector::reveal($stored) === TOKEN_A, 'token chiffré, même valeur');
    check(strpos(all_stored_text(), TOKEN_A) === false, 'plus de clair dans la base');
    same(get_option('ssm_pending_events', 'absent'), 'absent', 'identifiants saisis à la connexion effacés');
    same(get_option(SSM_Connector::OPT_SCHEMA), SSM_Connector::SCHEMA, 'version du schéma enregistrée');
    $before = get_option('ssm_connector_token');
    $ssm->maybe_upgrade();
    same(get_option('ssm_connector_token'), $before, 'idempotent : pas de second chiffrement');
    ssm_reset();
    $ssm->maybe_upgrade();
    same(get_option('ssm_connector_token', 'absent'), 'absent', 'sans token : rien créé');
    update_option('ssm_connector_token', SSM_Connector::protect(TOKEN_B));
    delete_option(SSM_Connector::OPT_SCHEMA);
    $kept = get_option('ssm_connector_token');
    $ssm->maybe_upgrade();
    same(get_option('ssm_connector_token'), $kept, 'token déjà chiffré : intact');
});

test("la planification horaire est créée et retirée à la désactivation", function () use ($ssm) {
    $ssm->on_loaded();
    check(isset($GLOBALS['ssm_scheduled']['ssm_heartbeat_event']), 'envoi horaire planifié');
    check(get_option(SSM_Connector::OPT_TOKEN, 'absent') === 'absent', 'aucun token inventé');
    $ssm->deactivate();
    check(!isset($GLOBALS['ssm_scheduled']['ssm_heartbeat_event']), 'planification retirée');
});

// --- Administration ------------------------------------------------------------------------------------------------

test("rappel tant que le site n'est pas connecté : seulement aux administrateurs, sur Extensions et Tableau de bord", function () use ($ssm) {
    $show = function () use ($ssm) { ob_start(); $ssm->maybe_notice(); return ob_get_clean(); };
    $GLOBALS['ssm_screen'] = 'plugins';
    check(strpos($show(), 'pas encore connecté') !== false, 'affiché sur Extensions');
    $GLOBALS['ssm_screen'] = 'dashboard';
    check(strpos($show(), 'Configurer maintenant') !== false, 'affiché sur le Tableau de bord');
    $GLOBALS['ssm_screen'] = 'edit-post';
    same($show(), '', 'pas ailleurs');
    $GLOBALS['ssm_screen'] = null;
    same($show(), '', 'pas hors écran d\'administration');
    $GLOBALS['ssm_screen'] = 'plugins';
    $GLOBALS['ssm_can'] = false;
    same($show(), '', 'pas pour un non-administrateur');
    $GLOBALS['ssm_can'] = true;
    configure();
    same($show(), '', 'plus rien une fois connecté');
});

test("lien « Réglages » dans la liste des extensions", function () use ($ssm) {
    $links = $ssm->action_links(['<a href="#">Désactiver</a>']);
    check(strpos($links[0], 'options-general.php?page=ssm-connector') !== false && strpos($links[0], 'Réglages') !== false, 'lien en premier');
    same(count($links), 2, 'les autres liens sont conservés');
});

test("page de réglages : guide quand rien n'est configuré", function () use ($ssm) {
    $html = page_html($ssm);
    check(strpos($html, 'Pas encore connecté') !== false && strpos($html, 'bouton 🔌') !== false, 'guide en trois étapes');
    check(strpos($html, 'name="ssm_url"') !== false && strpos($html, 'name="ssm_token"') !== false && strpos($html, 'Connecter') !== false, 'formulaire');
    check(strpos($html, 'Tester la connexion') === false, 'pas de test tant que rien n\'est configuré');
    check(strpos($html, 'type="password"') !== false, 'le token se saisit dans un champ masqué');
});

test("page de réglages : connecté, le token n'est jamais affiché", function () use ($ssm) {
    configure();
    core_replies(200);
    $ssm->send_heartbeat();
    $html = page_html($ssm);
    check(strpos($html, '✔ Connecté') !== false, 'état connecté');
    check(strpos($html, TOKEN_A) === false, 'token absent de la page');
    check(strpos($html, '•••• ' . substr(TOKEN_A, -4)) !== false, '4 derniers caractères seulement');
    check(strpos($html, 'chiffré dans la base de données') !== false, 'sécurité : chiffrement indiqué');
    check(strpos($html, 'n\'ouvre aucune porte') !== false, 'sécurité : aucune porte ouverte');
    check(strpos($html, 'Tester la connexion') !== false, 'bouton de test');
    check(!preg_match('/name="ssm_token"[^>]*value="[^"]+"/', $html), 'le champ token n\'est jamais prérempli');
});

test("page de réglages : l'échec est expliqué et le texte est échappé", function () use ($ssm) {
    configure();
    update_option(SSM_Connector::OPT_LAST_AT, '2026-10-05 10:00:00');
    update_option(SSM_Connector::OPT_LAST_OK, '0');
    update_option(SSM_Connector::OPT_LAST_MSG, 'Token refusé <script>alert(1)</script>');
    $html = page_html($ssm);
    check(strpos($html, '✘ Échec') !== false, 'état en échec');
    check(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false, 'message échappé');
});

test("page de réglages : problème de configuration mis en avant", function () use ($ssm) {
    configure();
    $GLOBALS['ssm_salt'] = 'salt-B';
    $html = page_html($ssm);
    check(strpos($html, 'Configuration à corriger') !== false && strpos($html, 'illisible') !== false, 'token illisible expliqué');
});

test("page de réglages : refusée sans le droit manage_options", function () use ($ssm) {
    $GLOBALS['ssm_can'] = false;
    try {
        page_html($ssm);
        check(false, 'aurait dû refuser');
    } catch (RuntimeException $e) {
        check(strpos($e->getMessage(), 'wp_die') === 0, 'accès refusé');
        ob_end_clean();
    }
});

// ---------------------------------------------------------------------------------------------------------------
// Mises à jour de l'extension (SSM_Connector_Updater)
// ---------------------------------------------------------------------------------------------------------------

const REPO_URL = 'https://github.com/fred-selest/ssm-connector-wp';

function release_json(array $override = []) {
    return array_merge([
        'tag_name' => 'v0.9.0',
        'draft' => false,
        'prerelease' => false,
        'html_url' => REPO_URL . '/releases/tag/v0.9.0',
        'body' => "### Corrigé\n- un truc",
        'published_at' => '2026-10-05T10:00:00Z',
        'assets' => [
            ['name' => 'ssm-connector-wp-v0.9.0.zip', 'browser_download_url' => REPO_URL . '/releases/download/v0.9.0/ssm-connector-wp-v0.9.0.zip', 'digest' => 'sha256:' . str_repeat('11', 32)],
            ['name' => 'ssm-connector-wp.zip', 'browser_download_url' => REPO_URL . '/releases/download/v0.9.0/ssm-connector-wp.zip', 'digest' => 'sha256:' . str_repeat('ab', 32)],
        ],
    ], $override);
}

function github_returns($status, $body) {
    $GLOBALS['ssm_get'] = function ($url, $args) use ($status, $body) {
        return ['code' => $status, 'body' => is_string($body) ? $body : json_encode($body)];
    };
}

function updater() {
    return new SSM_Connector_Updater('ssm-connector/ssm-connector.php');
}

test("mises à jour : lecture stricte d'une release", function () {
    $u = updater();
    $r = $u->parse_release(release_json());
    check($r['ok'], 'release valide acceptée');
    same($r['release']['version'], '0.9.0', 'version sans le v');
    same($r['release']['package'], REPO_URL . '/releases/download/v0.9.0/ssm-connector-wp.zip', "c'est le ZIP à nom stable qui est choisi");
    same($r['release']['sha256'], str_repeat('ab', 32), 'somme SHA-256 de ce ZIP');
    same($u->parse_release(release_json(['tag_name' => '0.4.0']))['release']['version'], '0.4.0', 'tag sans v accepté');
    foreach (['latest', 'v0.4', 'v1.0.0-beta', ' v0.4.0', "v0.4.0\n", '', 'v0.4.0.1', 'v0.4.x'] as $bad) {
        check(!$u->parse_release(release_json(['tag_name' => $bad]))['ok'], 'tag refusé : ' . json_encode($bad));
    }
    check(!$u->parse_release(release_json(['draft' => true]))['ok'], 'brouillon refusé');
    check(!$u->parse_release(release_json(['prerelease' => true]))['ok'], 'préversion refusée');
    check(!$u->parse_release(release_json(['assets' => [['name' => 'autre.zip', 'browser_download_url' => REPO_URL . '/releases/download/v0.9.0/autre.zip']]]))['ok'], 'ZIP manquant refusé');
    check(!$u->parse_release(release_json(['assets' => 'x']))['ok'], 'assets invalide refusé');
});

test("mises à jour : le paquet doit venir des releases du dépôt", function () {
    $u = updater();
    foreach ([
        'https://evil.example/ssm-connector-wp.zip',
        'https://github.com/autre/depot/releases/download/v0.9.0/ssm-connector-wp.zip',
        'http://github.com/fred-selest/ssm-connector-wp/releases/download/v0.9.0/ssm-connector-wp.zip',
        'https://github.com.evil.example/fred-selest/ssm-connector-wp/releases/download/v0/ssm-connector-wp.zip',
        'https://github.com/fred-selest/ssm-connector-wp-evil/releases/download/v0/ssm-connector-wp.zip',
    ] as $url) {
        $data = release_json(['assets' => [['name' => 'ssm-connector-wp.zip', 'browser_download_url' => $url]]]);
        check(!$u->parse_release($data)['ok'], 'adresse refusée : ' . $url);
    }
});

test("mises à jour : somme de contrôle absente tolérée, présente mais illisible refusée", function () {
    $u = updater();
    $zip = REPO_URL . '/releases/download/v0.9.0/ssm-connector-wp.zip';
    $none = $u->parse_release(release_json(['assets' => [['name' => 'ssm-connector-wp.zip', 'browser_download_url' => $zip]]]));
    check($none['ok'] && $none['release']['sha256'] === null, 'sans somme : acceptée, non contrôlée');
    foreach (['sha1:' . str_repeat('a', 40), 'sha256:xyz', 'sha256:' . str_repeat('AB', 32), 'sha256:' . str_repeat('a', 63), ['sha256']] as $bad) {
        $r = $u->parse_release(release_json(['assets' => [['name' => 'ssm-connector-wp.zip', 'browser_download_url' => $zip, 'digest' => $bad]]]));
        check(!$r['ok'], 'somme illisible refusée : ' . json_encode($bad));
    }
});

test("mises à jour : interrogation de GitHub, cache et erreurs", function () {
    $u = updater();
    github_returns(200, release_json());
    $s = $u->state();
    check($s['ok'], 'état ok');
    same(count($GLOBALS['ssm_get_calls']), 1, 'une requête');
    same($GLOBALS['ssm_get_calls'][0]['url'], 'https://api.github.com/repos/fred-selest/ssm-connector-wp/releases/latest', 'adresse');
    check(strpos($GLOBALS['ssm_get_calls'][0]['args']['headers']['User-Agent'], 'SSM-Connector/') === 0, 'User-Agent');
    same($GLOBALS['ssm_transient_ttl'][SSM_Connector_Updater::CACHE_KEY], 43200, 'succès gardé 12 h');
    $u->state();
    $u->inject((object) []);
    same(count($GLOBALS['ssm_get_calls']), 1, 'pas de nouvelle requête tant que le cache est valide');
    $u->state(true);
    same(count($GLOBALS['ssm_get_calls']), 2, 'rechargement forcé');
});

test("mises à jour : les échecs sont expliqués et gardés 1 h (GitHub n'est pas martelé)", function () {
    foreach ([
        [404, '{}', 'privé'],
        [403, '{}', 'limite'],
        [429, '{}', 'limite'],
        [500, '{}', 'HTTP 500'],
        [200, 'pas du json', 'illisible'],
        [200, '"une chaîne"', 'illisible'],
    ] as [$status, $body, $needle]) {
        ssm_reset();
        $u = updater();
        github_returns($status, $body);
        $s = $u->state();
        check(!$s['ok'] && strpos($s['error'], $needle) !== false, "HTTP $status / $body : {$s['error']}");
        same($GLOBALS['ssm_transient_ttl'][SSM_Connector_Updater::CACHE_KEY], 3600, "échec $status gardé 1 h");
        $u->state();
        same(count($GLOBALS['ssm_get_calls']), 1, "pas de nouvelle requête après l'échec $status");
    }
    ssm_reset();
    $GLOBALS['ssm_get'] = function () { return new WP_Error('http_request_failed', 'cURL error 6'); };
    $s = updater()->state();
    check(!$s['ok'] && strpos($s['error'], 'injoignable') !== false, 'GitHub injoignable');
});

test("mises à jour : WordPress reçoit la nouvelle version", function () {
    $u = updater();
    github_returns(200, release_json());
    $t = (object) ['response' => ['autre/autre.php' => (object) ['new_version' => '2.0']], 'no_update' => [], 'checked' => []];
    $out = $u->inject($t);
    $e = $out->response['ssm-connector/ssm-connector.php'];
    same($e->new_version, '0.9.0', 'nouvelle version');
    same($e->package, REPO_URL . '/releases/download/v0.9.0/ssm-connector-wp.zip', 'paquet');
    same($e->slug, 'ssm-connector', 'slug = dossier');
    same($e->plugin, 'ssm-connector/ssm-connector.php', 'fichier du plugin');
    check(!empty($e->icons['1x']) && !empty($e->icons['2x']) && !empty($e->icons['svg']), 'icônes fournies');
    check(isset($out->response['autre/autre.php']), "l'entrée d'un autre plugin est conservée");
    check(!isset($out->no_update['ssm-connector/ssm-connector.php']), 'pas en même temps dans no_update');
});

test("mises à jour : à jour ou plus ancienne = pas d'installation proposée", function () {
    foreach (['v0.4.0', 'v0.3.0', 'v0.2.0'] as $tag) {
        ssm_reset();
        github_returns(200, release_json(['tag_name' => $tag, 'assets' => [['name' => 'ssm-connector-wp.zip', 'browser_download_url' => REPO_URL . "/releases/download/$tag/ssm-connector-wp.zip"]]]));
        $out = updater()->inject((object) ['response' => ['ssm-connector/ssm-connector.php' => (object) ['new_version' => '9.9.9']]]);
        check(!isset($out->response['ssm-connector/ssm-connector.php']), "$tag : entrée de mise à jour retirée");
        $e = $out->no_update['ssm-connector/ssm-connector.php'] ?? null;
        check($e !== null && $e->package === '' && $e->new_version === SSM_CONNECTOR_VERSION, "$tag : entrée no_update sans paquet (permet les mises à jour automatiques)");
    }
});

test("mises à jour : juste après l'installation, la version du disque fait foi (le code en mémoire est l'ancien)", function () {
    // WordPress refait sa liste de mises à jour dans le MÊME processus que la mise à jour :
    // SSM_CONNECTOR_VERSION y vaut encore la version chargée en mémoire, pas celle du disque.
    // Sans lecture du disque, la version qui vient d'être installée resterait proposée pendant 12 h.
    $u = updater();
    github_returns(200, release_json());       // 0.9.0 disponible sur GitHub
    $GLOBALS['ssm_disk_version'] = '0.9.0';   // et c'est celle-ci qu'on vient d'installer
    $out = $u->inject((object) ['response' => ['ssm-connector/ssm-connector.php' => (object) ['new_version' => '0.9.0']]]);
    check(!isset($out->response['ssm-connector/ssm-connector.php']), 'la version installée n\'est plus proposée');
    $e = $out->no_update['ssm-connector/ssm-connector.php'] ?? null;
    check($e !== null && $e->new_version === '0.9.0' && $e->package === '', 'entrée no_update à la version du disque');
    $GLOBALS['ssm_disk_version'] = '0.4.0';   // avant la mise à jour, c'est 0.4.0 qui est installée
    $out = $u->inject((object) []);
    check(isset($out->response['ssm-connector/ssm-connector.php']), 'avant la mise à jour, 0.9.0 reste proposée');
});

test("mises à jour : GitHub en panne ne casse rien", function () {
    $u = updater();
    github_returns(500, '{}');
    $t = (object) ['response' => ['autre/autre.php' => (object) ['new_version' => '2.0']], 'no_update' => ['x/x.php' => (object) []]];
    $out = $u->inject($t);
    same(array_keys($out->response), ['autre/autre.php'], 'réponses inchangées');
    same(array_keys($out->no_update), ['x/x.php'], 'no_update inchangé');
    same($u->inject('pas un objet'), 'pas un objet', 'valeur non objet renvoyée telle quelle');
    same($u->inject(false), false, 'false renvoyé tel quel');
});

test("mises à jour : fenêtre « Afficher les détails »", function () {
    $u = updater();
    github_returns(200, release_json(['body' => '<script>alert(1)</script> & notes']));
    $u->state();
    $info = $u->plugin_information(false, 'plugin_information', (object) ['slug' => 'ssm-connector']);
    same($info->version, '0.9.0', 'version');
    same($info->download_link, REPO_URL . '/releases/download/v0.9.0/ssm-connector-wp.zip', 'lien');
    check(strpos($info->sections['changelog'], '<script>') === false && strpos($info->sections['changelog'], '&lt;script&gt;') !== false, 'notes échappées');
    same($u->plugin_information(false, 'plugin_information', (object) ['slug' => 'autre']), false, 'autre extension : inchangé');
    same($u->plugin_information('x', 'query_plugins', (object) ['slug' => 'ssm-connector']), 'x', 'autre action : inchangé');
    ssm_reset();
    same(updater()->plugin_information(false, 'plugin_information', (object) ['slug' => 'ssm-connector']), false, 'sans cache : inchangé');
});

test("mises à jour : contrôle de la somme SHA-256 avant installation", function () {
    $u = updater();
    $file = tempnam(sys_get_temp_dir(), 'ssmzip');
    file_put_contents($file, 'contenu du zip');
    $sum = hash('sha256', 'contenu du zip');
    $pkg = REPO_URL . '/releases/download/v0.9.0/ssm-connector-wp.zip';
    $GLOBALS['ssm_download'] = function ($url) use ($file) { return $file; };

    github_returns(200, release_json(['assets' => [['name' => 'ssm-connector-wp.zip', 'browser_download_url' => $pkg, 'digest' => "sha256:$sum"]]]));
    $u->state();
    same($u->verify_download(false, $pkg, null, []), $file, 'somme correcte : fichier renvoyé');

    $other = tempnam(sys_get_temp_dir(), 'ssmzip');
    file_put_contents($other, 'zip modifié par quelqu\'un');
    $GLOBALS['ssm_download'] = function ($url) use ($other) { return $other; };
    $r = $u->verify_download(false, $pkg, null, []);
    check($r instanceof WP_Error && $r->code === 'ssm_checksum', 'somme incorrecte : erreur');
    check(!file_exists($other), 'le fichier douteux est supprimé');

    same($u->verify_download(false, 'https://autre.example/x.zip', null, []), false, "autre paquet : on n'intervient pas");
    same($u->verify_download('/deja/telecharge.zip', $pkg, null, []), '/deja/telecharge.zip', 'déjà pris en charge par un autre filtre');

    $GLOBALS['ssm_download'] = function ($url) { return new WP_Error('download_failed', 'timeout'); };
    $r = $u->verify_download(false, $pkg, null, []);
    check($r instanceof WP_Error && $r->code === 'download_failed', "erreur de téléchargement transmise");

    ssm_reset();
    $u = updater();
    github_returns(200, release_json(['assets' => [['name' => 'ssm-connector-wp.zip', 'browser_download_url' => $pkg]]]));
    $u->state();
    $GLOBALS['ssm_download'] = function ($url) use ($file) { return $file; };
    same($u->verify_download(false, $pkg, null, []), $file, 'release sans somme : installée sans contrôle');
    unlink($file);
});

test("mises à jour : le dossier de l'extension est conservé", function () {
    $u = updater();
    $hook = ['plugin' => 'ssm-connector/ssm-connector.php'];
    $fs = $GLOBALS['wp_filesystem'];
    same($u->fix_source_dir('/tmp/up/ssm-connector-wp-main/', '/tmp/up', null, $hook), '/tmp/up/ssm-connector/', 'dossier renommé');
    same($fs->moves, [['/tmp/up/ssm-connector-wp-main', '/tmp/up/ssm-connector']], 'déplacement demandé');
    same($u->fix_source_dir('/tmp/up/ssm-connector/', '/tmp/up', null, $hook), '/tmp/up/ssm-connector/', 'déjà bon : inchangé');
    same(count($fs->moves), 1, 'pas de déplacement inutile');
    same($u->fix_source_dir('/tmp/up/autre/', '/tmp/up', null, ['plugin' => 'autre/autre.php']), '/tmp/up/autre/', "autre extension : on n'y touche pas");
    same($u->fix_source_dir('/tmp/up/x/', '/tmp/up', null, []), '/tmp/up/x/', 'thème ou cœur : on n\'y touche pas');
    $fs->ok = false;
    $r = $u->fix_source_dir('/tmp/up/zzz/', '/tmp/up', null, $hook);
    check($r instanceof WP_Error, 'déplacement impossible : erreur plutôt que mauvais dossier');
    $GLOBALS['wp_filesystem'] = null;
    check($u->fix_source_dir('/tmp/up/zzz/', '/tmp/up', null, $hook) instanceof WP_Error, 'sans système de fichiers : erreur');
});

test("mises à jour : inactives pour un fichier seul (mu-plugin)", function () {
    check(SSM_Connector_Updater::applies('ssm-connector/ssm-connector.php'), 'extension dans son dossier');
    check(!SSM_Connector_Updater::applies('ssm-connector.php'), 'fichier seul : pas de mise à jour');
    check(SSM_Connector_Updater::current() !== null, "l'instance est créée au chargement");
});

test("logo : icônes livrées avec l'extension", function () {
    $icons = SSM_Connector::icon_urls();
    same(array_keys($icons), ['1x', '2x', 'svg'], 'trois formats');
    $dir = dirname(__DIR__) . '/assets/';
    $s128 = getimagesize($dir . 'icon-128x128.png');
    $s256 = getimagesize($dir . 'icon-256x256.png');
    same([$s128[0], $s128[1], $s128['mime']], [128, 128, 'image/png'], 'icon-128x128.png');
    same([$s256[0], $s256[1], $s256['mime']], [256, 256, 'image/png'], 'icon-256x256.png');
    $svg = file_get_contents($dir . 'icon.svg');
    check(strpos($svg, '<svg') === 0 && strpos($svg, 'viewBox="0 0 256 256"') !== false, 'icon.svg est un SVG carré');
    check(stripos($svg, '<script') === false && stripos($svg, 'onload') === false, 'SVG sans script');
});


// ---------------------------------------------------------------------------------------------------------------
// Constantes de wp-config.php : sous-processus séparés (une constante ne se redéfinit pas)
// ---------------------------------------------------------------------------------------------------------------

foreach (['valid', 'invalid'] as $mode) {
    echo "- constantes de wp-config.php ($mode)\n";
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/constants.php') . ' ' . $mode . ' 2>&1', $out, $code);
    foreach ($out as $line) {
        echo "  $line\n";
    }
    check($code === 0, "phase constantes ($mode)");
}

// === Mises a jour demandees par SSM Core ===

test("mise a jour : rien n'est execute sans commande", function () use ($ssm) {
    same($ssm->collect_inventory()['results'], [], 'aucun compte rendu au repos');
    check(!is_dir(WP_PLUGIN_DIR . '/akismet-9.0.0'), 'aucune sauvegarde sans commande');
});

test("mise a jour : un compte rendu.part, puis il ne repart plus", function () use ($ssm) {
    $ssm->queue_result(7, 'success', null, '9.1.2');
    $envoyes = $ssm->collect_inventory()['results'];
    same($envoyes, [['update_id' => 7, 'status' => 'success', 'version' => '9.1.2']], 'compte rendu transmis');
    same($ssm->collect_inventory()['results'], [], 'un compte rendu ne repart pas deux fois');
});

test("mise a jour : le compte rendu ne contient aucune donnée personnelle", function () use ($ssm) {
    $ssm->queue_result(7, 'failed', 'permission denied sur wp-content/plugins');
    $json = json_encode($ssm->collect_inventory()['results']);
    foreach (['admin_email', 'user', 'login', 'password', 'token', 'ip'] as $forbidden) {
        check(stripos($json, $forbidden) === false, "pas de « $forbidden » dans un compte rendu");
    }
    check(isset($json[0]) && strpos($json, 'update_id') !== false, 'le compte rendu porte bien son identifiant');
});

test("mise a jour : un slug qui sort du dossier des extensions est refuse", function () use ($ssm) {
    // Le slug vient de SSM, donc d'un tiers : sans controle, il designerait n'importe quel chemin.
    $ssm->apply_commands([['id' => 1, 'kind' => 'update_extension', 'slug' => '../../wp-config', 'to_version' => '1.0']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'slug hostile refusé');
    check(strpos($r['error'], 'refusé') !== false, 'la raison est explicite');
});

test("mise a jour : une commande inconnue est refusee, pas executee", function () use ($ssm) {
    $ssm->apply_commands([['id' => 2, 'kind' => 'drop_database', 'slug' => 'akismet', 'to_version' => '9']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'commande inconnue refusée');
    check(strpos($r['error'], 'inconnue') !== false, 'la raison nomme la commande');
});

test("mise a jour : une extension absente est signalee, pas devinée", function () use ($ssm) {
    $ssm->apply_commands([['id' => 3, 'kind' => 'update_extension', 'slug' => 'inexistant', 'to_version' => '9']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'extension absente');
    check(stripos($r['error'], 'introuvable') !== false, 'la raison dit ce qui manque');
});

test("mise a jour : une version cible absente est refusee", function () use ($ssm) {
    $ssm->apply_commands([['id' => 4, 'kind' => 'update_extension', 'slug' => 'akismet']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'version cible absente');
});

test("mise a jour : deja a jour, on ne degrade rien", function () use ($ssm) {
    // WooCommerce est en 9.1.2 et la commande demande 9.1.0 : rien à faire.
    $ssm->apply_commands([['id' => 5, 'kind' => 'update_extension', 'slug' => 'woocommerce', 'to_version' => '9.1.0']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'success', 'deja a jour');
    same($r['version'], '9.1.2', 'la version installée est rapportée');
});

test("mise a jour : sauvegarde avant modification, et restauration si ça échoue", function () use ($ssm) {
    $GLOBALS['ssm_upgrade_ok'] = false;   // l'upgrader échoue
    $ssm->apply_commands([['id' => 6, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    $sauvegardes = glob(WP_PLUGIN_DIR . '/' . SSM_Connector::BACKUP_DIR . '/akismet-*', GLOB_ONLYDIR) ?: [];
    check(count($sauvegardes) >= 1, 'une sauvegarde existe avant toute modification');
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'l\'échec est rapporté, pas masqué');
    check(stripos($r['error'], 'remis') !== false || stripos($r['error'], 'restauration') !== false,
        "le compte rendu dit ce qu'est devenu le site");
    $GLOBALS['ssm_upgrade_ok'] = true;
});

test("mise a jour : sans sauvegarde possible, on n'essaie pas", function () use ($ssm) {
    // La couture est l'objet lui-meme : le global n'est lu qu'au reinitialisation.
    SSM_Connector::$disk_check = function ($path) { return false; };
    $ssm->apply_commands([['id' => 8, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'refus sans sauvegarde');
    check(stripos($r['error'], 'annul') !== false, 'la mise a jour est explicitement annulee');
    check(count($GLOBALS['ssm_upgraded']) === 0, "l'upgrader n'a pas ete appele");
    SSM_Connector::$disk_check = null;
});

test("mise a jour : la version installée est relue sur le disque", function () use ($ssm) {
    // L'upgrader « réussit » mais n'installe rien : c'est le cas quand le paquet est vide ou
    // corrompu. Se fier au retour de l'upgrader ferait croire a une reussite.
    $GLOBALS['ssm_disk_version'] = '5.3';
    $ssm->apply_commands([['id' => 9, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'rien n\'a été installé, donc échec');
    $GLOBALS['ssm_disk_version'] = null;
});

test("mise a jour : les sauvegardes s'accumulent pas indefiniment", function () use ($ssm) {
    for ($i = 0; $i < 6; $i++) {
        $GLOBALS['ssm_upgrade_ok'] = false;
        $ssm->apply_commands([['id' => 100 + $i, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.0.' . $i]]);
        $GLOBALS['ssm_opts']['ssm_update_results'] = [];
    }
    $GLOBALS['ssm_upgrade_ok'] = true;
    $garde = glob(WP_PLUGIN_DIR . '/' . SSM_Connector::BACKUP_DIR . '/akismet-*', GLOB_ONLYDIR) ?: [];
    check(count($garde) <= SSM_Connector::BACKUPS_KEPT + 1, 'les anciennes sauvegardes sont purgées ('
        . count($garde) . ' conservées, plafond ' . (SSM_Connector::BACKUPS_KEPT + 1) . ')');
});

// ---------------------------------------------------------------------------------------------------------------
// Contrat 3 (0.5.0) : capacités, thèmes, cœur, contrôle de santé, actions, erreurs PHP, connexion directe, sauvegarde

function action_result($i = 0) {
    return $GLOBALS['ssm_opts']['ssm_command_results'][$i] ?? null;
}

test("contrat 3 : capacités annoncées, sans « login » tant que la constante n'est pas posée", function () use ($ssm) {
    $inv = $ssm->collect_inventory();
    foreach (['update_extension', 'update_theme', 'update_core', 'plugin_install', 'php_errors'] as $c) {
        check(in_array($c, $inv['capabilities'], true), "capacité $c");
    }
    check(!in_array('login', $inv['capabilities'], true), 'connexion directe absente par défaut');
    same($inv['login_enabled'], false, 'login_enabled faux');
    check(!array_key_exists('cms_latest_version', $inv), "pas de version du cœur tant que WordPress n'a pas vérifié");
    set_site_transient('update_core', (object) ['updates' => [(object) ['response' => 'upgrade', 'current' => '6.7']]]);
    same($ssm->collect_inventory()['cms_latest_version'], '6.7', 'version du cœur proposée');
    set_site_transient('update_core', (object) ['updates' => [(object) ['response' => 'latest', 'current' => '6.6.1']]]);
    same($ssm->collect_inventory()['cms_latest_version'], null, 'à jour : null explicite');
});

test("mise a jour : un site cassé après la mise à jour est remis à la version précédente", function () use ($ssm) {
    $appels = 0;
    SSM_Connector::$health_check = function () use (&$appels) {
        $appels++;
        return $appels === 1 ? ['ok' => true, 'code' => 200, 'reason' => 'HTTP 200'] : ['ok' => false, 'code' => 500, 'reason' => 'HTTP 500'];
    };
    $GLOBALS['ssm_plugin_upgrade_version'] = '9.9.9';
    $ssm->apply_commands([['id' => 21, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'échec rapporté');
    check(strpos($r['error'], 'HTTP 500') !== false && strpos($r['error'], 'remis à la version précédente') !== false, 'cause et retour arrière dits');
});

test("mise a jour : un site déjà en panne avant n'est pas « remis » à tort", function () use ($ssm) {
    SSM_Connector::$health_check = function () { return ['ok' => false, 'code' => 500, 'reason' => 'HTTP 500']; };
    $GLOBALS['ssm_plugin_upgrade_version'] = '9.9.9';
    $ssm->apply_commands([['id' => 22, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    same($GLOBALS['ssm_opts']['ssm_update_results'][0]['status'], 'success', 'la mise à jour n\'est pas la cause');
});

test("thème : mise à jour, version relue, et retour arrière si l'upgrader échoue", function () use ($ssm) {
    $GLOBALS['ssm_theme_upgrade_version'] = '1.3';
    $ssm->apply_commands([['id' => 30, 'kind' => 'update_theme', 'slug' => 'twentytwentyfour', 'to_version' => '1.3']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same([$r['status'], $r['version']], ['success', '1.3'], 'thème mis à jour');
    $GLOBALS['ssm_opts']['ssm_update_results'] = [];
    $GLOBALS['ssm_upgrade_ok'] = false;
    $ssm->apply_commands([['id' => 31, 'kind' => 'update_theme', 'slug' => 'child', 'to_version' => '0.6']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'échec rapporté');
    check(strpos($r['error'], 'thème remis') !== false, 'thème restauré');
    check(is_file(get_theme_root() . '/child/style.css'), 'le thème est toujours là');
    $ssm->apply_commands([['id' => 32, 'kind' => 'update_theme', 'slug' => '../x', 'to_version' => '1']]);
    same($GLOBALS['ssm_opts']['ssm_update_results'][1]['status'], 'failed', 'slug hostile refusé');
});

test("cœur : mise à jour, et site cassé dit sans retour arrière prétendu", function () use ($ssm) {
    $ssm->apply_commands([['id' => 40, 'kind' => 'update_core', 'to_version' => '6.7']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same([$r['status'], $r['version']], ['success', '6.7'], 'cœur mis à jour, version relue sur le disque');
    $GLOBALS['ssm_opts']['ssm_update_results'] = [];
    $appels = 0;
    SSM_Connector::$health_check = function () use (&$appels) {
        $appels++;
        return $appels === 1 ? ['ok' => true, 'code' => 200, 'reason' => 'ok'] : ['ok' => false, 'code' => 500, 'reason' => 'HTTP 500'];
    };
    $ssm->apply_commands([['id' => 41, 'kind' => 'update_core', 'to_version' => '6.8']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'site cassé rapporté');
    check(strpos($r['error'], 'pas de retour arrière') !== false, 'aucun retour arrière prétendu');
});

test("actions : activer, désactiver, installer, supprimer — comptes rendus séparés", function () use ($ssm) {
    $ssm->apply_commands([
        ['id' => 1, 'ref' => 'command', 'kind' => 'plugin_deactivate', 'slug' => 'akismet'],
        ['id' => 2, 'ref' => 'command', 'kind' => 'plugin_delete', 'slug' => 'akismet'],
        ['id' => 3, 'ref' => 'command', 'kind' => 'plugin_install', 'slug' => 'wordfence'],
        ['id' => 4, 'ref' => 'command', 'kind' => 'plugin_activate', 'slug' => 'wordfence'],
        ['id' => 5, 'ref' => 'command', 'kind' => 'plugin_delete', 'slug' => 'woocommerce'],
        ['id' => 6, 'ref' => 'command', 'kind' => 'plugin_install', 'slug' => 'inconnue'],
        ['id' => 7, 'ref' => 'command', 'kind' => 'format_disk', 'slug' => 'x'],
    ]);
    $r = $GLOBALS['ssm_opts']['ssm_command_results'];
    same(array_column($r, 'status'), ['success', 'success', 'success', 'success', 'failed', 'failed', 'failed'], 'statuts');
    check(!isset($GLOBALS['ssm_plugins']['akismet/akismet.php']), 'akismet supprimée');
    check(isset($GLOBALS['ssm_plugins']['wordfence/wordfence.php']), 'wordfence installée');
    check(strpos($r[4]['error'], 'active') !== false, "une extension active n'est jamais supprimée");
    check(empty($GLOBALS['ssm_opts']['ssm_update_results']), "aucun compte rendu n'est pris pour une mise à jour");
    $taken = $ssm->collect_inventory()['command_results'];
    same(count($taken), 7, 'partent au heartbeat suivant');
    same($ssm->collect_inventory()['command_results'], [], 'puis ne repartent plus');
});

test("actions : le connecteur ne se désactive pas lui-même, et le paquet doit venir de wordpress.org", function () use ($ssm) {
    $GLOBALS['ssm_plugins']['ssm-connector/ssm-connector.php'] = ['Name' => 'SSM Connector', 'Version' => '0.5.0'];
    $ssm->apply_commands([['id' => 1, 'ref' => 'command', 'kind' => 'plugin_deactivate', 'slug' => 'ssm-connector']]);
    same(action_result()['status'], 'failed', 'refus de se désactiver');
    $GLOBALS['ssm_download_link'] = 'https://evil.example/x.zip';
    $ssm->apply_commands([['id' => 2, 'ref' => 'command', 'kind' => 'plugin_install', 'slug' => 'piege']]);
    same(action_result(1)['status'], 'failed', 'paquet hors wordpress.org refusé');
});

test("erreurs PHP : journal lu par morceaux, chemins relatifs, regroupement", function () use ($ssm) {
    $log = WP_CONTENT_DIR . '/php-errors.log';
    ini_set('error_log', $log);
    file_put_contents($log, '');
    update_option(SSM_Connector::OPT_LOG_OFFSET, 0);
    file_put_contents($log,
        "[08-Oct-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in " . ABSPATH . "wp-content/plugins/x/x.php:12\n"
        . "Stack trace:\n#0 {main}\n"
        . "[08-Oct-2026 10:01:00 UTC] PHP Warning:  Undefined variable \$a in " . ABSPATH . "wp-content/themes/t/functions.php on line 7\n"
        . "[08-Oct-2026 10:02:00 UTC] PHP Warning:  Undefined variable \$a in " . ABSPATH . "wp-content/themes/t/functions.php on line 7\n");
    $errors = $ssm->collect_inventory()['php_errors'];
    same(count($errors), 2, 'deux erreurs distinctes');
    same([$errors[0]['level'], $errors[0]['file'], $errors[0]['line']], ['fatal', 'wp-content/plugins/x/x.php', 12], 'fatale, chemin relatif');
    same($errors[1]['count'], 2, 'avertissement compté deux fois');
    check(strpos(json_encode($errors), ABSPATH) === false, 'aucun chemin absolu du serveur');
    same($ssm->collect_inventory()['php_errors'], [], 'rien de neuf au heartbeat suivant');
    file_put_contents($log, "[08-Oct-2026 11:00:00 UTC] PHP Parse error:  syntax error in " . ABSPATH . "a.php on line 3\n", FILE_APPEND);
    $suite = $ssm->collect_inventory()['php_errors'];
    // en cas d'échec, le journal dit quelle erreur réelle de PHP s'y est glissée (php.ini de production : log_errors=On)
    same($suite[0]['level'] ?? null, 'parse', 'seule la suite est lue (journal : ' . substr((string) file_get_contents($log), -700) . ')');
    ini_restore('error_log');
});

test("connexion directe : fermée par défaut, aucun crochet public posé", function () use ($ssm) {
    check(empty($GLOBALS['ssm_hooks']['login_init']), 'pas de crochet login_init sans la constante');
    same($ssm->verify_login_token('a.b'), 'connexion directe désactivée sur ce site', 'jeton refusé');
});

function login_token($key, $claims) {
    $b64 = function ($raw) { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); };
    $payload = $b64(json_encode($claims));
    return $payload . '.' . $b64(hash_hmac('sha256', $payload, $key, true));
}

test("connexion directe : clé reçue de SSM, jeton vérifié, usage unique, expiration, autre site", function () use ($ssm) {
    SSM_Connector::$login_allowed = true;
    configure();
    $key = 'cle-de-connexion-0123456789abcdefghijkl';
    core_replies(200, ['site_id' => 7, 'status' => 'accepted', 'commands' => [], 'login_key' => $key]);
    check($ssm->send_heartbeat(), 'heartbeat accepté');
    same($ssm->login_key(), $key, 'clé conservée (chiffrée)');
    check(strpos(all_stored_text(), $key) === false, 'la clé n\'est jamais stockée en clair');
    $inv = $ssm->collect_inventory();
    same($inv['login_key_fingerprint'], substr(hash('sha256', $key), 0, 16), 'empreinte annoncée');
    check(in_array('login', $inv['capabilities'], true), 'capacité login');

    $ok = login_token($key, ['s' => 7, 'u' => 'tech', 'exp' => time() + 60, 'n' => 'nonce-1']);
    $u = $ssm->verify_login_token($ok);
    check(is_object($u) && $u->ID === 1, 'administrateur connecté');
    same($ssm->verify_login_token($ok), 'lien déjà utilisé', 'usage unique');
    same($ssm->verify_login_token(login_token($key, ['s' => 7, 'u' => 't', 'exp' => time() - 1, 'n' => 'n2'])), 'lien expiré (60 secondes)', 'expiré');
    same($ssm->verify_login_token(login_token($key, ['s' => 8, 'u' => 't', 'exp' => time() + 30, 'n' => 'n3'])), 'lien destiné à un autre site', 'autre site');
    same($ssm->verify_login_token(login_token('autre-cle', ['s' => 7, 'exp' => time() + 30, 'n' => 'n4'])), 'signature invalide', 'signature');
    same($ssm->verify_login_token(login_token($key, ['s' => 7, 'exp' => time() + 3600, 'n' => 'n5'])), 'expiration invalide', 'jeton trop long');
    same(get_option(SSM_Connector::OPT_LOGIN_LOG)[0]['by'], 'tech', 'connexion journalisée');
});

test("sauvegarde : base et fichiers dans l'archive, médias exclus sur demande, dossier de travail nettoyé", function () use ($ssm) {
    $deposes = [];
    SSM_Connector::$uploader = function ($url, $path, $size) use (&$deposes) {
        $zip = new ZipArchive();
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $sql = $zip->getFromName('database.sql');
        $zip->close();
        $deposes[] = ['url' => $url, 'names' => $names, 'sql' => $sql, 'size' => $size];
        return ['ok' => true, 'error' => null];
    };
    $ssm->apply_commands([['id' => 9, 'ref' => 'command', 'kind' => 'backup_site',
        'params' => ['upload_url' => 'https://s3.example/b/k.zip?X-Amz-Signature=x', 'include_uploads' => false, 'max_bytes' => 100000000]]]);
    $r = action_result();
    if (!class_exists('ZipArchive')) {       // PHP sans l'extension zip : échec dit, rien de tenté
        same($r['status'], 'failed', 'sans zip : sauvegarde refusée');
        check(strpos($r['error'] ?? '', 'zip') !== false, 'sans zip : raison donnée');
        return;
    }
    same($r['status'], 'success', 'sauvegarde rapportée réussie');
    check($r['data']['size_bytes'] > 0 && strlen($r['data']['sha256']) === 64, 'taille et empreinte');
    same($r['data']['tables'], 2, 'deux tables exportées');
    $d = $deposes[0];
    check(in_array('database.sql', $d['names'], true) && in_array('wp-content/index.php', $d['names'], true), 'base et fichiers');
    check(!in_array('wp-content/uploads/2026/photo.jpg', $d['names'], true), 'médias exclus sur demande');
    check(!in_array('wp-content/cache/page.html', $d['names'], true), 'cache jamais sauvegardé');
    check(strpos($d['sql'], "'l\\'été\\n'") !== false && strpos($d['sql'], 'NULL') !== false, 'valeurs SQL échappées');
    check(!is_dir(WP_CONTENT_DIR . '/' . SSM_Connector::BACKUP_TMP), 'dossier de travail supprimé');
});

test("sauvegarde : un dépôt refusé est un échec, et le dossier de travail est quand même nettoyé", function () use ($ssm) {
    if (!class_exists('ZipArchive')) {
        return;                          // sans zip, rien n'est déposé : cas couvert par le test précédent
    }
    SSM_Connector::$uploader = function () { return ['ok' => false, 'error' => 'HTTP 403 SignatureDoesNotMatch']; };
    $ssm->apply_commands([['id' => 10, 'ref' => 'command', 'kind' => 'backup_site', 'params' => ['upload_url' => 'https://s3.example/k.zip']]]);
    same(action_result()['status'], 'failed', 'échec');
    check(strpos(action_result()['error'], 'SignatureDoesNotMatch') !== false, 'raison du stockage rapportée');
    check(!is_dir(WP_CONTENT_DIR . '/' . SSM_Connector::BACKUP_TMP), 'nettoyé');
    $ssm->apply_commands([['id' => 11, 'ref' => 'command', 'kind' => 'backup_site', 'params' => ['upload_url' => 'file:///etc/passwd']]]);
    same(action_result(1)['status'], 'failed', 'adresse non http refusée');
});

test("mise a jour : WordPress reçoit le fichier principal de l'extension, pas son dossier", function () use ($ssm) {
    // Constaté sur un vrai WordPress : Plugin_Upgrader::upgrade('akismet') est refusé sans détail.
    $GLOBALS['ssm_plugin_upgrade_version'] = '9.9.9';
    $ssm->apply_commands([['id' => 50, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    same($GLOBALS['ssm_upgraded'], ['akismet/akismet.php'], 'fichier principal transmis');
    same($GLOBALS['ssm_opts']['ssm_update_results'][0]['status'], 'success', 'mise à jour réussie');
});

test("extension d'un seul fichier : action, mise à jour, sauvegarde et restauration", function () use ($ssm) {
    file_put_contents(WP_PLUGIN_DIR . '/hello.php', "<?php\n/* Version: 1.7.2 */\n");
    $ssm->apply_commands([['id' => 60, 'ref' => 'command', 'kind' => 'plugin_activate', 'slug' => 'hello']]);
    same(action_result()['status'], 'success', 'hello.php activée');
    $GLOBALS['ssm_upgrade_ok'] = false;
    $ssm->apply_commands([['id' => 61, 'kind' => 'update_extension', 'slug' => 'hello', 'to_version' => '1.8']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    check(strpos($r['error'], 'remis à la version précédente') !== false, 'sauvegardée puis restaurée : ' . $r['error']);
    check(is_file(WP_PLUGIN_DIR . '/hello.php'), 'le fichier est toujours là');
});

test("erreurs PHP : « in fichier:ligne » quitte le message, et une fatale n'est pas comptée deux fois", function () use ($ssm) {
    $ssm->record_php_error('fatal', "Uncaught Error: x() in " . ABSPATH . "wp-content/a.php:3\nStack trace:", null, null);
    $e = $ssm->take_php_errors()[0];
    same([$e['message'], $e['file'], $e['line']], ['Uncaught Error: x()', 'wp-content/a.php', 3], 'message, fichier et ligne séparés');
});

test("mise a jour hors tâche planifiée : l'extension active le reste", function () use ($ssm) {
    $GLOBALS['ssm_deactivate_on_upgrade'] = true;
    $GLOBALS['ssm_plugin_upgrade_version'] = '9.9.9';
    $ssm->apply_commands([['id' => 70, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    same($GLOBALS['ssm_opts']['ssm_update_results'][0]['status'], 'success', 'mise à jour réussie');
    check(is_plugin_active('akismet/akismet.php'), 'réactivée après la mise à jour');
});

test("mise a jour : une réactivation impossible est un échec, avec retour arrière", function () use ($ssm) {
    $GLOBALS['ssm_deactivate_on_upgrade'] = true;
    $GLOBALS['ssm_plugin_upgrade_version'] = '9.9.9';
    $GLOBALS['ssm_activation_fails'] = true;
    $ssm->apply_commands([['id' => 71, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    $r = $GLOBALS['ssm_opts']['ssm_update_results'][0];
    same($r['status'], 'failed', 'échec');
    check(strpos($r['error'], 'réactivation impossible') !== false && strpos($r['error'], 'remis') !== false, $r['error']);
});

test("contrôle de santé : avant la mise à jour tout de suite, après en laissant l'OPcache relire les fichiers", function () use ($ssm) {
    $appels = [];
    SSM_Connector::$health_check = function ($after = false) use (&$appels) {
        $appels[] = $after;
        return ['ok' => true, 'code' => 200, 'reason' => 'HTTP 200'];
    };
    $GLOBALS['ssm_plugin_upgrade_version'] = '9.9.9';
    $ssm->apply_commands([['id' => 80, 'kind' => 'update_extension', 'slug' => 'akismet', 'to_version' => '9.9.9']]);
    same($appels, [false, true], 'avant : immédiat ; après : différé');
});

test("connexion directe : case de la page de l'extension et administrateur choisi", function () use ($ssm) {
    ssm_reset();
    $GLOBALS['ssm_users'] = [(object) ['ID' => 1, 'user_login' => 'admin', 'display_name' => 'Admin'],
                             (object) ['ID' => 4, 'user_login' => 'fred', 'display_name' => 'Fred']];
    same(SSM_Connector::login_allowed(), false, 'fermée par défaut');
    check(strpos(page_html($ssm), 'name="ssm_login_allowed" value="1" />') !== false, 'case décochée dans la page');
    same($ssm->save_login_settings(true, 4), 'Connexion directe ouverte : prête après deux envois à SSM (clé remise, puis confirmée).', 'ouverte');
    same(SSM_Connector::login_allowed(), true, 'ouverte par la case');
    $html = page_html($ssm);
    check(strpos($html, 'name="ssm_login_allowed" value="1" checked') !== false && strpos($html, '<option value="4" selected>Fred (fred)</option>') !== false, 'page remplie');
    check(strpos($html, 'nonce-ssm_connector_login') !== false, 'formulaire protégé par un nonce');
    same($ssm->login_user()->ID, 4, "c'est cet administrateur qui sera connecté");
    same($ssm->save_login_settings(true, 99), 'Administrateur inconnu.', 'administrateur inconnu refusé');
    $GLOBALS['ssm_users'] = [(object) ['ID' => 1, 'user_login' => 'admin']];
    same($ssm->login_user(), null, "plus administrateur : personne n'est connecté, pas un autre");

    update_option(SSM_Connector::OPT_LOGIN_KEY, 'x');
    same($ssm->save_login_settings(false, 0), 'Connexion directe fermée.', 'refermée');
    same([SSM_Connector::login_allowed(), get_option(SSM_Connector::OPT_LOGIN_KEY)], [false, false], 'clé effacée aussitôt');
    update_option(SSM_Connector::OPT_LOGIN_KEY, 'y');
    configure();
    core_replies(200, ['site_id' => 7, 'status' => 'accepted', 'commands' => [], 'login_key' => 'z']);
    $ssm->send_heartbeat();
    same(get_option(SSM_Connector::OPT_LOGIN_KEY), false, 'fermée : la clé envoyée par SSM est refusée et effacée');
});

test("connexion directe : prête dès l'enregistrement de la case, sans attendre deux envois horaires", function () use ($ssm) {
    ssm_reset();
    configure();
    $GLOBALS['ssm_users'] = [(object) ['ID' => 1, 'user_login' => 'admin', 'display_name' => 'Admin']];
    $envois = [];
    $GLOBALS['ssm_http'] = function ($url, $args) use (&$envois) {
        $body = json_decode($args['body'], true);
        $envois[] = $body['login_key_fingerprint'] ?? null;
        // SSM : remet la clé tant que l'empreinte ne correspond pas, puis plus rien
        $fp = substr(hash('sha256', 'cle-de-ssm'), 0, 16);
        $reply = ['site_id' => 7, 'status' => 'accepted', 'commands' => []];
        if (($body['login_key_fingerprint'] ?? null) !== $fp) {
            $reply['login_key'] = 'cle-de-ssm';
        }
        return ['code' => 200, 'body' => json_encode($reply)];
    };
    $ssm->save_login_settings(true, 1);
    same($ssm->sync_login_now(), 'Connexion directe ouverte et prête : SSM peut ouvrir une session sur ce site.', 'prête');
    same(count($envois), 2, 'deux envois tout de suite');
    same([$envois[0], $envois[1]], [null, substr(hash('sha256', 'cle-de-ssm'), 0, 16)], 'le second annonce la clé reçue au premier');

    core_replies(500, []);
    same(strpos($ssm->sync_login_now(), 'SSM n\'a pas répondu') !== false, true, 'SSM muet : le message le dit');
});

test("une nouvelle version de l'extension est annoncée à SSM sans attendre l'envoi horaire", function () use ($ssm) {
    ssm_reset();
    $ssm->announce_new_version();
    same($GLOBALS['ssm_single_events'], [[SSM_Connector::HEARTBEAT_HOOK, ['nouvelle-version']]], 'un envoi immédiat');
    $ssm->announce_new_version();
    same(count($GLOBALS['ssm_single_events']), 1, 'une seule fois par version');
});

// En dernier : une constante PHP ne se retire plus une fois définie.
test("connexion directe : la constante de wp-config.php l'emporte sur la case", function () use ($ssm) {
    ssm_reset();
    update_option(SSM_Connector::OPT_LOGIN_ALLOWED, 1);
    define('SSM_CONNECTOR_ALLOW_LOGIN', false);
    same(SSM_Connector::login_allowed(), false, 'constante à false : fermée malgré la case');
    same($ssm->save_login_settings(true, 0), 'Réglage imposé par SSM_CONNECTOR_ALLOW_LOGIN dans wp-config.php.', 'réglage refusé');
    $html = page_html($ssm);
    check(strpos($html, 'name="ssm_login_allowed" value="1" disabled') !== false && strpos($html, 'Fermée et verrouillée') !== false, 'case grisée, verrou affiché');
});

echo "\n$checks vérifications, $failures échec(s)\n";
exit($failures === 0 ? 0 : 1);
