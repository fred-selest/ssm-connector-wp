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

function request_with_core($status, $body = []) {
    $GLOBALS['ssm_http'] = function ($url, $args) use ($status, $body) {
        return ['code' => $status, 'body' => json_encode($body)];
    };
}

function configure() {
    update_option(SSM_CONNECTOR_OPTION_URL, 'https://ssm.exemple.fr');
    update_option(SSM_CONNECTOR_OPTION_TOKEN, str_repeat('a', 43));
}

test('le fichier se charge et branche ses crochets', function () {
    foreach (['ssm_heartbeat_event', 'activated_plugin', 'deactivated_plugin', 'admin_init', 'admin_post_ssm_connector_test', 'rest_api_init'] as $hook) {
        check(!empty($GLOBALS['ssm_hooks'][$hook]), "crochet $hook");
    }
    $activated = $GLOBALS['ssm_hooks']['activated_plugin'][0][1];
    $deactivated = $GLOBALS['ssm_hooks']['deactivated_plugin'][0][1];
    check($activated !== $deactivated, 'activation et désactivation ont chacune leur méthode');
});

test("charger le fichier une 2e fois (mu-plugin + plugin) ne provoque pas de doublon", function () {
    $hooks_before = count($GLOBALS['ssm_hooks']['rest_api_init']);
    require dirname(__DIR__) . '/ssm-connector.php';
    same(count($GLOBALS['ssm_hooks']['rest_api_init']), $hooks_before, 'crochets non dupliqués');
});

test("la classe est déclarée dans un bloc conditionnel (sinon opcache la déclare d'avance : fatal au double chargement)", function () {
    // Constaté sur un vrai WordPress : « Cannot declare class SSM_Connector » quand le fichier est chargé deux fois.
    // Sans opcache (php en ligne de commande), le fatal n'apparaît pas : on contrôle donc la structure du code.
    $tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/ssm-connector.php'));
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $previous = null;
    $significant = [];
    foreach ($tokens as $t) {
        if (is_array($t) && in_array($t[0], $skip, true)) {
            continue;
        }
        $significant[] = $t;
    }
    $found = false;
    foreach ($significant as $i => $t) {
        if (is_array($t) && $t[0] === T_CLASS && is_array($significant[$i + 1]) && $significant[$i + 1][1] === 'SSM_Connector') {
            $found = true;
            $previous = $significant[$i - 1];
            check($previous === ':' || $previous === '{', 'la déclaration suit « if (...) : » ou « { », pas une instruction terminée');
        }
    }
    check($found, 'déclaration de la classe trouvée');
});

test("l'inventaire respecte le contrat de SSM Core", function () use ($ssm) {
    $inv = $ssm->collect_inventory();
    check(isset($inv['cms_version']) && $inv['cms_version'] !== '', 'cms_version');
    check(isset($inv['extensions']) && is_array($inv['extensions']), 'clé extensions (et non plugins)');
    check(!array_key_exists('plugins', $inv), "pas de clé 'plugins' ignorée par Core");
    same(count($inv['extensions']), 4, 'quatre extensions');
    same(count($inv['themes']), 2, 'deux thèmes');
    check(preg_match('/^\d+\.\d+\.\d+$/', $inv['php_version']) === 1, 'php_version à trois nombres');
    same($inv['db_version'], '8.0.36', 'db_version');
    check(!empty($inv['hostname']) && !empty($inv['site_path']), 'hostname et site_path');
    same($inv['plugin_count'], 4, 'plugin_count');
    same($inv['plugin_active_count'], 3, 'plugin_active_count');
    same($inv['users_count'], 7, 'users_count');
    check_core_limits($inv);
});

test("slugs d'extensions et de thèmes", function () use ($ssm) {
    $inv = $ssm->collect_inventory();
    $slugs = array_column($inv['extensions'], 'slug');
    same($slugs, ['akismet', 'woocommerce', 'inactive-demo', 'hello'], 'dossier, ou nom du fichier pour un plugin seul');
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
    same($themes['twentytwentyfour']['update_available'], true, 'thème à mettre à jour');
    same($themes['twentytwentyfour']['latest_version'], '1.3', 'version du thème');
    same($themes['child']['update_available'], false, 'thème à jour');
});

test('aucune donnée de mise à jour (transient absent) : pas d\'erreur', function () use ($ssm) {
    $inv = $ssm->collect_inventory();   // get_site_transient() renvoie false
    foreach ($inv['extensions'] as $e) {
        same($e['update_available'], false, 'pas de MAJ pour ' . $e['slug']);
    }
});

test('événements : activation et désactivation bien distinguées', function () use ($ssm) {
    $ssm->on_plugin_activated('akismet/akismet.php', false);
    $ssm->on_plugin_deactivated('akismet/akismet.php', false);
    $types = array_column(get_option(SSM_Connector::OPT_EVENTS), 'type');
    same($types, ['plugin_activated', 'plugin_deactivated'], 'types');
});

test("événements : la file est plafonnée", function () use ($ssm) {
    for ($i = 0; $i < 130; $i++) {
        $ssm->on_user_login_failed("u$i");
    }
    $events = get_option(SSM_Connector::OPT_EVENTS);
    same(count($events), SSM_Connector::MAX_EVENTS, 'au plus 100 événements');
    same(end($events)['payload']['user'], 'u129', 'on garde les plus récents');
});

test("l'URL de SSM Core est contrôlée", function () use ($ssm) {
    update_option(SSM_CONNECTOR_OPTION_URL, 'https://ancien.exemple.fr');
    same($ssm->sanitize_url('https://ssm.exemple.fr/'), 'https://ssm.exemple.fr', 'slash final retiré');
    same($ssm->sanitize_url("  http://10.0.0.5:8000 "), 'http://10.0.0.5:8000', 'http et port acceptés');
    same($ssm->sanitize_url(''), '', 'vide = désactivé');
    same($GLOBALS['ssm_settings_errors'], [], 'aucune erreur pour les valeurs valides');
    foreach (['ftp://ssm.exemple.fr', 'javascript:alert(1)', 'ssm.exemple.fr', 'https://u:p@ssm.exemple.fr'] as $bad) {
        same($ssm->sanitize_url($bad), 'https://ancien.exemple.fr', "refusée : $bad (l'ancienne valeur reste)");
    }
    same(count($GLOBALS['ssm_settings_errors']), 4, 'une erreur affichée par valeur refusée');
});

test('le token est contrôlé et un champ vide le conserve', function () use ($ssm) {
    $old = str_repeat('o', 40);
    update_option(SSM_CONNECTOR_OPTION_TOKEN, $old);
    same($ssm->sanitize_token(''), $old, 'vide : on garde');
    same($ssm->sanitize_token(null), $old, 'absent : on garde');
    same($GLOBALS['ssm_settings_errors'], [], 'pas d\'erreur pour un champ vide');
    $new = 'Abc_123-' . str_repeat('x', 35);
    same($ssm->sanitize_token("  $new\n"), $new, 'token valide, espaces retirés');
    same($ssm->sanitize_token('court'), $old, 'trop court : refusé');
    same($ssm->sanitize_token(str_repeat('a', 20) . ' ' . str_repeat('b', 20)), $old, 'avec espace : refusé');
    same($ssm->sanitize_token(str_repeat('a', 300)), $old, 'trop long : refusé');
    same(count($GLOBALS['ssm_settings_errors']), 3, 'une erreur par token refusé');
});

test("le token est créé s'il manque (y compris en mu-plugin)", function () use ($ssm) {
    check(get_option(SSM_CONNECTOR_OPTION_TOKEN) === false, 'pas de token au départ');
    $ssm->schedule_heartbeat();   // wp_loaded : le seul crochet qui existe aussi en mu-plugin
    $token = get_option(SSM_CONNECTOR_OPTION_TOKEN);
    check(is_string($token) && strlen($token) === 64, 'token de 64 caractères créé');
    check(isset($GLOBALS['ssm_scheduled']['ssm_heartbeat_event']), 'envoi horaire planifié');
    $ssm->schedule_heartbeat();
    same(get_option(SSM_CONNECTOR_OPTION_TOKEN), $token, 'un token existant n\'est pas remplacé');
});

test('la désactivation retire la planification', function () use ($ssm) {
    $ssm->schedule_heartbeat();
    $ssm->deactivate();
    check(!isset($GLOBALS['ssm_scheduled']['ssm_heartbeat_event']), 'planification retirée');
});

test("heartbeat : sans URL, un message clair et aucune requête", function () use ($ssm) {
    update_option(SSM_CONNECTOR_OPTION_TOKEN, str_repeat('a', 43));
    same($ssm->send_heartbeat(), false, 'échec');
    same($GLOBALS['ssm_http_calls'], [], 'rien envoyé');
    check(strpos(get_option(SSM_Connector::OPT_LAST_MSG), 'URL') !== false, 'message sur l\'URL');
    same(get_option(SSM_Connector::OPT_LAST_OK), '0', 'statut enregistré');
});

test('heartbeat accepté : en-têtes, corps et file vidée', function () use ($ssm) {
    configure();
    $ssm->on_user_login('admin', (object) ['roles' => ['administrator']]);
    request_with_core(200, ['status' => 'accepted']);
    same($ssm->send_heartbeat(), true, 'succès');
    $call = $GLOBALS['ssm_http_calls'][0];
    same($call['url'], 'https://ssm.exemple.fr/api/v1/heartbeat', 'adresse');
    same($call['args']['headers']['X-SSM-Token'], str_repeat('a', 43), 'token envoyé');
    same($call['args']['redirection'], 0, 'aucune redirection suivie');
    same($call['args']['headers']['X-SSM-Signature'], hash_hmac('sha256', $call['args']['body'], str_repeat('a', 43)), 'signature');
    $body = json_decode($call['args']['body'], true);
    check(isset($body['extensions']) && count($body['extensions']) === 4, 'extensions dans le corps');
    same(count($body['pending_events']), 1, 'événement envoyé');
    same(get_option(SSM_Connector::OPT_EVENTS), [], 'file vidée après acceptation');
    same(get_option(SSM_Connector::OPT_LAST_OK), '1', 'statut OK');
});

test("heartbeat refusé : les événements ne sont pas perdus et le message dit quoi faire", function () use ($ssm) {
    configure();
    $ssm->on_user_login_failed('pirate');
    request_with_core(401, ['detail' => 'Invalid connector token']);
    same($ssm->send_heartbeat(), false, 'échec');
    same(count(get_option(SSM_Connector::OPT_EVENTS)), 1, 'événement conservé');
    $msg = get_option(SSM_Connector::OPT_LAST_MSG);
    check(strpos($msg, '401') !== false && strpos($msg, 'token') !== false, "message 401 : $msg");
    check(strpos($msg, str_repeat('a', 43)) === false, 'le token n\'apparaît jamais dans le message');
});

test('heartbeat 422 : le champ refusé est indiqué', function () use ($ssm) {
    configure();
    request_with_core(422, ['detail' => [['loc' => ['body', 'php_version'], 'msg' => 'String should have at most 20 characters', 'type' => 'string_too_long']]]);
    $ssm->send_heartbeat();
    $msg = get_option(SSM_Connector::OPT_LAST_MSG);
    check(strpos($msg, '422') !== false && strpos($msg, 'php_version') !== false, "message 422 : $msg");
});

test('heartbeat : 404, redirection, 500 et réponse non JSON', function () use ($ssm) {
    configure();
    foreach ([404 => '404', 301 => 'Redirection', 502 => 'Erreur de SSM Core'] as $status => $needle) {
        request_with_core($status);
        same($ssm->send_heartbeat(), false, "échec $status");
        check(strpos(get_option(SSM_Connector::OPT_LAST_MSG), $needle) !== false, "message $status");
    }
    $GLOBALS['ssm_http'] = function () { return ['code' => 502, 'body' => '<html>Bad gateway</html>']; };
    same($ssm->send_heartbeat(), false, 'corps HTML sans erreur PHP');
});

test('heartbeat : SSM Core injoignable', function () use ($ssm) {
    configure();
    $GLOBALS['ssm_http'] = function () { return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host'); };
    same($ssm->send_heartbeat(), false, 'échec');
    check(strpos(get_option(SSM_Connector::OPT_LAST_MSG), 'injoignable') !== false, 'message injoignable');
});

test("un événement arrivé pendant l'envoi reste dans la file", function () use ($ssm) {
    configure();
    $ssm->on_user_login_failed('a');
    $GLOBALS['ssm_http'] = function () use ($ssm) {
        $ssm->on_user_login_failed('b');   // arrive pendant la requête
        return ['code' => 200, 'body' => '{}'];
    };
    $ssm->send_heartbeat();
    $left = get_option(SSM_Connector::OPT_EVENTS);
    same(count($left), 1, 'un seul événement restant');
    same($left[0]['payload']['user'], 'b', 'c\'est le plus récent');
});

test("REST : le token se lit dans X-SSM-Token ou Bearer, jamais dans l'URL", function () use ($ssm) {
    $token = str_repeat('t', 64);
    update_option(SSM_CONNECTOR_OPTION_TOKEN, $token);
    same($ssm->check_token(new FakeRequest(['X-SSM-Token' => $token])), true, 'X-SSM-Token');
    same($ssm->check_token(new FakeRequest(['Authorization' => "Bearer $token"])), true, 'Bearer');
    foreach ([
        new FakeRequest(['X-SSM-Token' => 'faux']),
        new FakeRequest(['Authorization' => 'Bearer faux']),
        new FakeRequest([], ['token' => $token]),    // dans l'URL : refusé
        new FakeRequest(),
    ] as $i => $req) {
        $r = $ssm->check_token($req);
        check($r instanceof WP_Error && $r->data['status'] === 401, "refusé en 401 (cas $i)");
    }
    delete_option(SSM_CONNECTOR_OPTION_TOKEN);
    $r = $ssm->check_token(new FakeRequest(['X-SSM-Token' => 'x']));
    check($r instanceof WP_Error && $r->data['status'] === 500, 'sans token configuré : 500');
});

test('REST : status, heartbeat et extensions répondent', function () use ($ssm) {
    $s = $ssm->rest_status(null);
    same($s['connector_version'], SSM_CONNECTOR_VERSION, 'version');
    same($ssm->rest_heartbeat(null)['status'], 'ok', 'heartbeat');
    same(count($ssm->rest_extensions(null)['extensions']), 4, 'extensions');
});

echo "\n$checks vérifications, $failures échec(s)\n";
exit($failures === 0 ? 0 : 1);
