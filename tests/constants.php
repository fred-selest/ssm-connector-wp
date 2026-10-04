<?php
// Phase à part, lancée par run.php : les constantes de wp-config.php ne peuvent pas être redéfinies dans le même processus.
//   php tests/constants.php valid    SSM_CONNECTOR_URL / SSM_CONNECTOR_TOKEN corrects
//   php tests/constants.php invalid  valeurs refusées (http public, token trop court)

require __DIR__ . '/stubs.php';

$mode = isset($argv[1]) ? $argv[1] : 'valid';
const CONST_TOKEN = 'Const-Token-0123456789-abcdefghijklmnopqrstuv';
if ($mode === 'valid') {
    define('SSM_CONNECTOR_URL', "https://ssm.constante.fr/sites\n");
    define('SSM_CONNECTOR_TOKEN', "  " . CONST_TOKEN . "\n");
} else {
    define('SSM_CONNECTOR_URL', 'http://ssm.exemple.fr');
    define('SSM_CONNECTOR_TOKEN', 'court');
}
require dirname(__DIR__) . '/ssm-connector.php';

$failures = 0;
function check($cond, $label) {
    global $failures;
    if (!$cond) {
        $failures++;
        echo "ÉCHEC : $label\n";
    }
}

ssm_reset();
$ssm = SSM_Connector::instance();
$cfg = $ssm->config();

if ($mode === 'valid') {
    check($cfg['url'] === 'https://ssm.constante.fr' && $cfg['url_source'] === 'constant', 'adresse prise dans wp-config.php et nettoyée : ' . json_encode($cfg));
    check($cfg['token'] === CONST_TOKEN && $cfg['token_source'] === 'constant' && $cfg['problem'] === '', 'token pris dans wp-config.php');

    $GLOBALS['ssm_http'] = function () { return ['code' => 200, 'body' => '{}']; };
    // Les champs du formulaire sont ignorés : la configuration vient de wp-config.php
    $r = $ssm->connect('https://autre.exemple.fr', 'Autre-Token-' . str_repeat('z', 40));
    check($r['ok'], 'connexion : ' . $r['message']);
    $call = $GLOBALS['ssm_http_calls'][0];
    check($call['url'] === 'https://ssm.constante.fr/api/v1/heartbeat', 'envoyé à l\'adresse de wp-config.php : ' . $call['url']);
    check($call['args']['headers']['X-SSM-Token'] === CONST_TOKEN, 'envoyé avec le token de wp-config.php');
    check(get_option(SSM_Connector::OPT_URL, 'absent') === 'absent' && get_option(SSM_Connector::OPT_TOKEN, 'absent') === 'absent', 'rien écrit dans la base de données');
    check(strpos(json_encode($GLOBALS['ssm_opts']), CONST_TOKEN) === false, 'le token n\'est jamais dans la base de données');

    ob_start();
    $ssm->render_admin_page();
    $html = ob_get_clean();
    check(strpos($html, 'SSM_CONNECTOR_TOKEN') !== false && strpos($html, 'SSM_CONNECTOR_URL') !== false, 'la page explique que tout vient de wp-config.php');
    check(substr_count($html, 'disabled') >= 2, 'champs désactivés');
    check(strpos($html, 'class="primary">Connecter') === false, 'pas de bouton Connecter quand tout est verrouillé');
    check(strpos($html, 'Tester la connexion') !== false, 'bouton de test présent');
    check(strpos($html, CONST_TOKEN) === false, 'token absent de la page');
    check(strpos($html, 'jamais écrit dans la base de données') !== false, 'sécurité : token hors base de données');
} else {
    check($cfg['url'] === '' && $cfg['token'] === '', 'valeurs refusées non retenues : ' . json_encode($cfg));
    check($cfg['problem'] !== '' && strpos($cfg['problem'], 'https') !== false, 'problème expliqué : ' . $cfg['problem']);
    check($ssm->send_heartbeat() === false && $GLOBALS['ssm_http_calls'] === [], 'rien n\'est envoyé');
    ob_start();
    $ssm->render_admin_page();
    $html = ob_get_clean();
    check(strpos($html, 'Configuration à corriger') !== false, 'la page signale le problème');
}

exit($failures === 0 ? 0 : 1);
