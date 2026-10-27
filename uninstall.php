<?php

// Runs only when the plugin is deleted from 插件 → 已安装的插件, never on
// deactivation. Knowledge already in MomoBird stays there; delete the source
// in the MomoBird console to remove it.
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

foreach (array('momobird_ai_settings', 'momobird_ai_widget', 'momobird_ai_podcast') as $option) {
    delete_option($option);
}
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}momobird_sync");
