<?php
/**
 * Файл виконується при видаленні плагіна
 */

// Якщо файл викликано не через WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Видалення таблиць
$tables = array(
    $wpdb->prefix . 'spr_product_views',
    $wpdb->prefix . 'spr_product_relevance'
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Видалення опцій
delete_option('spr_settings');

// Видалення мета-даних продуктів
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_spr_sales_count'");

// Видалення transients та кешу
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_spr_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_spr_%'");

// Видалення cron задач
wp_clear_scheduled_hook('spr_cleanup_views');
wp_clear_scheduled_hook('spr_update_relevance');

// Очищення кешу
wp_cache_flush();
