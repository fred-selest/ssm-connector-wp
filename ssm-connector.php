<?php
/**
 * Plugin Name: SSM Connector
 * Description: Connecteur SSM (Selest Site Manager) : envoie toutes les heures l'inventaire du site à SSM Core et exécute ce que SSM demande (mises à jour sûres, sauvegardes, actions sur les extensions). N'ouvre aucune porte sur le site, sauf la connexion directe si vous l'activez. Se met à jour depuis les releases GitHub.
 * Version: 0.13.0
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

define('SSM_CONNECTOR_VERSION', '0.13.0');
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
    const OPT_RESULTS = 'ssm_update_results';   // comptes rendus des mises a jour, renvoyes au heartbeat suivant
    const OPT_CMD_RESULTS = 'ssm_command_results';   // comptes rendus des actions (contrat 3)
    const OPT_PHP_ERRORS = 'ssm_php_errors';          // erreurs PHP en attente d'envoi
    const OPT_LOG_OFFSET = 'ssm_debug_log_offset';    // position de lecture du journal de PHP
    const OPT_LOGIN_KEY = 'ssm_login_key';            // clé de connexion directe (chiffrée)
    const OPT_VERSION_SENT = 'ssm_connector_version_sent';   // dernière version annoncée à SSM
    const OPT_SITE_ID = 'ssm_site_id';                // identifiant du site chez SSM
    const POLL_HOOK = 'ssm_poll_event';               // question « une action m'attend-elle ? » à SSM
    const POLL_SCHEDULE = 'ssm_two_minutes';
    // 15 s, et non 120 : WP-Cron n'execute que les echeances dues, quand une page le declenche.
    // Avec 120 s, meme en reveillant le site, une commande attendait l'echeance suivante — d'ou les
    // minutes d'attente mesurees en production. A 15 s, une commande demandee est traitee en
    // quelques secondes. Le cout reste nul quand le site est inactif : sans reveil de SSM, ce
    // planificateur n'a rien a executer, et une question « avez-vous quelque chose ? » qui repond
    // non ne declenche aucun envoi complet.
    const POLL_INTERVAL = 15;
    // Session d'ecoute de l'explorateur (canal rapide, SSM 2.33) : une requete longue sur SSM,
    // qui repond des qu'une commande de fichiers arrive. Bornee : elle occupe un processus PHP.
    const FAST_LOCK = 'ssm_fast_session';
    const FAST_SECONDS = 40;      // duree maximale d'une session (surchargeable : SSM_CONNECTOR_SESSION_SECONDS)
    const LIST_MAX = 1000;        // entrees rendues par page (500, sans pagination, avant 0.12.0)
    const FAST_WAIT = 15;         // secondes d'attente par requete longue
    const OPT_LOGIN_LOG = 'ssm_login_log';            // dernières connexions directes
    const OPT_LOGIN_ALLOWED = 'ssm_login_allowed';    // case « Autoriser la connexion directe » (page de l'extension)
    const OPT_LOGIN_USER = 'ssm_login_user';          // administrateur connecté (ID), 0 : le premier administrateur
    const PHP_ERRORS_MAX = 100;
    const LOG_READ_MAX = 524288;                      // 512 Ko de journal lus au plus par heartbeat
    const BACKUP_TMP = 'ssm-backup-tmp';
    const SINGLE_FILE = '__fichier-unique__.php';     // sauvegarde d'une extension d'un seul fichier
    const BACKUP_DIR = 'ssm-backups';
    const BACKUPS_KEPT = 3;                    // combien de sauvegarde garder par extension
    const SCHEMA = '2';

    /**
     * Contrôle d'écriture du disque, remplaçable. `is_writable` est un builtin : sous root il
     * renvoie toujours vrai, et les tests ne peuvent donc pas simuler un `wp-content` non
     * inscriptible sans cette couture.
     */
    public static $disk_check = null;
    /** Coutures de test : connexion directe autorisée, contrôle de santé, dépôt d'une archive. */
    public static $login_allowed = null;
    public static $health_check = null;
    public static $uploader = null;
    const ENC_PREFIX = 'enc:v1:';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Vrai pendant le renvoi qui rapporte le résultat des actions (évite une boucle d'envois). */
    private $reporting = false;

    private function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_notices', [$this, 'maybe_notice']);
        add_action('admin_post_ssm_connector_connect', [$this, 'handle_connect_request']);
        add_action('admin_post_ssm_connector_test', [$this, 'handle_test_request']);
        add_action('admin_post_ssm_connector_login', [$this, 'handle_login_settings']);
        add_action('wp_loaded', [$this, 'on_loaded']);
        add_action(self::HEARTBEAT_HOOK, [$this, 'send_heartbeat']);
        add_action(self::POLL_HOOK, [$this, 'poll']);
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        // stockage fourni par SSM à d'autres extensions (WP Migration) : voir storage_sign()
        add_filter('ssm_connector_storage_available', [$this, 'storage_available']);
        add_filter('ssm_connector_storage_sign', [$this, 'storage_sign'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(SSM_CONNECTOR_FILE), [$this, 'action_links']);
        register_activation_hook(SSM_CONNECTOR_FILE, [$this, 'activate']);
        register_deactivation_hook(SSM_CONNECTOR_FILE, [$this, 'deactivate']);
        // Erreurs fatales : relevées à la fin de chaque requête (rien n'est écrit sans erreur).
        register_shutdown_function([$this, 'capture_fatal']);
        // La seule porte d'entrée, et seulement si un administrateur du site l'a ouverte (case de la
        // page de l'extension, ou constante de wp-config.php) : sinon, aucun crochet public n'est posé.
        if (self::login_allowed()) {
            add_action('login_init', [$this, 'handle_login']);
        }
    }

    /**
     * Connexion directe depuis SSM : fermée par défaut. Un administrateur l'ouvre dans la page de
     * l'extension, jamais SSM à distance. La constante SSM_CONNECTOR_ALLOW_LOGIN de wp-config.php
     * l'emporte : true l'ouvre, false la verrouille fermée, quoi qu'indique la case.
     */
    public static function login_allowed() {
        if (self::$login_allowed !== null) {
            return (bool) self::$login_allowed;
        }
        if (self::login_locked_by_constant()) {
            return (bool) SSM_CONNECTOR_ALLOW_LOGIN;
        }
        return (bool) get_option(self::OPT_LOGIN_ALLOWED, false);
    }

    public static function login_locked_by_constant() {
        return defined('SSM_CONNECTOR_ALLOW_LOGIN');
    }

    /** Ce que ce connecteur sait exécuter : SSM n'envoie rien d'autre. */
    public function capabilities() {
        $caps = ['update_extension', 'update_theme', 'update_core', 'health_check', 'plugin_activate',
                 'plugin_deactivate', 'plugin_install', 'plugin_install_package', 'plugin_delete', 'php_errors', 'poll',
                 'storage_broker'];
        if (class_exists('ZipArchive') && function_exists('curl_init')) {
            $caps[] = 'backup_site';
        }
        if (self::login_allowed()) {
            $caps[] = 'login';
        }
        // Explorateur de fichiers (SSM 2.27). Annoncees separement : lire un `debug.log` ne doit
        // pas obliger a ouvrir le droit d'ecrire sur des fichiers de production.
        if (is_dir(ABSPATH) && is_readable(ABSPATH)) {
            $caps[] = 'read_files';
            // Canal rapide : commandes de fichiers recues et resultats rendus sans inventaire.
            $caps[] = 'fast_files';
        }
        if (is_dir(ABSPATH) && is_writable(ABSPATH)) {
            $caps[] = 'write_files';
            $caps[] = 'copy_files';
            $caps[] = 'upload_files';
            $caps[] = 'extract_files';
        }
        // Le telechargement n'exige pas d'ecriture sur le site : c'est une lecture. Il demande
        // en revanche cURL pour deposer sur le point de sauvegarde.
        if (is_dir(ABSPATH) && is_readable(ABSPATH) && function_exists('curl_init')) {
            $caps[] = 'download_files';
        }
        return $caps;
    }

    // === Explorateur de fichiers (SSM 2.27) ===
// === Explorateur de fichiers (SSM 2.27) ===

    /**
     * Traduit un chemin SSM en chemin réel, ou lève une exception.
     *
     * SSM filtre déjà de son côté, mais il ne voit pas le disque : **seul le contrôle fait ici
     * vaut**, parce que c'est le seul endroit où l'on peut résoudre un lien symbolique. Un
     * `wp-content/uploads/lien` pointant vers `/etc` passerait tous les tests de SSM.
     *
     * On résout donc le chemin réel (`.` et `..` inclus) et on vérifie qu'il reste sous la racine
     * du site. `ABSPATH` est la racine *réelle* : WordPress vit ailleurs que « la racine » de son
     * arborescence de fichiers.
     */
    private function file_root() {
        $root = @realpath(ABSPATH);
        return $root === false ? rtrim(ABSPATH, '/\\') : $root;
    }

    private function resolve_site_path($raw) {
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Chemin absent.');
        }
        if (strpos($raw, "\0") !== false || preg_match('/[\r\n\t]/', $raw)) {
            throw new \RuntimeException('Chemin invalide.');
        }
        $rel = str_replace('\\', '/', trim($raw));
        $rel = ltrim($rel, '/');
        $segs = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                // Refuse franchement plutot que de normaliser. Un `../` qui deborde la racine
                // exprime une demande de sortir du site : la reduire silencieusement a la racine
                // eviterait un echec, mais elle validerait une requete qui n'a pas de sens.
                if (!$segs) {
                    throw new \RuntimeException("Chemin refuse : la remontee sort de la racine du site.");
                }
                array_pop($segs);
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._\-]+$/', $seg)) {
                throw new \RuntimeException('Segment de chemin refuse : ' . $this->cut($seg, 40));
            }
            $segs[] = $seg;
        }
        $root = $this->file_root();
        if (!$segs) {
            return $root;
        }
        $candidat = $root . '/' . implode('/', $segs);
        // Si le chemin existe deja, on resout reellement (liens symboliques) ; sinon on resout le
        // parent, pour qu'un fichier cree dans un dossier symlinke ne sorte pas non plus.
        $reel = realpath($candidat);
        if ($reel === false) {
            $reel = realpath(dirname($candidat));
        }
        if ($reel !== false) {
            $reel = rtrim($reel, '/\\');
            if ($reel !== $root && strpos($reel, $root . DIRECTORY_SEPARATOR) !== 0) {
                throw new \RuntimeException('Chemin refuse : il sort de la racine du site.');
            }
        }
        return $candidat;
    }

    /** Un fichier d'identifiants n'est ni lu ni ecrit depuis SSM : les afficher les copierait dans
     *  une page web, et les ecrire a l'aveugle casse le site. */
    private function is_secret_file($abs) {
        $base = basename($abs);
        if (in_array($base, ['wp-config.php', '.htaccess', '.env', 'web.config', 'configuration.php'], true)) {
            return true;
        }
        return strpos($abs, DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'config.php') !== false;
    }

    private function human_size($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return $bytes . ' o';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' Ko';
        }
        return round($bytes / 1048576, 1) . ' Mo';
    }

    private function rel_to_root($abs) {
        $rootn = str_replace('\\', '/', rtrim($this->file_root(), '/\\') . '/');
        $absn = str_replace('\\', '/', $abs);
        return strpos($absn, $rootn) === 0 ? '/' . ltrim(substr($absn, strlen($rootn)), '/') : $absn;
    }

    private function file_entry($abs) {
        $est_dossier = is_dir($abs);
        return [
            'nom' => basename($abs),
            'chemin' => $this->rel_to_root($abs),
            'dossier' => $est_dossier,
            'taille' => $est_dossier ? 0 : (int) @filesize($abs),
            'taille_lisible' => $est_dossier ? '—' : $this->human_size(@filesize($abs)),
            'modifie' => (int) @filemtime($abs),
            'secret' => !$est_dossier && $this->is_secret_file($abs),
        ];
    }

    private function signaler_erreur_fichier($id, $raison) {
        $this->queue_command_result($id, 'failed', $raison);
    }

    // --- opérations ---

    private function op_list_files($id, $params) {
        try {
            $dir = $this->resolve_site_path(isset($params['path']) ? $params['path'] : '/');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if (!is_dir($dir)) {
            $this->signaler_erreur_fichier($id, "Ce chemin n'est pas un dossier.");
            return;
        }
        $dirs_only = !empty($params['dirs_only']);
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;
        $entrees = [];
        $handle = @opendir($dir);
        if ($handle === false) {
            $this->signaler_erreur_fichier($id, 'Dossier illisible.');
            return;
        }
        while (($nom = readdir($handle)) !== false) {
            if ($nom === '.' || $nom === '..') {
                continue;
            }
            $abs = $dir . '/' . $nom;
            // Arborescence : seuls les sous-dossiers comptent. Evite de statuer des milliers de
            // fichiers d'`uploads/` pour n'en afficher aucun.
            if ($dirs_only && !is_dir($abs)) {
                continue;
            }
            $entrees[] = $this->file_entry($abs);
        }
        closedir($handle);
        usort($entrees, function ($a, $b) {
            if ($a['dossier'] !== $b['dossier']) {
                return $a['dossier'] ? -1 : 1;    // les dossiers d'abord : c'est ce qu'on cherche
            }
            return strcasecmp($a['nom'], $b['nom']);
        });
        $this->queue_command_result($id, 'success', null, [
            'path' => $this->rel_to_root($dir),
            'racine' => $this->rel_to_root($this->file_root()),
            'entrees' => array_slice($entrees, $offset, self::LIST_MAX),
            'tronque' => count($entrees) > $offset + self::LIST_MAX,
            'total' => count($entrees),
            'offset' => $offset,
        ]);
    }

    private function op_read_file($id, $params) {
        try {
            $abs = $this->resolve_site_path(isset($params['path']) ? $params['path'] : '');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if (!is_file($abs)) {
            $this->signaler_erreur_fichier($id, 'Fichier introuvable.');
            return;
        }
        if ($this->is_secret_file($abs)) {
            $this->signaler_erreur_fichier($id, "Ce fichier contient des identifiants : son contenu n'est pas transmis.");
            return;
        }
        $taille = (int) filesize($abs);
        if ($taille > 524288) {     // 512 Ko : au-dela, l'ecran n'enregistre rien d'utile
            $this->signaler_erreur_fichier($id, 'Fichier trop volumineux pour etre affiche ('
                . $this->human_size($taille) . ').');
            return;
        }
        $contenu = @file_get_contents($abs);
        if ($contenu === false) {
            $this->signaler_erreur_fichier($id, 'Lecture impossible.');
            return;
        }
        $this->queue_command_result($id, 'success', null, [
            'path' => $this->rel_to_root($abs),
            'content' => $contenu,
            'encoding' => 'utf-8',
            'size_bytes' => $taille,
            'modifie' => (int) filemtime($abs),
        ]);
    }

    private function op_write_file($id, $params) {
        try {
            $abs = $this->resolve_site_path(isset($params['path']) ? $params['path'] : '');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if ($this->is_secret_file($abs)) {
            $this->signaler_erreur_fichier($id, 'Ce fichier contient des identifiants : SSM ne le modifie pas.');
            return;
        }
        if (is_dir($abs)) {
            $this->signaler_erreur_fichier($id, 'Ce chemin est un dossier.');
            return;
        }
        $contenu = isset($params['content']) ? (string) $params['content'] : '';
        if (strlen($contenu) > 2097152) {   // 2 Mo
            $this->signaler_erreur_fichier($id, 'Contenu trop volumineux.');
            return;
        }
        $dossier = dirname($abs);
        if (!$this->disk_writable($dossier)) {
            $this->signaler_erreur_fichier($id, 'Dossier non inscriptible.');
            return;
        }
        // Ecriture atomique : un temporaire puis un renommage. Une ecriture interrompue ne doit
        // jamais laisser un fichier tronque sur un site de production.
        $tmp = $abs . '.ssmtmp' . wp_rand(1000, 9999);
        if (@file_put_contents($tmp, $contenu, LOCK_EX) === false) {
            $this->signaler_erreur_fichier($id, 'Ecriture impossible.');
            return;
        }
        if (is_file($abs)) {
            @chmod($tmp, @fileperms($abs) & 0777);   // un fichier php executable doit le rester
        }
        if (!@rename($tmp, $abs)) {
            @unlink($tmp);
            $this->signaler_erreur_fichier($id, 'Remplacement impossible.');
            return;
        }
        clearstatcache(true, $abs);
        $this->queue_command_result($id, 'success', null, [
            'path' => $this->rel_to_root($abs),
            'size_bytes' => (int) filesize($abs),
            'modifie' => (int) filemtime($abs),
        ]);
    }

    private function op_make_dir($id, $params) {
        try {
            $abs = $this->resolve_site_path(isset($params['path']) ? $params['path'] : '');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if ($abs === $this->file_root()) {
            $this->signaler_erreur_fichier($id, 'La racine du site existe deja.');
            return;
        }
        if (file_exists($abs)) {
            // Refus net : ni fusion ni ecrasement. Creer « uploads » alors qu'il existe est une
            // erreur de saisie, pas une demande.
            $this->signaler_erreur_fichier($id, 'Ce nom existe deja.');
            return;
        }
        $parent = dirname($abs);
        if (!is_dir($parent)) {
            $this->signaler_erreur_fichier($id, "Le dossier parent n'existe pas.");
            return;
        }
        if (!$this->disk_writable($parent)) {
            $this->signaler_erreur_fichier($id, 'Dossier non inscriptible.');
            return;
        }
        // 0755 sans recursion : on cree le dossier demande, pas une chaine de dossiers.
        if (!@mkdir($abs, 0755)) {
            $this->signaler_erreur_fichier($id, 'Creation impossible.');
            return;
        }
        clearstatcache(true, $abs);
        $this->queue_command_result($id, 'success', null, [
            'path' => $this->rel_to_root($abs),
            'modifie' => (int) @filemtime($abs),
        ]);
    }

    private function op_move_file($id, $params) {
        try {
            $abs = $this->resolve_site_path(isset($params['path']) ? $params['path'] : '');
            $vers = $this->resolve_site_path(isset($params['to']) ? $params['to'] : '');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if (!file_exists($abs)) {
            $this->signaler_erreur_fichier($id, 'Source introuvable.');
            return;
        }
        if (file_exists($vers)) {
            $this->signaler_erreur_fichier($id, 'La destination existe deja.');
            return;
        }
        if (!$this->disk_writable(dirname($vers))) {
            $this->signaler_erreur_fichier($id, 'Dossier de destination non inscriptible.');
            return;
        }
        // `rename` conserve le mode et le proprietaire ; un `copy` suivi d'un `unlink` les perdrait —
        // et un script de site qui perd son bit executable ne se lance plus.
        if (@rename($abs, $vers)) {
            $this->queue_command_result($id, 'success', null, [
                'path' => $this->rel_to_root($abs), 'to' => $this->rel_to_root($vers)]);
            return;
        }
        $this->signaler_erreur_fichier($id, 'Deplacement impossible.');
    }

    private function op_delete_file($id, $params) {
        try {
            $abs = $this->resolve_site_path(isset($params['path']) ? $params['path'] : '');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if (rtrim($abs, '/\\') === rtrim($this->file_root(), '/\\')) {
            $this->signaler_erreur_fichier($id, 'La racine du site ne peut pas etre supprimee.');
            return;
        }
        if (!file_exists($abs)) {
            $this->signaler_erreur_fichier($id, 'Fichier introuvable.');
            return;
        }
        if (is_dir($abs) && !is_link($abs)) {
            // Seul un dossier VIDE se supprime : effacer un `wp-content/themes` d'un geste serait le
            // pire service a rendre. La suppression recursive reste refusee.
            $contenu = @scandir($abs);
            if ($contenu === false || count(array_diff($contenu, ['.', '..'])) > 0) {
                $this->signaler_erreur_fichier($id,
                    "Ce dossier n'est pas vide : la suppression recursive n'est pas autorisee depuis SSM.");
                return;
            }
            if (!$this->disk_writable(dirname($abs))) {
                $this->signaler_erreur_fichier($id, 'Dossier non inscriptible.');
                return;
            }
            if (@rmdir($abs)) {
                $this->queue_command_result($id, 'success', null, ['path' => $this->rel_to_root($abs)]);
            } else {
                $this->signaler_erreur_fichier($id, 'Suppression impossible.');
            }
            return;
        }
        if (!$this->disk_writable(dirname($abs))) {
            $this->signaler_erreur_fichier($id, 'Dossier non inscriptible.');
            return;
        }
        if (@unlink($abs)) {
            $this->queue_command_result($id, 'success', null, ['path' => $this->rel_to_root($abs)]);
            return;
        }
        $this->signaler_erreur_fichier($id, 'Suppression impossible.');
    }

// === Transfert de fichiers (SSM 2.27) : copier, telecharger, envoyer, decompresser ===

    /**
     * Copie cote serveur. Les octets ne quittent jamais le site : c'est la seule façon de copier un
     * fichier de 200 Mo sans le faire transiter par une API.
     */
    private function op_copy_file($id, $params)
    {
        try {
            $src = $this->resolve_site_path($params['path'] ?? '');
            $dst = $this->resolve_site_path($params['to'] ?? '');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if ($src === $dst) {
            $this->signaler_erreur_fichier($id, 'La source et la destination sont identiques.');
            return;
        }
        if (!file_exists($src)) {
            $this->signaler_erreur_fichier($id, 'Source introuvable.');
            return;
        }
        if (file_exists($dst) && empty($params['overwrite'])) {
            $this->signaler_erreur_fichier($id, 'La destination existe deja.');
            return;
        }
        $dossier = dirname($dst);
        if (!$this->disk_writable($dossier)) {
            $this->signaler_erreur_fichier($id, 'Dossier de destination non inscriptible.');
            return;
        }
        if (is_dir($src)) {
            if (!$this->copier_dossier($src, $dst, !empty($params['overwrite']))) {
                $this->signaler_erreur_fichier($id, 'Copie du dossier impossible.');
                return;
            }
        } elseif (!@copy($src, $dst)) {
            $this->signaler_erreur_fichier($id, 'Copie impossible.');
            return;
        }
        @chmod($dst, @fileperms($src) & 0777);   // un script qui perd son bit executable ne se lance plus
        $this->queue_command_result($id, 'success', null, [
            'path' => $this->rel_to_root($src), 'to' => $this->rel_to_root($dst),
            'size_bytes' => (int) @filesize($dst), 'detail' => 'Copie effectuee sur le site.',
        ]);
    }

    private function copier_dossier($src, $dst, $ecraser)
    {
        if (!@mkdir($dst, 0777, true) && !is_dir($dst)) {
            return false;
        }
        $handle = @opendir($src);
        if ($handle === false) {
            return false;
        }
        $ok = true;
        while (($nom = readdir($handle)) !== false) {
            if ($nom === '.' || $nom === '..') {
                continue;
            }
            $de = $src . '/' . $nom;
            $vers = $dst . '/' . $nom;
            // Un membre d'archive, ou une copie, ne doit jamais pouvoir ressortir par un lien.
            if (is_link($de)) {
                continue;
            }
            if (is_dir($de)) {
                $ok = $this->copier_dossier($de, $vers, $ecraser) && $ok;
            } else {
                if (file_exists($vers) && !$ecraser) {
                    continue;
                }
                $ok = @copy($de, $vers) && $ok;
            }
        }
        closedir($handle);
        return $ok;
    }

    /**
     * Depose un fichier du site sur le point de sauvegarde, pour que SSM en serve une URL signee.
     *
     * Les octets ne passent pas par SSM : ils vont du site vers le stockage, puis du stockage vers
     * le navigateur. C'est ce qui rend possible le telechargement d'un fichier de 200 Mo.
     */
    private function op_download_file($id, $params)
    {
        try {
            $abs = $this->resolve_site_path($params['path'] ?? '');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if (!is_file($abs)) {
            $this->signaler_erreur_fichier($id, 'Fichier introuvable.');
            return;
        }
        if ($this->is_secret_file($abs)) {
            $this->signaler_erreur_fichier($id, "Ce fichier contient des identifiants : il n'est pas telechargeable.");
            return;
        }
        $url = (string) ($params['upload_url'] ?? '');
        $cle = (string) ($params['object_key'] ?? '');
        if ($url === '' || $cle === '') {
            $this->signaler_erreur_fichier($id, 'Aucune URL de depot fournie.');
            return;
        }
        $taille = (int) filesize($abs);
        if ($taille > 536870912) {   // 512 Mo
            $this->signaler_erreur_fichier($id, 'Fichier trop volumineux pour le transfert (512 Mo maximum).');
            return;
        }
        $contenu = @file_get_contents($abs);
        if ($contenu === false) {
            $this->signaler_erreur_fichier($id, 'Lecture impossible.');
            return;
        }
        $http = $this->mettre($url, $contenu, 'application/octet-stream');
        if (!empty($http['erreur'])) {
            $this->signaler_erreur_fichier($id, "Depot refuse : " . $http['erreur']);
            return;
        }
        // On ne repond « depose » qu'apres l'envoi reel : c'est cet avis que SSM transforme en URL
        // de telechargement. Un lien vers un fichier que personne n'a depose ne doit pas exister.
        $this->queue_command_result($id, 'success', null, [
            'depose' => true, 'object_key' => $cle, 'size_bytes' => $taille,
            'sha256' => hash('sha256', $contenu),
            'detail' => 'Depot sur le point de sauvegarde : ' . $this->human_size($taille),
        ]);
    }

    /**
     * Recupere un fichier que l'utilisateur a depose et l'installe sur le site, en le
     * decompressant si c'est une archive.
     *
     * Chaque membre d'une archive est verifie avant extraction : un zip telecharge sur internet
     * contient parfois `../../wp-config.php`, et une extraction non verifiee ecrirait n'importe ou
     * sur le serveur.
     */
    private function op_install_file($id, $params)
    {
        $url = (string) ($params['download_url'] ?? '');
        if ($url === '') {
            $this->signaler_erreur_fichier($id, 'Aucune URL de telechargement fournie.');
            return;
        }
        $reponse = $this->telecharger($url);
        if ($reponse === false) {
            $this->signaler_erreur_fichier($id, 'Recuperation du fichier impossible.');
            return;
        }
        $nom = basename((string) ($params['name'] ?? 'fichier'));
        $nom = preg_replace('/[^A-Za-z0-9._\-]/', '_', $nom);
        if (!empty($params['sha256']) && hash('sha256', $reponse) !== $params['sha256']) {
            $this->signaler_erreur_fichier($id, 'Empreinte du fichier recu differente de celle annoncee.');
            return;
        }
        $dossier = $this->resolve_site_path($params['path'] ?? '/');
        if (!is_dir($dossier)) {
            if (!$this->disk_writable(dirname($dossier)) || !@mkdir($dossier, 0777, true)) {
                $this->signaler_erreur_fichier($id, 'Dossier de destination impossible a creer.');
                return;
            }
        }
        $cible = rtrim($dossier, '/') . '/' . $nom;
        if (file_exists($cible) && empty($params['overwrite'])) {
            $this->signaler_erreur_fichier($id, 'Le fichier existe deja : refus de le remplacer.');
            return;
        }
        if (!$this->disk_writable($dossier)) {
            $this->signaler_erreur_fichier($id, 'Dossier non inscriptible.');
            return;
        }
        if (@file_put_contents($cible, $reponse, LOCK_EX) === false) {
            $this->signaler_erreur_fichier($id, 'Ecriture impossible.');
            return;
        }
        $res = ['path' => $this->rel_to_root($cible), 'size_bytes' => strlen($reponse)];
        if (!empty($params['extract']) && preg_match('/\.zip$/i', $nom)) {
            $extrait = $this->extraire_zip($cible, $dossier, !empty($params['overwrite']));
            $res['extraction'] = $extrait;
            if (isset($extrait['erreur'])) {
                $this->signaler_erreur_fichier($id, 'Fichier installe, mais decompression refusee : ' . $extrait['erreur']);
                return;
            }
            $res['detail'] = 'Archive installee et decompressee (' . $extrait['fichiers'] . ' fichier(s)).';
        } else {
            $res['detail'] = 'Fichier installe (' . $this->human_size(strlen($reponse)) . ').';
        }
        $this->queue_command_result($id, 'success', null, $res);
    }

    private function op_extract_archive($id, $params)
    {
        try {
            $abs = $this->resolve_site_path($params['path'] ?? '');
        } catch (\RuntimeException $e) {
            $this->signaler_erreur_fichier($id, $e->getMessage());
            return;
        }
        if (!is_file($abs) || !preg_match('/\.zip$/i', $abs)) {
            $this->signaler_erreur_fichier($id, 'Archive zip introuvable.');
            return;
        }
        $dossier = dirname($abs);
        $res = $this->extraire_zip($abs, $dossier, !empty($params['overwrite']));
        if (isset($res['erreur'])) {
            $this->signaler_erreur_fichier($id, 'Decompression refusee : ' . $res['erreur']);
            return;
        }
        $this->queue_command_result($id, 'success', null, [
            'path' => $this->rel_to_root($abs), 'fichiers' => $res['fichiers'],
            'detail' => $res['fichiers'] . ' fichier(s) decompresse(s).',
        ]);
    }

    /**
     * Extraction sure : chaque membre est resolu, et refuse s'il sort de la racine du site.
     * `ZipArchive::extractTo()` ne fait pas ce controle — c'est a nous de le faire.
     */
    private function extraire_zip($zip, $dossier, $ecraser)
    {
        if (!class_exists('ZipArchive')) {
            return ['erreur' => "l'extension ZipArchive n'est pas installee sur ce serveur."];
        }
        $racine = rtrim($this->file_root(), '/\\');
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) {
            return ['erreur' => 'archive illisible ou corrompue.'];
        }
        $n = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $membre = $z->getNameIndex($i);
            if ($membre === false || substr($membre, -1) === '/') {
                continue;
            }
            // Resolution complete : c'est elle qui detecte `../` et un lien symbolique prealable.
            $reel = realpath($dossier . '/' . $membre);
            if ($reel === false) {
                $reel = realpath(dirname($dossier . '/' . $membre));
                $reel = $reel === false ? false : $reel . DIRECTORY_SEPARATOR . basename($membre);
            }
            if ($reel === false || (rtrim($reel, '/\\') !== $racine
                                   && strpos(rtrim($reel, '/\\'), $racine . DIRECTORY_SEPARATOR) !== 0)) {
                $z->close();
                return ['erreur' => 'un membre sort de la racine du site : ' . $this->cut($membre, 80)];
            }
            $contenu = $z->getFromIndex($i);
            if ($contenu === false) {
                continue;
            }
            if (file_exists($reel) && !$ecraser) {
                continue;
            }
            $sous = dirname($reel);
            if (!is_dir($sous) && !@mkdir($sous, 0777, true)) {
                $z->close();
                return ['erreur' => 'dossier impossible a creer : ' . $this->cut(dirname($membre), 80)];
            }
            if (@file_put_contents($reel, $contenu, LOCK_EX) !== false) {
                $n++;
            }
        }
        $z->close();
        return ['fichiers' => $n];
    }

    // --- transfers HTTP bruts (S3-compatible) ---

    private function mettre($url, $corps, $type)
    {
        if (!function_exists('curl_init')) {
            return ['erreur' => 'cURL absent de ce serveur.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => fopen('php://temp', 'r+'),
            CURLOPT_INFILESIZE => strlen($corps),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $type, 'Content-Length: ' . strlen($corps)],
            CURLOPT_TIMEOUT => 120,
        ]);
        curl_setopt($ch, CURLOPT_INFILE, $this->temp_stream($corps));
        $reponse = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erreur = curl_error($ch);
        curl_close($ch);
        if ($reponse === false || ($code < 200 && $code != 0) || $erreur) {
            return ['erreur' => $this->cut($erreur !== '' ? $erreur : 'HTTP ' . $code, 200)];
        }
        return [];
    }

    private function temp_stream($corps)
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, $corps);
        rewind($h);
        return $h;
    }

    private function telecharger($url)
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 180]);
        $reponse = curl_exec($ch);
        curl_close($ch);
        return $reponse === false ? false : $reponse;
    }
    // === Activation, migration, planification ===

    public function activate() {
        $this->maybe_upgrade();
        $this->schedule_heartbeat();
        $this->schedule_poll();
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::HEARTBEAT_HOOK);
        wp_clear_scheduled_hook(self::POLL_HOOK);
    }

    public function on_loaded() {
        $this->maybe_upgrade();
        $this->schedule_heartbeat();
        $this->schedule_poll();
        $this->announce_new_version();
    }

    /**
     * Après une installation ou une mise à jour de l'extension, un envoi part tout de suite (au prochain passage
     * de WP-Cron) au lieu d'attendre l'envoi horaire : SSM affiche la nouvelle version dans la minute.
     */
    public function announce_new_version() {
        if (get_option(self::OPT_VERSION_SENT) === SSM_CONNECTOR_VERSION) {
            return;
        }
        update_option(self::OPT_VERSION_SENT, SSM_CONNECTOR_VERSION, false);
        wp_schedule_single_event(time(), self::HEARTBEAT_HOOK, ['nouvelle-version']);
    }

    // === Question « une action m'attend-elle ? » ===

    /** WP-Cron : intervalle de deux minutes pour la question à SSM (WordPress n'en propose pas de si court). */
    public function cron_schedules($schedules) {
        $schedules[self::POLL_SCHEDULE] = ['interval' => self::POLL_INTERVAL, 'display' => 'SSM : toutes les 15 secondes'];
        return $schedules;
    }

    /**
     * Toutes les deux minutes, une question légère à SSM (`GET /api/v1/connector/pending`, authentifiée par le token) :
     * une mise à jour, une action ou une sauvegarde attend-elle ? Oui : l'envoi complet part aussitôt et la reçoit.
     * Sans cela, une demande attendait l'envoi horaire. SSM fait tourner WP-Cron en appelant wp-cron.php, la page
     * publique de WordPress : l'extension n'ouvre aucune porte de plus sur le site.
     */
    public function poll() {
        $cfg = $this->config();
        if ($cfg['problem'] !== '' || $cfg['url'] === '' || $cfg['token'] === '') {
            return false;
        }
        $response = wp_remote_get($cfg['url'] . '/api/v1/connector/pending', [
            'headers' => ['X-SSM-Token' => $cfg['token'], 'Accept' => 'application/json'],
            'timeout' => 10,
            'user-agent' => 'SSM-Connector/' . SSM_CONNECTOR_VERSION,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return false;   // SSM injoignable ou plus ancien (route absente) : l'envoi horaire reste là
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return false;
        }
        // Deux voies depuis le canal rapide : `fast` (commandes de fichiers, session d'ecoute sans
        // inventaire) et `pending` (mises a jour, actions : le heartbeat, comme avant). Un SSM
        // plus ancien ne repond que `pending`, pour tout.
        $ok = false;
        if (!empty($body['fast']) && in_array('fast_files', $this->capabilities(), true)) {
            $ok = $this->fast_session() || $ok;
        }
        if (!empty($body['pending'])) {
            $ok = $this->send_heartbeat() || $ok;
        }
        return $ok;
    }

    /**
     * Session d'ecoute de l'explorateur : tant qu'on navigue, une requete longue sur SSM
     * (`GET /connector/commands?wait=`) rend la main des qu'une commande de fichiers arrive ; on
     * l'execute et on renvoie son resultat tout de suite (`POST /connector/results`), sans inventaire.
     *
     * Bornee, parce qu'elle occupe un processus PHP : duree maximale (FAST_SECONDS), un seul
     * exemplaire a la fois (verrou), arret apres deux attentes sans commande. Elle ne demarre que
     * si SSM l'a demandee (`fast` dans la reponse de `pending`) : un site non consulte n'ouvre rien.
     *
     * @return bool true si au moins une commande a ete traitee.
     */
    public function fast_session() {
        $cfg = $this->config();
        if ($cfg['problem'] !== '' || $cfg['url'] === '' || $cfg['token'] === '') {
            return false;
        }
        if (get_transient(self::FAST_LOCK)) {
            return false;       // une session tourne deja
        }
        $max = defined('SSM_CONNECTOR_SESSION_SECONDS') ? max(5, min(120, (int) SSM_CONNECTOR_SESSION_SECONDS)) : self::FAST_SECONDS;
        // Verrou a duree courte, renouvele a chaque tour : un hebergeur qui tue le processus avant la
        // fin (limite de temps, delai du serveur web) n'executerait pas le `finally`, et un verrou
        // de `$max + 30` s bloquait alors toute nouvelle session pendant plus d'une minute.
        $bail = self::FAST_WAIT + 10;
        set_transient(self::FAST_LOCK, time(), $bail);
        // WP-Cron a pose son propre verrou pour 60 s : le garder ferait attendre les autres taches
        // planifiees du site pendant toute la session.
        delete_transient('doing_cron');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit($max + 30);
        }
        $fin = time() + $max;
        $vides = 0;
        $traitees = 0;
        try {
            while (($reste = $fin - time()) >= 2) {
                $attente = min(self::FAST_WAIT, $reste - 1);
                set_transient(self::FAST_LOCK, time(), $bail + 10);
                $reponse = wp_remote_get($cfg['url'] . '/api/v1/connector/commands?wait=' . (int) $attente, [
                    'headers' => ['X-SSM-Token' => $cfg['token'], 'Accept' => 'application/json'],
                    'timeout' => $attente + 8,
                    'redirection' => 0,
                    'user-agent' => 'SSM-Connector/' . SSM_CONNECTOR_VERSION,
                ]);
                if (is_wp_error($reponse) || (int) wp_remote_retrieve_response_code($reponse) !== 200) {
                    break;      // SSM injoignable, ou canal ferme (409) : le heartbeat reste la
                }
                $body = json_decode((string) wp_remote_retrieve_body($reponse), true);
                $commandes = is_array($body) && is_array($body['commands'] ?? null) ? $body['commands'] : [];
                if (!$commandes) {
                    if (++$vides >= 2) {
                        break;  // personne ne navigue plus : on rend le processus
                    }
                    continue;
                }
                $vides = 0;
                $this->apply_commands($commandes);
                $traitees += count($commandes);
                if (!$this->flush_command_results($cfg)) {
                    break;
                }
            }
        } finally {
            // Resultats restes en file (erreur reseau en cours de route) : ils partent au heartbeat.
            delete_transient(self::FAST_LOCK);
        }
        return $traitees > 0;
    }

    /** Renvoie a SSM les comptes rendus en file, sans inventaire. En cas d'echec ils sont remis en file. */
    private function flush_command_results($cfg) {
        $resultats = $this->take_command_results();
        if (!$resultats) {
            return true;
        }
        $reply = $this->post_to_ssm($cfg['url'] . '/api/v1/connector/results', $cfg['token'],
            ['command_results' => $resultats]);
        $code = isset($reply['status_code']) ? (int) $reply['status_code'] : 0;
        if (isset($reply['error']) || $code < 200 || $code >= 300) {
            $restant = get_option(self::OPT_CMD_RESULTS, []);
            update_option(self::OPT_CMD_RESULTS, array_slice(array_merge($resultats, is_array($restant) ? $restant : []), -200));
            return false;
        }
        return true;
    }

    // === Stockage fourni par SSM (courtier d'URL pré-signées) ===

    /**
     * Filtre `ssm_connector_storage_available` : le connecteur est-il prêt à demander des signatures à SSM ?
     * (adresse et jeton valides). Ne dit pas que SSM a un point de sauvegarde pour ce site : SSM le dira à la demande.
     */
    public function storage_available($default = false) {
        $cfg = $this->config();
        return $cfg['problem'] === '' && $cfg['url'] !== '' && $cfg['token'] !== '';
    }

    /**
     * Filtre `ssm_connector_storage_sign` : demande à SSM de signer UNE requête S3 d'une autre extension (WP Migration).
     * `$args` : ['method' => GET|PUT|HEAD|DELETE|POST, 'key' => clé relative au dossier du site, 'query' => [...], 'expires' => s].
     * Retourne ['url', 'method', 'base', 'expires_in'] ou un WP_Error au message prêt à afficher (jamais l'URL signée).
     * La clé du compartiment reste dans SSM : le site ne reçoit qu'une URL valable pour cette requête, dans son dossier.
     * Ce filtre n'ouvre aucune route : il n'est appelable que par du code PHP du site, qui peut déjà lire le jeton.
     */
    public function storage_sign($default, $args = []) {
        $cfg = $this->config();
        if (!$this->storage_available()) {
            return new WP_Error('ssm_connector', $cfg['problem'] !== '' ? $cfg['problem'] : "SSM Connector n'est pas configuré : saisissez l'adresse de SSM et le token du site.");
        }
        $args = is_array($args) ? $args : [];
        $method = isset($args['method']) ? strtoupper((string) $args['method']) : '';
        if (!in_array($method, ['GET', 'PUT', 'HEAD', 'DELETE', 'POST'], true)) {
            return new WP_Error('ssm_connector', 'Méthode S3 non prise en charge.');
        }
        $query = [];
        foreach ((isset($args['query']) && is_array($args['query']) ? $args['query'] : []) as $name => $value) {
            $query[(string) $name] = (string) $value;
        }
        $body = ['method' => $method, 'key' => isset($args['key']) ? (string) $args['key'] : '', 'query' => (object) $query];
        if (isset($args['expires'])) {
            $body['expires'] = (int) $args['expires'];
        }
        $reply = $this->post_to_ssm($cfg['url'] . '/api/v1/connector/storage/sign', $cfg['token'], $body);
        if (isset($reply['error'])) {
            return new WP_Error('ssm_connector', $reply['error']);
        }
        $code = (int) $reply['status_code'];
        $data = is_array($reply['body']) ? $reply['body'] : [];
        if ($code === 200 && isset($data['url']) && is_string($data['url']) && preg_match('#^https?://#', $data['url'])) {
            return [
                'url' => $data['url'],
                'method' => $method,
                'base' => isset($data['base']) && is_string($data['base']) ? $data['base'] : '',
                'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : 0,
            ];
        }
        if ($code === 401 || $code === 403) {
            return new WP_Error('ssm_connector', 'SSM a refusé le token de ce site : reconnectez le connecteur.');
        }
        if ($code === 404) {
            return new WP_Error('ssm_connector', "Cette version de SSM ne fournit pas le stockage aux extensions : mettez SSM à jour.");
        }
        $detail = isset($data['detail']) && is_string($data['detail']) ? $this->cut($data['detail'], 200) : '';
        return new WP_Error('ssm_connector', $detail !== '' ? 'SSM : ' . $detail : 'Réponse inattendue de SSM Core (HTTP ' . $code . ').');
    }

    public function schedule_poll() {
        if (!wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_event(time() + self::POLL_INTERVAL, self::POLL_SCHEDULE, self::POLL_HOOK);
        }
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

    /**
     * L'état des mises à jour tel que WordPress le calcule. Juste après une mise à jour, WordPress l'efface et ne le
     * recalcule qu'au prochain passage dans l'administration : sans lui, l'inventaire ne dirait rien des versions
     * publiées. On le fait recalculer (la même requête vers wordpress.org que WordPress fait deux fois par jour).
     */
    private function update_state($transient, $refresh) {
        $state = get_site_transient($transient);
        if (!is_object($state) || empty($state->last_checked)) {
            if (!function_exists($refresh) && is_readable(ABSPATH . 'wp-includes/update.php')) {
                require_once ABSPATH . 'wp-includes/update.php';   // chargé par WordPress en temps normal
            }
            if (function_exists($refresh)) {
                $refresh();
                $state = get_site_transient($transient);
            }
        }
        return $state;
    }

    private function collect_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $updates = $this->update_state('update_plugins', 'wp_update_plugins');
        $response = (is_object($updates) && isset($updates->response) && is_array($updates->response)) ? $updates->response : [];
        // WordPress range les extensions à jour dans no_update, avec leur version publiée : sans elles, une extension
        // à jour n'avait pas de « dernière version », et SSM affichait « versions inconnues » sur un site tout à jour.
        $current = (is_object($updates) && isset($updates->no_update) && is_array($updates->no_update)) ? $updates->no_update : [];
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
            $info = $response[$path] ?? ($current[$path] ?? null);
            if ($info !== null) {
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
        $updates = $this->update_state('update_themes', 'wp_update_themes');
        $response = (is_object($updates) && isset($updates->response) && is_array($updates->response)) ? $updates->response : [];
        $current = (is_object($updates) && isset($updates->no_update) && is_array($updates->no_update)) ? $updates->no_update : [];
        $active = get_stylesheet();
        $list = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $latest = null;
            $info = $response[$slug] ?? ($current[$slug] ?? null);
            if ($info !== null) {
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
            // adresse du site telle que WordPress la connaît : SSM propose de suivre un passage en ligne
            'shop_url' => $this->cut_or_null(home_url('/'), 255),
            'extensions' => $this->collect_plugins(),
            'themes' => $this->collect_themes(),
            'connector_version' => SSM_CONNECTOR_VERSION,
            // Comptes rendus des commandes executees au heartbeat precedent. Ils partent UNE fois :
            // SSM les applique a la reception et n'oublie jamais un resultat, mais les renvoyer
            // indefiniment lui ferait repeter des echecs deja connus.
            'results' => $this->take_pending_results(),
        ] + $this->contract3();
    }

    /** Champs du contrat 3 (SSM Core 2.13+) : ignorés sans dommage par un SSM plus ancien. */
    private function contract3() {
        $out = [
            'capabilities' => $this->capabilities(),
            'command_results' => $this->take_command_results(),
            'php_errors' => $this->take_php_errors(),
            'login_enabled' => self::login_allowed(),
            // DISABLE_WP_CRON posé : visiter wp-cron.php ne réveille pas le connecteur (SSM cesse alors de le tenter)
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && (bool) constant('DISABLE_WP_CRON'),
        ];
        $core = $this->core_latest();
        if ($core['known']) {
            // absent tant que WordPress n'a pas vérifié : SSM n'en déduit alors rien
            $out['cms_latest_version'] = $core['version'];
        }
        if (self::login_allowed()) {
            $key = $this->login_key();
            $out['login_key_fingerprint'] = $key ? substr(hash('sha256', $key), 0, 16) : null;
            $out['login_url'] = $this->cut(wp_login_url(), 500);
        }
        return $out;
    }

    /** Version du cœur proposée par WordPress : ['known' => bool, 'version' => string|null]. */
    private function core_latest() {
        $u = get_site_transient('update_core');
        if (!is_object($u) || !isset($u->updates) || !is_array($u->updates)) {
            return ['known' => false, 'version' => null];
        }
        foreach ($u->updates as $o) {
            if (is_object($o) && isset($o->response, $o->current) && $o->response === 'upgrade' && $o->current !== '') {
                return ['known' => true, 'version' => $this->cut($o->current, 50)];
            }
        }
        return ['known' => true, 'version' => null];
    }

    // === Mises a jour demandees par SSM Core ===
    //
    // SSM ne touche pas au site : il n'a pas acces au systeme de fichiers. Il envoie une
    // instruction, ce connecteur l'execute, et renvoie ce qu'il a obtenu. C'est la seule maniere
    // honnete d'ecrire « appliquee » cote SSM : un fait constate ici, pas une intention la-bas.
    //
    // Ce chemin n'est atteint que si SSM l'a explicitement demande — soit que le prestataire a
    // clique sur « Demander », soit que le site est en politique automatique. Defaut, il ne se
    // passe rien.

    /** Les comptes rendus en attente d'envoi. Ils sont renvoyes une seule fois. */
    public function take_pending_results() {
        $r = get_option(self::OPT_RESULTS, []);
        if (!is_array($r)) {
            return [];
        }
        delete_option(self::OPT_RESULTS);   // partir, c'est les remettre : SSM les a recus
        return array_slice($r, 0, 200);
    }

    public function queue_result($update_id, $status, $error = null, $version = null) {
        $entry = ['update_id' => (int) $update_id, 'status' => $status === 'success' ? 'success' : 'failed'];
        if ($version !== null) {
            $entry['version'] = $this->cut_or_null($version, 50);
        }
        if ($error !== null && $error !== '') {
            $entry['error'] = $this->cut((string) $error, 300);
        }
        $all = get_option(self::OPT_RESULTS, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[] = $entry;
        // On borne la file : un connecteur hors ligne des semaines ne doit pas accumuler des
        // comptes rendus que personne ne lira.
        update_option(self::OPT_RESULTS, array_slice($all, -200));
    }

    /**
     * Execute les commandes reçues. Jamais de `\Throwable` non rattrapé : une extension cassée
     * ne doit pas empêcher le heartbeat de partir.
     */
    public function apply_commands($commands) {
        foreach ($commands as $cmd) {
            if (!is_array($cmd) || !isset($cmd['id'])) {
                continue;
            }
            $is_action = isset($cmd['ref']) && $cmd['ref'] === 'command';
            try {
                if ($is_action) {
                    $this->apply_action((int) $cmd['id'], isset($cmd['kind']) ? (string) $cmd['kind'] : '', $cmd);
                } else {
                    $this->apply_command($cmd);
                }
            } catch (\Throwable $e) {
                if ($is_action) {
                    $this->queue_command_result($cmd['id'], 'failed', $e->getMessage());
                } else {
                    $this->queue_result($cmd['id'], 'failed', $e->getMessage());
                }
            }
        }
    }

    private function apply_command($cmd) {
        $id = (int) $cmd['id'];
        $kind = isset($cmd['kind']) ? (string) $cmd['kind'] : '';
        if ($kind === 'update_theme') {
            $this->update_theme($id, $cmd);
            return;
        }
        if ($kind === 'update_core') {
            $this->update_core($id, $cmd);
            return;
        }
        if ($kind !== 'update_extension') {
            $this->queue_result($id, 'failed', 'Commande inconnue : ' . $kind);
            return;
        }
        $slug = isset($cmd['slug']) ? (string) $cmd['slug'] : '';
        // Le slug vient d'un tiers. Sans contrôle, « ../../wp-config.php » désignerait un chemin
        // hors du dossier des extensions.
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            $this->queue_result($id, 'failed', 'Slug d\'extension refusé : ' . $slug);
            return;
        }
        $cible = isset($cmd['to_version']) ? (string) $cmd['to_version'] : '';
        if ($cible === '') {
            $this->queue_result($id, 'failed', 'Version cible absente de la commande.');
            return;
        }

        $plugin = $this->plugin_file($slug);
        if ($plugin === null) {
            $this->queue_result($id, 'failed', 'Extension « ' . $slug . ' » introuvable sur ce site.');
            return;
        }

        $avant = $this->plugin_version($slug);
        if ($avant !== null && version_compare($avant, $cible, '>=')) {
            // Deja a jour, ou en deca : rien a faire, et surtout rien a degrader.
            $this->queue_result($id, 'success', null, $avant);
            return;
        }

        $avant_sante = $this->site_health();
        $backup = $this->backup_plugin($slug);
        if ($backup === false) {
            // Pas de sauvegarde, pas de mise a jour : sans elle, un echec se traduit par un site
            // casse, et SSM ne dispose d'aucun moyen de le remettre droit.
            $this->queue_result($id, 'failed', 'Sauvegarde impossible, mise a jour annulée. Vérifiez les droits d\'écriture de wp-content.');
            return;
        }

        $fichier = $this->plugin_file($slug);
        $etait_active = $fichier !== null && is_plugin_active($fichier);
        $this->active_before_update = $etait_active ? [$fichier] : [];
        $erreur = $this->run_upgrader($slug);
        if ($erreur === null && $etait_active && !is_plugin_active($fichier)) {
            // Hors tâche planifiée (WP-CLI, bouton « Tester »), WordPress désactive l'extension
            // pendant la mise à jour et compte sur le navigateur pour la réactiver : sans ceci,
            // elle restait désactivée (constaté sur un vrai site). Une réactivation impossible
            // (erreur fatale à l'activation) est un échec : on revient en arrière.
            $r = activate_plugin($fichier, '', false, true);
            if (is_wp_error($r)) {
                $erreur = "réactivation impossible après la mise à jour : " . $r->get_error_message();
            }
        }
        if ($erreur !== null) {
            $remis = $this->restore_plugin($slug, $backup);
            // Dire ce qu'est devenu le site fait partie du compte rendu : sans cela, l'opérateur
            // ignore s'il doit intervenir lui-même ou seulement relancer la commande.
            $suffixe = $remis
                ? ' — site remis à la version précédente.'
                : ' — ET LA RESTAURATION AUTOMATIQUE A ÉCHOUÉ, intervention manuelle requise.';
            $this->queue_result($id, 'failed', $erreur . $suffixe);
            return;
        }

        $apres = $this->plugin_version($slug);
        if ($apres === null || version_compare($apres, $cible, '<')) {
            $remis = $this->restore_plugin($slug, $backup);
            $suffixe = $remis
                ? ' — site remis à la version précédente.'
                : ' — ET LA RESTAURATION AUTOMATIQUE A ÉCHOUÉ, intervention manuelle requise.';
            $this->queue_result($id, 'failed', 'Mise à jour annoncée vers ' . $cible . ' mais version installée : ' . ($apres ?: 'inconnue') . '.' . $suffixe);
            return;
        }
        $sante = $this->site_health(true);
        if ($avant_sante['ok'] && !$sante['ok']) {
            // Le site répondait avant, il ne répond plus : on remet la version précédente.
            $remis = $this->restore_plugin($slug, $backup);
            $this->queue_result($id, 'failed', 'Site en erreur après la mise à jour vers ' . $apres . ' (' . $sante['reason'] . ')'
                . ($remis ? ' — site remis à la version précédente.' : ' — ET LA RESTAURATION AUTOMATIQUE A ÉCHOUÉ, intervention manuelle requise.'));
            return;
        }
        $this->queue_result($id, 'success', null, $apres);
    }

    /**
     * La page d'accueil répond-elle ? Une requête vers le site lui-même, après la mise à jour : le
     * processus courant a encore l'ancien code en mémoire, la requête en charge le nouveau.
     * Une requête impossible (pare-feu, authentification) n'est pas un échec : on ne conclut rien.
     */
    public function site_health($after_change = false) {
        if (self::$health_check !== null) {
            return call_user_func(self::$health_check, $after_change);
        }
        if ($after_change) {
            // L'OPcache de PHP ne relit un fichier modifié qu'après `opcache.revalidate_freq`
            // (2 s par défaut) : contrôler tout de suite teste l'ancien code et conclut à tort que
            // tout va bien (constaté sur un vrai site). SSM_CONNECTOR_HEALTH_DELAY pour l'ajuster.
            $delay = defined('SSM_CONNECTOR_HEALTH_DELAY') ? (int) SSM_CONNECTOR_HEALTH_DELAY : 3;
            if ($delay > 0) {
                sleep(min($delay, 30));
            }
        }
        // paramètre unique : un cache de page ne doit pas répondre à la place du site
        $url = add_query_arg('ssm_health', (string) wp_rand(100000, 999999), home_url('/'));
        $r = wp_remote_get($url, [
            'timeout' => 20, 'redirection' => 3, 'sslverify' => false,
            'headers' => ['Cache-Control' => 'no-cache'],
            'user-agent' => 'SSM-Connector/' . SSM_CONNECTOR_VERSION . ' (controle apres mise a jour)',
        ]);
        if (is_wp_error($r)) {
            return ['ok' => true, 'code' => 0, 'reason' => 'contrôle impossible : ' . $r->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        $body = (string) wp_remote_retrieve_body($r);
        if ($code >= 500) {
            return ['ok' => false, 'code' => $code, 'reason' => 'HTTP ' . $code];
        }
        foreach (['There has been a critical error', 'Il y a eu une erreur critique', 'Fatal error</b>:', 'Parse error</b>:'] as $marque) {
            if (stripos($body, $marque) !== false) {
                return ['ok' => false, 'code' => $code, 'reason' => "erreur PHP fatale sur la page d'accueil"];
            }
        }
        return ['ok' => true, 'code' => $code, 'reason' => 'HTTP ' . $code];
    }

    // === Thèmes et cœur ===

    private function theme_root() {
        return function_exists('get_theme_root') ? get_theme_root() : WP_CONTENT_DIR . '/themes';
    }

    private function theme_version($slug) {
        $file = $this->theme_root() . '/' . $slug . '/style.css';
        if (!is_file($file)) {
            return null;
        }
        $data = get_file_data($file, ['Version' => 'Version']);
        return (is_array($data) && !empty($data['Version'])) ? (string) $data['Version'] : null;
    }

    private function update_theme($id, $cmd) {
        $slug = isset($cmd['slug']) ? (string) $cmd['slug'] : '';
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            $this->queue_result($id, 'failed', 'Slug de thème refusé : ' . $slug);
            return;
        }
        $cible = isset($cmd['to_version']) ? (string) $cmd['to_version'] : '';
        $avant = $this->theme_version($slug);
        if ($avant === null) {
            $this->queue_result($id, 'failed', 'Thème « ' . $slug . ' » introuvable sur ce site.');
            return;
        }
        if ($cible !== '' && version_compare($avant, $cible, '>=')) {
            $this->queue_result($id, 'success', null, $avant);
            return;
        }
        $avant_sante = $this->site_health();
        $backup = $this->backup_tree($this->theme_root(), $slug, 'theme-');
        if ($backup === false) {
            $this->queue_result($id, 'failed', 'Sauvegarde impossible, mise a jour annulée. Vérifiez les droits d\'écriture de wp-content.');
            return;
        }
        $erreur = $this->run_named_upgrader('Theme_Upgrader', $slug, $this->theme_root());
        $apres = $this->theme_version($slug);
        $casse = $erreur !== null || $apres === null || ($cible !== '' && version_compare($apres, $cible, '<'));
        $sante = $casse ? null : $this->site_health(true);
        if ($casse || ($avant_sante['ok'] && !$sante['ok'])) {
            $remis = $this->restore_tree($this->theme_root(), $slug, $backup);
            $raison = $erreur !== null ? $erreur : ($casse ? 'version installée : ' . ($apres ?: 'inconnue')
                : 'site en erreur après la mise à jour (' . $sante['reason'] . ')');
            $this->queue_result($id, 'failed', $raison . ($remis ? ' — thème remis à la version précédente.'
                : ' — ET LA RESTAURATION AUTOMATIQUE A ÉCHOUÉ, intervention manuelle requise.'));
            return;
        }
        $this->queue_result($id, 'success', null, $apres);
    }

    private function core_version() {
        $file = ABSPATH . 'wp-includes/version.php';
        if (is_file($file) && preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($file), $m)) {
            return $m[1];
        }
        return get_bloginfo('version');
    }

    /**
     * Mise à jour du cœur. Pas de retour arrière automatique : remettre les fichiers du cœur depuis
     * l'intérieur de WordPress n'est pas fiable. Le contrôle de santé est fait, et son résultat
     * rapporté tel quel — un site cassé après une mise à jour du cœur est dit, pas caché.
     */
    private function update_core($id, $cmd) {
        $cible = isset($cmd['to_version']) ? (string) $cmd['to_version'] : '';
        $avant = $this->core_version();
        if ($cible !== '' && version_compare($avant, $cible, '>=')) {
            $this->queue_result($id, 'success', null, $avant);
            return;
        }
        if (!$this->disk_writable(ABSPATH)) {
            $this->queue_result($id, 'failed', "Les fichiers de WordPress ne sont pas accessibles en écriture depuis cette tâche.");
            return;
        }
        $avant_sante = $this->site_health();
        if (!defined('FS_METHOD')) {
            define('FS_METHOD', 'direct');
        }
        foreach (['file.php', 'misc.php', 'class-wp-upgrader.php', 'update.php'] as $fichier) {
            $chemin = ABSPATH . 'wp-admin/includes/' . $fichier;
            if (file_exists($chemin)) {
                require_once $chemin;
            }
        }
        if (!class_exists('\Core_Upgrader') || !function_exists('find_core_update')) {
            $this->queue_result($id, 'failed', 'Le module de mise à jour de WordPress est indisponible sur cette installation.');
            return;
        }
        $update = find_core_update($cible, function_exists('get_locale') ? get_locale() : 'en_US');
        if (!$update) {
            $update = find_core_update($cible, 'en_US');
        }
        if (!$update) {
            $this->queue_result($id, 'failed', 'WordPress ne propose pas la version ' . $cible . ' (relancez la vérification des mises à jour).');
            return;
        }
        add_filter('request_filesystem_credentials', [$this, 'grant_core_filesystem'], 10, 2);
        try {
            $upgrader = new \Core_Upgrader(new \Automatic_Upgrader_Skin());
            $res = $upgrader->upgrade($update);
        } catch (\Throwable $e) {
            $res = new WP_Error('ssm', $e->getMessage());
        } finally {
            remove_filter('request_filesystem_credentials', [$this, 'grant_core_filesystem'], 10);
        }
        $apres = $this->core_version();
        if (is_wp_error($res) || ($cible !== '' && version_compare($apres, $cible, '<'))) {
            $this->queue_result($id, 'failed', (is_wp_error($res) ? $res->get_error_message() : 'version installée : ' . $apres)
                . ' — le cœur n\'a pas de retour arrière automatique : vérifiez le site.');
            return;
        }
        $sante = $this->site_health(true);
        if ($avant_sante['ok'] && !$sante['ok']) {
            $this->queue_result($id, 'failed', 'WordPress ' . $apres . ' installé mais le site est en erreur (' . $sante['reason']
                . ') — pas de retour arrière automatique pour le cœur, intervention requise.', $apres);
            return;
        }
        $this->queue_result($id, 'success', null, $apres);
    }

    public function grant_core_filesystem($credentials = false, $context = '') {
        return $this->disk_writable(ABSPATH) ? true : $credentials;
    }

    /** Lance un upgrader WordPress (Theme_Upgrader…) sur un élément. Null si réussi, sinon la raison. */
    private function run_named_upgrader($class, $slug, $root) {
        if (!is_dir($root) || !$this->disk_writable($root)) {
            return 'Le dossier ' . basename($root) . ' n\'est pas accessible en écriture depuis cette tâche.';
        }
        if (!defined('FS_METHOD')) {
            define('FS_METHOD', 'direct');
        }
        foreach (['file.php', 'misc.php', 'class-wp-upgrader.php'] as $fichier) {
            $chemin = ABSPATH . 'wp-admin/includes/' . $fichier;
            if (file_exists($chemin)) {
                require_once $chemin;
            }
        }
        if (!class_exists('\\' . $class) || !class_exists('\Automatic_Upgrader_Skin')) {
            return 'Le module de mise à jour de WordPress est indisponible sur cette installation.';
        }
        add_filter('request_filesystem_credentials', [$this, 'grant_any_filesystem'], 10, 2);
        if ($class === 'Theme_Upgrader') {
            $this->update_state('update_themes', 'wp_update_themes');   // même raison que pour les extensions
        }
        try {
            $qualified = '\\' . $class;
            $upgrader = new $qualified(new \Automatic_Upgrader_Skin());
            $res = $upgrader->upgrade($slug);
        } catch (\Throwable $e) {
            return 'Erreur pendant la mise à jour : ' . $e->getMessage();
        } finally {
            remove_filter('request_filesystem_credentials', [$this, 'grant_any_filesystem'], 10);
        }
        if (is_wp_error($res)) {
            return $res->get_error_message();
        }
        if ($res === false) {
            return $class === 'Theme_Upgrader' ? $this->refusal_reason('update_themes', $slug) : 'WordPress a refusé la mise à jour sans détail.';
        }
        return null;
    }

    public function grant_any_filesystem($credentials = false, $context = '') {
        return $this->disk_writable(WP_CONTENT_DIR) ? true : $credentials;
    }

    /** Sauvegarde générique d'un dossier (thème…) dans ssm-backups, trois générations gardées. */
    private function backup_tree($root, $slug, $prefix) {
        $source = $root . '/' . $slug;
        if (!is_dir($source) || !$this->disk_writable(WP_PLUGIN_DIR)) {
            return false;
        }
        $base = WP_PLUGIN_DIR . '/' . self::BACKUP_DIR;
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            return false;
        }
        $dest = $base . '/' . $prefix . $slug . '-' . gmdate('Ymd-His');
        if (!$this->copy_tree($source, $dest)) {
            return false;
        }
        $this->prune_backups($prefix . $slug);
        return $dest;
    }

    private function restore_tree($root, $slug, $backup) {
        if (!$backup || !is_dir($backup)) {
            return false;
        }
        $dest = $root . '/' . $slug;
        $this->remove_tree($dest);
        return $this->copy_tree($backup, $dest);
    }

    // === Actions demandées par SSM (contrat 3) : extensions, sauvegarde ===

    public function take_command_results() {
        $r = get_option(self::OPT_CMD_RESULTS, []);
        if (!is_array($r)) {
            return [];
        }
        delete_option(self::OPT_CMD_RESULTS);
        return array_slice($r, 0, 200);
    }

    public function queue_command_result($command_id, $status, $error = null, $data = null) {
        $entry = ['command_id' => (int) $command_id, 'status' => $status === 'success' ? 'success' : 'failed'];
        if ($error !== null && $error !== '') {
            $entry['error'] = $this->cut((string) $error, 500);
        }
        if (is_array($data)) {
            $entry['data'] = $data;
        }
        $all = get_option(self::OPT_CMD_RESULTS, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[] = $entry;
        update_option(self::OPT_CMD_RESULTS, array_slice($all, -200));
    }

    private function apply_action($id, $kind, $cmd) {
        $params = isset($cmd['params']) && is_array($cmd['params']) ? $cmd['params'] : [];
        if ($kind === 'backup_site') {
            $this->backup_site($id, $params);
            return;
        }
        $fichier_ops = [
            'list_files' => 'op_list_files', 'read_file' => 'op_read_file',
            'write_file' => 'op_write_file', 'move_file' => 'op_move_file',
            'delete_file' => 'op_delete_file', 'make_dir' => 'op_make_dir', 'copy_file' => 'op_copy_file',
            'download_file' => 'op_download_file', 'install_file' => 'op_install_file',
            'extract_archive' => 'op_extract_archive',
        ];
        if (isset($fichier_ops[$kind])) {
            // Une ecriture est refusee si le connecteur n'annonce pas write_files : c'est une
            // garde de plus, independante de celle de SSM.
            if (in_array($kind, ['write_file', 'move_file', 'delete_file', 'make_dir', 'copy_file',
                                 'install_file', 'extract_archive'], true)
                && !in_array('write_files', $this->capabilities(), true)) {
                $this->queue_command_result($id, 'failed', "Ce connecteur n'annonce pas write_files.");
                return;
            }
            try {
                $this->{$fichier_ops[$kind]}($id, $params);
            } catch (\Throwable $e) {
                $this->queue_command_result($id, 'failed', $e->getMessage());
            }
            return;
        }
        $slug = isset($cmd['slug']) ? (string) $cmd['slug'] : '';
        if (!in_array($kind, ['plugin_activate', 'plugin_deactivate', 'plugin_install', 'plugin_delete'], true)) {
            $this->queue_command_result($id, 'failed', 'Action inconnue : ' . $kind);
            return;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            $this->queue_command_result($id, 'failed', "Identifiant d'extension refusé : " . $slug);
            return;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $file = $this->plugin_file($slug);
        $self = plugin_basename(SSM_CONNECTOR_FILE);
        if ($file !== null && $file === $self && $kind !== 'plugin_activate') {
            $this->queue_command_result($id, 'failed', 'Le connecteur ne se désactive ni ne se supprime lui-même.');
            return;
        }
        if ($kind === 'plugin_install') {
            $this->install_plugin($id, $slug, $file, $params);
            return;
        }
        if ($file === null) {
            $this->queue_command_result($id, 'failed', 'Extension « ' . $slug . ' » introuvable sur ce site.');
            return;
        }
        if ($kind === 'plugin_activate') {
            $r = activate_plugin($file);
            if (is_wp_error($r)) {
                $this->queue_command_result($id, 'failed', $r->get_error_message());
                return;
            }
            $ok = is_plugin_active($file);
            $this->queue_command_result($id, $ok ? 'success' : 'failed', $ok ? null : "WordPress n'a pas activé l'extension.");
            return;
        }
        if ($kind === 'plugin_deactivate') {
            deactivate_plugins([$file]);
            $ok = !is_plugin_active($file);
            $this->queue_command_result($id, $ok ? 'success' : 'failed', $ok ? null : "WordPress n'a pas désactivé l'extension.");
            return;
        }
        // plugin_delete : jamais une extension active (elle pourrait porter le site)
        if (is_plugin_active($file)) {
            $this->queue_command_result($id, 'failed', "Extension active : désactivez-la d'abord.");
            return;
        }
        if (!function_exists('delete_plugins')) {
            foreach (['file.php', 'plugin.php'] as $f) {
                if (file_exists(ABSPATH . 'wp-admin/includes/' . $f)) {
                    require_once ABSPATH . 'wp-admin/includes/' . $f;
                }
            }
        }
        add_filter('request_filesystem_credentials', [$this, 'grant_filesystem'], 10, 2);
        try {
            $r = delete_plugins([$file]);
        } finally {
            remove_filter('request_filesystem_credentials', [$this, 'grant_filesystem'], 10);
        }
        if (is_wp_error($r) || $r === false || $this->plugin_file($slug) !== null) {
            $this->queue_command_result($id, 'failed', is_wp_error($r) ? $r->get_error_message() : 'Suppression refusée par WordPress.');
            return;
        }
        $this->queue_command_result($id, 'success');
    }

    /**
     * Sources d'où une archive d'extension peut être installée en dehors de wordpress.org : par défaut les releases
     * GitHub de fred-selest (WP Migration…). Une adresse hors de cette liste est refusée, quoi que SSM demande.
     * Filtre `ssm_connector_package_sources` pour en changer.
     */
    public function package_sources() {
        $sources = apply_filters('ssm_connector_package_sources', ['https://github.com/fred-selest/']);
        return is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [];
    }

    /** L'adresse d'une archive est acceptée si elle est en https, vient d'une release d'une source connue et finit par .zip. */
    private function package_allowed($url) {
        if (!is_string($url) || strlen($url) > 500 || strpos($url, '..') !== false || !preg_match('/\.zip$/i', $url)) {
            return false;
        }
        if (strpos($url, '/releases/download/') === false || preg_match('/[\s?#@\\\\]/', $url)) {
            return false;
        }
        foreach ($this->package_sources() as $prefix) {
            if (strpos($prefix, 'https://') === 0 && strpos($url, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Installation : depuis le répertoire officiel (le paquet doit venir de downloads.wordpress.org) ou, si SSM transmet
     * `package_url`, depuis une archive d'une source de la liste blanche (extension maison, voir package_sources()).
     */
    private function install_plugin($id, $slug, $file, $params = []) {
        if ($file !== null) {
            $this->queue_command_result($id, 'success', 'déjà installée');
            return;
        }
        $package = isset($params['package_url']) ? $params['package_url'] : null;
        foreach (['plugin-install.php', 'file.php', 'misc.php', 'class-wp-upgrader.php'] as $f) {
            if (file_exists(ABSPATH . 'wp-admin/includes/' . $f)) {
                require_once ABSPATH . 'wp-admin/includes/' . $f;
            }
        }
        if (!function_exists('plugins_api') || !class_exists('\Plugin_Upgrader')) {
            $this->queue_command_result($id, 'failed', "Le module d'installation de WordPress est indisponible.");
            return;
        }
        if ($package !== null) {
            if (!$this->package_allowed($package)) {
                $this->queue_command_result($id, 'failed', "Archive refusée : elle ne vient pas d'une source autorisée par ce connecteur.");
                return;
            }
            $download = $package;
        } else {
            $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
            if (is_wp_error($api) || !is_object($api) || empty($api->download_link)) {
                $this->queue_command_result($id, 'failed', 'Extension « ' . $slug . ' » inconnue du répertoire wordpress.org.');
                return;
            }
            if (strpos((string) $api->download_link, 'https://downloads.wordpress.org/') !== 0) {
                $this->queue_command_result($id, 'failed', 'Paquet refusé : il ne vient pas de downloads.wordpress.org.');
                return;
            }
            $download = $api->download_link;
        }
        if (!$this->disk_writable(WP_PLUGIN_DIR)) {
            $this->queue_command_result($id, 'failed', "Le dossier des extensions n'est pas accessible en écriture.");
            return;
        }
        if (!defined('FS_METHOD')) {
            define('FS_METHOD', 'direct');
        }
        add_filter('request_filesystem_credentials', [$this, 'grant_filesystem'], 10, 2);
        try {
            $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
            $r = $upgrader->install($download);
        } catch (\Throwable $e) {
            $r = new WP_Error('ssm', $e->getMessage());
        } finally {
            remove_filter('request_filesystem_credentials', [$this, 'grant_filesystem'], 10);
        }
        if (is_wp_error($r) || $r === false || $this->plugin_file($slug) === null) {
            $this->queue_command_result($id, 'failed', is_wp_error($r) ? $r->get_error_message() : "WordPress n'a pas installé l'extension.");
            return;
        }
        if ($package !== null && !empty($params['activate'])) {
            $installed = $this->plugin_file($slug);
            $a = activate_plugin($installed);
            if (is_wp_error($a) || !is_plugin_active($installed)) {
                $this->queue_command_result($id, 'success', 'installée, mais non activée : ' . (is_wp_error($a) ? $a->get_error_message() : "WordPress n'a pas activé l'extension."));
                return;
            }
            $this->queue_command_result($id, 'success', 'installée et activée');
            return;
        }
        $this->queue_command_result($id, 'success', 'installée, non activée');
    }

    // === Sauvegarde du site vers le stockage de SSM (URL pré-signée) ===

    /**
     * Exporte la base (database.sql) et les fichiers (wp-config.php, wp-content) dans une archive
     * zip, la dépose sur l'URL reçue, et rapporte taille et empreinte. Rien ne passe par SSM.
     * Le dossier de travail est protégé et vidé à la fin, quoi qu'il arrive.
     */
    private function backup_site($id, $params) {
        $url = isset($params['upload_url']) ? (string) $params['upload_url'] : '';
        $max = isset($params['max_bytes']) ? (int) $params['max_bytes'] : 5368709120;
        $uploads = !isset($params['include_uploads']) || $params['include_uploads'];
        if (strpos($url, 'https://') !== 0 && strpos($url, 'http://') !== 0) {
            $this->queue_command_result($id, 'failed', 'Adresse de dépôt invalide.');
            return;
        }
        if (!class_exists('ZipArchive')) {
            $this->queue_command_result($id, 'failed', "L'extension PHP zip est requise pour sauvegarder.");
            return;
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        $dir = WP_CONTENT_DIR . '/' . self::BACKUP_TMP;
        $this->remove_tree($dir);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->queue_command_result($id, 'failed', 'Dossier de travail impossible à créer dans wp-content.');
            return;
        }
        @file_put_contents($dir . '/index.php', "<?php\n// Silence.\n");
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        try {
            $sql = $dir . '/database.sql';
            $tables = $this->dump_database($sql);
            $zip_path = $dir . '/site.zip';
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Archive impossible à créer.");
            }
            $zip->addFile($sql, 'database.sql');
            $config = is_file(ABSPATH . 'wp-config.php') ? ABSPATH . 'wp-config.php' : dirname(ABSPATH) . '/wp-config.php';
            if (is_file($config)) {
                $zip->addFile($config, 'wp-config.php');
            }
            $files = $this->zip_tree($zip, WP_CONTENT_DIR, 'wp-content', $this->backup_excludes($uploads));
            $zip->close();
            $size = (int) filesize($zip_path);
            if ($size > $max) {
                throw new RuntimeException('Archive de ' . round($size / 1048576) . ' Mo : au-delà de la limite de dépôt.');
            }
            $sha = hash_file('sha256', $zip_path);
            $up = $this->upload($url, $zip_path, $size);
            if (!$up['ok']) {
                throw new RuntimeException('Dépôt refusé : ' . $up['error']);
            }
            $this->queue_command_result($id, 'success', null,
                ['size_bytes' => $size, 'sha256' => $sha, 'files' => $files + 1, 'tables' => $tables]);
        } catch (\Throwable $e) {
            $this->queue_command_result($id, 'failed', $e->getMessage());
        } finally {
            $this->remove_tree($dir);
        }
    }

    private function backup_excludes($uploads) {
        $ex = ['cache', self::BACKUP_TMP, 'upgrade', 'upgrade-temp-backup', 'updraft', 'ai1wm-backups', 'backup-db',
               'wpvividbackups', 'backups-dup-lite', 'backups-dup-pro', 'et-cache', 'plugins/' . self::BACKUP_DIR, 'debug.log'];
        if (!$uploads) {
            $ex[] = 'uploads';
        }
        return $ex;
    }

    /** Ajoute un dossier à l'archive (chemins relatifs à wp-content exclus). Renvoie le nombre de fichiers. */
    private function zip_tree($zip, $root, $prefix, $excludes, $rel = '') {
        $count = 0;
        $items = @scandir($root . ($rel !== '' ? '/' . $rel : ''));
        if ($items === false) {
            return 0;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path_rel = $rel !== '' ? $rel . '/' . $item : $item;
            if (in_array($path_rel, $excludes, true)) {
                continue;
            }
            $full = $root . '/' . $path_rel;
            if (is_link($full)) {
                continue;     // un lien pourrait sortir de wp-content
            }
            if (is_dir($full)) {
                $count += $this->zip_tree($zip, $root, $prefix, $excludes, $path_rel);
            } elseif (is_readable($full)) {
                $zip->addFile($full, $prefix . '/' . $path_rel);
                $count++;
            }
        }
        return $count;
    }

    private static function sql_value($v) {
        if ($v === null) {
            return 'NULL';
        }
        return "'" . str_replace(["\\", "\0", "\n", "\r", "'", "\x1a"], ["\\\\", "\\0", "\\n", "\\r", "\\'", "\\Z"], (string) $v) . "'";
    }

    /** Export SQL des tables de WordPress (préfixe du site), par lots. Renvoie le nombre de tables. */
    private function dump_database($path) {
        global $wpdb;
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new RuntimeException("Export de la base impossible (écriture).");
        }
        fwrite($fh, "-- Export SSM Connector " . SSM_CONNECTOR_VERSION . ' du ' . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "SET NAMES utf8mb4;\nSET foreign_key_checks = 0;\n\n");
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $wpdb->prefix) . '%';
        $tables = $wpdb->get_col("SHOW TABLES LIKE '" . str_replace("'", "''", $like) . "'");
        $n = 0;
        foreach ((array) $tables as $table) {
            $table = str_replace('`', '', (string) $table);
            $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            if (!is_array($create) || !isset($create[1])) {
                continue;
            }
            fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n\n");
            for ($offset = 0; ; $offset += 500) {
                $rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT $offset, 500", ARRAY_A);
                if (!$rows) {
                    break;
                }
                foreach ($rows as $row) {
                    fwrite($fh, "INSERT INTO `$table` VALUES (" . implode(',', array_map([__CLASS__, 'sql_value'], array_values($row))) . ");\n");
                }
                if (count($rows) < 500) {
                    break;
                }
            }
            fwrite($fh, "\n");
            $n++;
        }
        fwrite($fh, "SET foreign_key_checks = 1;\n");
        fclose($fh);
        return $n;
    }

    /** PUT de l'archive vers l'URL pré-signée, en flux (pas de chargement en mémoire). */
    private function upload($url, $path, $size) {
        if (self::$uploader !== null) {
            return call_user_func(self::$uploader, $url, $path, $size);
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => "l'extension PHP curl est requise"];
        }
        $fp = fopen($path, 'rb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fp, CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3600, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/zip'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($body === false) {
            return ['ok' => false, 'error' => 'réseau : ' . $err];
        }
        return $code >= 200 && $code < 300 ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => 'HTTP ' . $code . ' ' . $this->cut(strip_tags((string) $body), 160)];
    }

    // === Erreurs PHP ===

    private function relative($text) {
        $text = (string) $text;
        foreach (array_unique([ABSPATH, function_exists('realpath') ? (string) realpath(ABSPATH) . '/' : '']) as $root) {
            if ($root !== '' && $root !== '/') {
                $text = str_replace($root, '', $text);
            }
        }
        return $text;
    }

    /** Fonction d'arrêt : une erreur fatale de cette requête est mise de côté pour SSM. */
    public function capture_fatal() {
        $e = error_get_last();
        if (!is_array($e) || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        if ($this->debug_log_path() !== null) {
            return;   // PHP l'écrit déjà dans le journal, lu au heartbeat : pas de double comptage
        }
        try {
            $this->record_php_error($e['type'] === E_PARSE ? 'parse' : 'fatal', $e['message'], $e['file'], $e['line']);
        } catch (\Throwable $x) {
            // la base elle-même peut être la cause : on n'aggrave rien
        }
    }

    public function record_php_error($level, $message, $file, $line, $count = 1, $when = null) {
        $all = get_option(self::OPT_PHP_ERRORS, []);
        if (!is_array($all)) {
            $all = [];
        }
        $message = (string) strtok((string) $message, "\n");
        // « … in /chemin/fichier.php:12 » : le fichier et la ligne ont leurs propres champs
        if (preg_match('/^(.*) in (\S+?)(?: on line (\d+)|:(\d+))\s*$/', $message, $f)) {
            $message = $f[1];
            if ($file === null) {
                $file = $f[2];
                $line = (int) ($f[3] !== '' ? $f[3] : $f[4]);
            }
        }
        $message = $this->cut($this->relative($message), 1000);
        $file = $file !== null ? $this->cut($this->relative($file), 300) : null;
        $key = md5($level . '|' . $message . '|' . $file . '|' . $line);
        $at = gmdate('c', $when ?: time());
        if (isset($all[$key])) {
            $all[$key]['count'] += $count;
            $all[$key]['last_seen'] = $at;
        } elseif (count($all) < self::PHP_ERRORS_MAX) {
            $all[$key] = ['level' => $level, 'message' => $message !== '' ? $message : '(sans message)', 'file' => $file,
                          'line' => $line !== null ? (int) $line : null, 'count' => $count, 'last_seen' => $at];
        } else {
            return;
        }
        update_option(self::OPT_PHP_ERRORS, $all, false);
    }

    /** Lit la suite du journal de PHP (WP_DEBUG_LOG, sinon error_log) depuis la dernière lecture. */
    /** Le journal où PHP écrit ses erreurs, s'il est lisible : WP_DEBUG_LOG, sinon error_log. */
    public function debug_log_path() {
        $path = null;
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $path = is_string(WP_DEBUG_LOG) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
        } elseif (($p = ini_get('error_log')) && is_string($p)) {
            $path = $p;
        }
        return ($path && is_file($path) && is_readable($path)) ? $path : null;
    }

    public function read_debug_log() {
        $path = $this->debug_log_path();
        if ($path === null) {
            return;
        }
        clearstatcache(true, $path);   // sinon filesize() rend la taille mise en cache par PHP
        $size = (int) filesize($path);
        $offset = (int) get_option(self::OPT_LOG_OFFSET, -1);
        if ($offset < 0 || $offset > $size) {
            $offset = max(0, $size - self::LOG_READ_MAX);   // premier passage, ou journal vidé
        }
        if ($size <= $offset) {
            // Rien de neuf. PHP 7.4 refuse fread(…, 0) par un avertissement, que PHP écrit… dans ce même journal :
            // chaque envoi ajoutait une fausse erreur du connecteur au journal du site, et SSM la remontait.
            return;
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return;
        }
        fseek($fh, $offset);
        $chunk = (string) fread($fh, min(self::LOG_READ_MAX, $size - $offset));
        fclose($fh);
        $end = strrpos($chunk, "\n");
        if ($end === false) {
            return;
        }
        $chunk = substr($chunk, 0, $end + 1);
        update_option(self::OPT_LOG_OFFSET, $offset + strlen($chunk), false);
        $levels = ['fatal error' => 'fatal', 'catchable fatal error' => 'fatal', 'recoverable fatal error' => 'fatal',
                   'parse error' => 'parse', 'warning' => 'warning', 'notice' => 'notice', 'deprecated' => 'deprecated'];
        foreach (explode("\n", $chunk) as $l) {
            if (!preg_match('/^\[([^\]]+)\] PHP ([A-Za-z ]+?):\s+(.*)$/', $l, $m)) {
                continue;
            }
            $level = isset($levels[strtolower($m[2])]) ? $levels[strtolower($m[2])] : null;
            if ($level === null) {
                continue;
            }
            $msg = $m[3];
            $file = null;
            $line = null;
            if (preg_match('/^(.*) in (\S+?)(?: on line (\d+)|:(\d+))\s*$/', $msg, $f)) {
                $msg = $f[1];
                $file = $f[2];
                $line = (int) ($f[3] !== '' ? $f[3] : $f[4]);
            }
            $ts = strtotime($m[1]);
            $this->record_php_error($level, $msg, $file, $line, 1, $ts ?: null);
        }
    }

    public function take_php_errors() {
        try {
            $this->read_debug_log();
        } catch (\Throwable $e) {
            // un journal illisible n'empêche pas le heartbeat
        }
        $all = get_option(self::OPT_PHP_ERRORS, []);
        delete_option(self::OPT_PHP_ERRORS);
        return is_array($all) ? array_slice(array_values($all), 0, self::PHP_ERRORS_MAX) : [];
    }

    // === Connexion directe depuis SSM (seulement si SSM_CONNECTOR_ALLOW_LOGIN) ===

    public function login_key() {
        $stored = get_option(self::OPT_LOGIN_KEY, '');
        return is_string($stored) && $stored !== '' ? self::reveal($stored) : null;
    }

    private static function b64url_decode($s) {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Vérifie un jeton de connexion. Renvoie l'utilisateur à connecter, ou la raison du refus. */
    public function verify_login_token($token) {
        if (!self::login_allowed()) {
            return 'connexion directe désactivée sur ce site';
        }
        $key = $this->login_key();
        if (!$key) {
            return 'clé de connexion pas encore reçue de SSM';
        }
        $parts = explode('.', (string) $token);
        if (count($parts) !== 2) {
            return 'jeton illisible';
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0], $key, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $parts[1])) {
            return 'signature invalide';
        }
        $claims = json_decode((string) self::b64url_decode($parts[0]), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['n'])) {
            return 'jeton incomplet';
        }
        $now = time();
        if ($now > (int) $claims['exp']) {
            return 'lien expiré (60 secondes)';
        }
        if ((int) $claims['exp'] - $now > 120) {
            return 'expiration invalide';
        }
        $site_id = (int) get_option(self::OPT_SITE_ID, 0);
        if ($site_id && isset($claims['s']) && (int) $claims['s'] !== $site_id) {
            return 'lien destiné à un autre site';
        }
        $nonce_key = 'ssm_login_' . md5((string) $claims['n']);
        if (get_transient($nonce_key)) {
            return 'lien déjà utilisé';
        }
        set_transient($nonce_key, 1, 300);
        $user = $this->login_user();
        if (!$user) {
            return 'aucun administrateur à connecter';
        }
        $log = get_option(self::OPT_LOGIN_LOG, []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, ['at' => current_time('mysql'), 'by' => substr((string) ($claims['u'] ?? ''), 0, 64), 'as' => $user->user_login]);
        update_option(self::OPT_LOGIN_LOG, array_slice($log, 0, 20), false);
        return $user;
    }

    /**
     * L'administrateur désigné (SSM_CONNECTOR_LOGIN_USER, sinon celui choisi dans la page de l'extension),
     * sinon le premier administrateur. Jamais un compte choisi par SSM.
     */
    public function login_user() {
        if (defined('SSM_CONNECTOR_LOGIN_USER') && SSM_CONNECTOR_LOGIN_USER) {
            $u = get_user_by('login', (string) SSM_CONNECTOR_LOGIN_USER);
            if (!$u) {
                $u = get_user_by('email', (string) SSM_CONNECTOR_LOGIN_USER);
            }
            return $u ?: null;
        }
        $chosen = (int) get_option(self::OPT_LOGIN_USER, 0);
        if ($chosen > 0) {
            // Toujours administrateur au moment de la connexion : un compte rétrogradé n'est plus connecté.
            foreach ($this->login_candidates() as $u) {
                if ((int) $u->ID === $chosen) {
                    return $u;
                }
            }
            return null;
        }
        $admins = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1]);
        return $admins ? $admins[0] : null;
    }

    /** Crochet login_init (posé seulement si la connexion directe est activée). */
    public function handle_login() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'ssm_login') {
            return;
        }
        $token = isset($_GET['ssm_token']) ? (string) wp_unslash($_GET['ssm_token']) : '';
        $user = $this->verify_login_token($token);
        if (is_string($user)) {
            wp_die(esc_html('Lien de connexion SSM refusé : ' . $user . '. Demandez-en un nouveau depuis SSM.'), 'SSM', ['response' => 403]);
        }
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        wp_safe_redirect(admin_url());
        exit;
    }

    /** Fichier principal d'une extension à partir de son dossier. Null si elle n'est pas installée. */
    private function plugin_file($slug) {
        foreach (array_keys(get_plugins()) as $file) {
            // dossier (« akismet/akismet.php ») ou extension d'un seul fichier (« hello.php »)
            if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                return $file;
            }
        }
        return null;
    }

    private function plugin_version($slug) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $file => $data) {
            if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                return isset($data['Version']) ? (string) $data['Version'] : null;
            }
        }
        return null;
    }

    /** Copie l'extension avant toute modification. Retourne le chemin, ou false si la copie est impossible. */
    private function disk_writable($path) {
        if (self::$disk_check !== null) {
            return (bool) call_user_func(self::$disk_check, $path);
        }
        return is_writable($path);
    }

    private function backup_plugin($slug) {
        $source = WP_PLUGIN_DIR . '/' . $slug;
        $single = !is_dir($source) && is_file($source . '.php');   // extension d'un seul fichier
        if ((!is_dir($source) && !$single) || !$this->disk_writable(WP_PLUGIN_DIR)) {
            return false;
        }
        $base = WP_PLUGIN_DIR . '/' . self::BACKUP_DIR;
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            return false;
        }
        $dest = $base . '/' . $slug . '-' . gmdate('Ymd-His');
        if ($single) {
            if ((!@mkdir($dest, 0755, true) && !is_dir($dest)) || !@copy($source . '.php', $dest . '/' . self::SINGLE_FILE)) {
                return false;
            }
        } elseif (!$this->copy_tree($source, $dest)) {
            return false;
        }
        $this->prune_backups($slug);
        return $dest;
    }

    /** Ne garde que les N sauvegardes les plus recentes d'une extension. */
    private function prune_backups($slug) {
        $base = WP_PLUGIN_DIR . '/' . self::BACKUP_DIR;
        $trouves = glob($base . '/' . $slug . '-*', GLOB_ONLYDIR) ?: [];
        if (count($trouves) <= self::BACKUPS_KEPT) {
            return;
        }
        rsort($trouves);   // noms horodates : l'ordre lexicographique est l'ordre chronologique
        foreach (array_slice($trouves, self::BACKUPS_KEPT) as $vieux) {
            $this->remove_tree($vieux);
        }
    }

    private function restore_plugin($slug, $backup) {
        $ok = $this->restore_plugin_files($slug, $backup);
        $fichier = $ok ? $this->plugin_file($slug) : null;
        if ($fichier !== null && !is_plugin_active($fichier) && in_array($fichier, (array) $this->active_before_update, true)) {
            activate_plugin($fichier, '', false, true);   // l'ancienne version reprend sa place, active
        }
        return $ok;
    }

    /** Extensions actives au moment de la dernière mise à jour (pour les réactiver après un retour arrière). */
    private $active_before_update = [];

    private function restore_plugin_files($slug, $backup) {
        if (!$backup || !is_dir($backup)) {
            return false;
        }
        if (is_file($backup . '/' . self::SINGLE_FILE)) {
            return @copy($backup . '/' . self::SINGLE_FILE, WP_PLUGIN_DIR . '/' . $slug . '.php');
        }
        $dest = WP_PLUGIN_DIR . '/' . $slug;
        $this->remove_tree($dest);
        return $this->copy_tree($backup, $dest);
    }

    private function copy_tree($from, $to) {
        if (!is_dir($to) && !@mkdir($to, 0755, true) && !is_dir($to)) {
            return false;
        }
        $items = @scandir($from);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $from . '/' . $item;
            $dst = $to . '/' . $item;
            if (is_dir($src)) {
                if (!$this->copy_tree($src, $dst)) {
                    return false;
                }
            } elseif (!@copy($src, $dst)) {
                return false;
            }
        }
        return true;
    }

    private function remove_tree($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                is_dir($path) ? $this->remove_tree($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Lance la mise a jour WordPress. Retourne null si reussie, sinon la raison de l'echec.
     *
     * WordPress refuse par defaut d'ecrire les fichiers depuis une tache planifiee : il veut des
     * identifiants FTP. On ne les demande pas — on verifie plutot que `wp-content` est
     * reellement inscriptible, et on ne pretend avoir rien fait sinon.
     */
    private function run_upgrader($slug) {
        if (!is_dir(WP_PLUGIN_DIR) || !$this->disk_writable(WP_PLUGIN_DIR)) {
            return 'Le dossier wp-content/plugins n\'est pas accessible en écriture depuis cette tâche.';
        }
        if (!defined('FS_METHOD')) {
            define('FS_METHOD', 'direct');
        }
        // `file_exists` avant chaque `require_once` : sur une installation où wp-admin est
        // incomplet, une alerte PHP inutile accompanies le message d'erreur utile. Sur un
        // WordPress normal, les trois fichiers sont la.
        foreach (['file.php', 'misc.php', 'class-wp-upgrader.php'] as $fichier) {
            $chemin = ABSPATH . 'wp-admin/includes/' . $fichier;
            if (file_exists($chemin)) {
                require_once $chemin;
            }
        }
        if (!class_exists('\\Plugin_Upgrader') || !class_exists('\\Automatic_Upgrader_Skin')) {
            return 'Le module de mise à jour de WordPress est indisponible sur cette installation.';
        }
        if (function_exists('add_filter')) {
            add_filter('request_filesystem_credentials', [$this, 'grant_filesystem'], 10, 2);
        }
        // Plugin_Upgrader::upgrade() attend le fichier principal (« akismet/akismet.php »), pas le
        // dossier : avec le dossier, WordPress refuse sans détail (constaté sur un vrai site).
        $fichier = $this->plugin_file($slug);
        if ($fichier === null) {
            return 'Extension « ' . $slug . ' » introuvable sur ce site.';
        }
        // WordPress vide sa liste de mises à jour après chaque mise à jour réussie, et Plugin_Upgrader::upgrade() refuse sans
        // détail toute extension qui n'y figure pas : sans cette ligne, la 1re extension d'un lot réussissait et toutes les
        // suivantes échouaient (constaté en production, six sur huit).
        $this->update_state('update_plugins', 'wp_update_plugins');
        try {
            $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
            $resultat = $upgrader->upgrade($fichier);
        } catch (\Throwable $e) {
            return 'Erreur pendant la mise à jour : ' . $e->getMessage();
        } finally {
            // Dans un `finally`, une exception ici masquerait le vrai motif de l'echec. Le filtre
            // n'a de sens que sur un WordPress complet ; son absence ne doit rien cacher.
            if (function_exists('remove_filter')) {
                remove_filter('request_filesystem_credentials', [$this, 'grant_filesystem'], 10);
            }
        }
        if (is_wp_error($resultat)) {
            return $resultat->get_error_message();
        }
        if ($resultat === false) {
            return $this->refusal_reason('update_plugins', $fichier);
        }
        return null;
    }

    /**
     * Pourquoi WordPress refuse « sans détail » : le plus souvent, l'extension n'est plus annoncée comme obsolète (déjà mise à
     * jour depuis la demande, ou version retirée de wordpress.org) ; sinon on ne sait pas, et on le dit.
     */
    private function refusal_reason($transient, $key) {
        $state = get_site_transient($transient);
        if (is_object($state) && !isset($state->response[$key])) {
            return 'WordPress n\'annonce plus de mise à jour pour cette extension (déjà à jour, ou version retirée de wordpress.org).';
        }
        return 'WordPress a refusé la mise à jour sans détail.';
    }

    /**
     * N'accorde l'accès fichiers que si le disque est réellement accessible en écriture, et
     * seulement depuis une tâche de fond : on ne veut pas ouvrir cette porte à une requête web.
     */
    public function grant_filesystem($credentials = false, $context = '') {
        if ($context !== '' && strpos((string) $context, 'plugin') === false) {
            return $credentials;
        }
        return $this->disk_writable(WP_PLUGIN_DIR) ? true : $credentials;
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
                if ($ok) {
                    $body = is_array($result['body']) ? $result['body'] : [];
                    if (isset($body['site_id'])) {
                        update_option(self::OPT_SITE_ID, (int) $body['site_id'], false);
                    }
                    if (!self::login_allowed()) {
                        delete_option(self::OPT_LOGIN_KEY);   // porte refermée : la clé ne doit plus servir
                    } elseif (!empty($body['login_key']) && is_string($body['login_key'])) {
                        update_option(self::OPT_LOGIN_KEY, self::protect($body['login_key']), false);
                    }
                    $commands = is_array($body['commands'] ?? null) ? $body['commands'] : [];
                    $this->apply_commands($commands);
                    if ($commands && !$this->reporting) {
                        // le résultat des actions part tout de suite, pas à l'envoi horaire suivant (un seul
                        // renvoi : s'il rapporte d'autres actions, leur résultat attendra l'envoi suivant)
                        $this->reporting = true;
                        try {
                            $this->send_heartbeat();
                        } finally {
                            $this->reporting = false;
                        }
                    }
                }
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
        $notice = get_transient('ssm_connector_notice');
        if (is_string($notice) && $notice !== '') {
            delete_transient('ssm_connector_notice');
            echo '<div class="notice notice-info inline"><p>' . esc_html($notice) . '</p></div>';
        }

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
        echo '<li>Envoyé à SSM : versions de WordPress, de PHP et de la base, serveur web, nom de la machine, chemin d\'installation, extensions et thèmes (versions, état, mises à jour), erreurs PHP (chemins relatifs au site). Rien d\'autre : ni utilisateurs, ni contenu, ni e-mails.</li>';
        echo '<li>Exécuté à la demande de SSM : mises à jour (sauvegarde avant, contrôle du site après, retour à la version précédente si le site casse), activation, désactivation, installation depuis wordpress.org, sauvegarde du site vers le stockage de l\'agence.</li>';
        echo '<li>Connexion directe depuis SSM : ' . (self::login_allowed() ? '<strong>ouverte</strong> (voir ci-dessous).' : 'fermée.') . '</li>';
        echo '</ul>';

        $this->render_login_section();

        $this->render_update_section();
        echo '</div>';
    }

    /** Administrateurs proposés à la connexion directe. */
    public function login_candidates() {
        return get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 200]);
    }

    private function render_login_section() {
        $locked = self::login_locked_by_constant();
        $allowed = self::login_allowed();
        echo '<h2>Connexion directe depuis SSM</h2>';
        echo '<p>Un clic dans SSM ouvre l\'administration de ce site, sans mot de passe : lien signé, valable 60 secondes, une seule fois. '
            . 'Fermée par défaut ; SSM ne peut pas l\'ouvrir à distance.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ssm_connector_login" />';
        wp_nonce_field('ssm_connector_login');
        $disabled = $locked ? ' disabled' : '';
        echo '<p><label><input type="checkbox" name="ssm_login_allowed" value="1"' . ($allowed ? ' checked' : '') . $disabled . ' /> '
            . 'Autoriser la connexion directe depuis SSM</label></p>';
        $chosen = (int) get_option(self::OPT_LOGIN_USER, 0);
        echo '<p><label for="ssm_login_user">Administrateur connecté</label><br /><select id="ssm_login_user" name="ssm_login_user"' . $disabled . '>'
            . '<option value="0">Le premier administrateur</option>';
        foreach ($this->login_candidates() as $u) {
            echo '<option value="' . (int) $u->ID . '"' . ((int) $u->ID === $chosen ? ' selected' : '') . '>'
                . esc_html(($u->display_name ?? $u->user_login) . ' (' . $u->user_login . ')') . '</option>';
        }
        echo '</select></p>';
        if ($locked) {
            echo '<p class="description">' . ($allowed ? 'Ouverte' : 'Fermée') . ' et verrouillée par <code>SSM_CONNECTOR_ALLOW_LOGIN</code> dans wp-config.php.</p>';
        } else {
            submit_button('Enregistrer', 'secondary', 'submit', false);
        }
        if ($allowed) {
            echo '<p class="description">' . ($this->login_key() ? 'Clé reçue de SSM : prête.' : 'Clé pas encore reçue (au prochain envoi).') . '</p>';
        }
        echo '</form>';
    }

    /** Enregistre la case et l'administrateur choisi. Renvoie le message à afficher. */
    public function save_login_settings($allowed, $user_id) {
        if (self::login_locked_by_constant()) {
            return 'Réglage imposé par SSM_CONNECTOR_ALLOW_LOGIN dans wp-config.php.';
        }
        $user_id = (int) $user_id;
        if ($user_id > 0 && !in_array($user_id, array_map(function ($u) { return (int) $u->ID; }, $this->login_candidates()), true)) {
            return 'Administrateur inconnu.';
        }
        update_option(self::OPT_LOGIN_ALLOWED, $allowed ? 1 : 0, false);
        update_option(self::OPT_LOGIN_USER, $user_id, false);
        if (!$allowed) {
            delete_option(self::OPT_LOGIN_KEY);   // refermée : les liens déjà signés cessent de servir
            return 'Connexion directe fermée.';
        }
        return 'Connexion directe ouverte : prête après deux envois à SSM (clé remise, puis confirmée).';
    }

    /**
     * Ouverture : deux envois tout de suite au lieu d'attendre deux envois horaires (jusqu'à deux heures). Le premier
     * reçoit la clé de SSM, le second annonce son empreinte : SSM confirme, la connexion directe est prête.
     */
    public function sync_login_now() {
        $later = 'Connexion directe ouverte. SSM n\'a pas répondu : elle sera prête après deux envois horaires '
            . '(ou deux clics sur « Tester la connexion »).';
        if (!$this->send_heartbeat()) {
            return $later;
        }
        if (!$this->login_key()) {
            return 'Connexion directe ouverte, mais SSM n\'a pas remis de clé : SSM 2.13 ou plus récent est nécessaire.';
        }
        if (!$this->send_heartbeat()) {
            return $later;
        }
        return 'Connexion directe ouverte et prête : SSM peut ouvrir une session sur ce site.';
    }

    /** Fermeture : SSM l'apprend tout de suite et ne propose plus le lien. */
    public function close_login_now($message) {
        $this->send_heartbeat();
        return $message;
    }

    public function handle_login_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.', '', ['response' => 403]);
        }
        check_admin_referer('ssm_connector_login');
        $allowed = !empty($_POST['ssm_login_allowed']);
        $message = $this->save_login_settings($allowed, isset($_POST['ssm_login_user']) ? (int) $_POST['ssm_login_user'] : 0);
        if (strpos($message, 'Connexion directe ') === 0) {   // enregistré (ni verrouillé, ni refusé)
            $message = $allowed ? $this->sync_login_now() : $this->close_login_now($message);
        }
        set_transient('ssm_connector_notice', $message, 120);
        wp_safe_redirect(admin_url('options-general.php?page=' . SSM_CONNECTOR_PAGE));
        exit;
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
