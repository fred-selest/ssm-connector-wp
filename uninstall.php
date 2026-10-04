<?php
// Exécuté par WordPress quand l'extension est SUPPRIMÉE (pas seulement désactivée) : efface ses réglages.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('ssm_heartbeat_event');

foreach ([
    'ssm_connector_token',
    'ssm_connector_url',
    'ssm_pending_events',
    'ssm_last_heartbeat_at',
    'ssm_last_heartbeat_ok',
    'ssm_last_heartbeat_message',
] as $option) {
    delete_option($option);
}
