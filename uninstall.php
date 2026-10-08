<?php
// Exécuté par WordPress quand l'extension est SUPPRIMÉE (pas seulement désactivée) : efface ses réglages.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('ssm_heartbeat_event');
delete_site_transient('ssm_connector_release');

foreach ([
    'ssm_connector_token',
    'ssm_connector_url',
    'ssm_connector_schema',
    'ssm_last_heartbeat_at',
    'ssm_last_heartbeat_ok',
    'ssm_last_heartbeat_message',
    'ssm_pending_events',   // file d'événements des versions 0.2.x
    'ssm_update_results',
    'ssm_command_results',
    'ssm_php_errors',
    'ssm_debug_log_offset',
    'ssm_login_key',
    'ssm_site_id',
    'ssm_login_log',
] as $option) {
    delete_option($option);
}
