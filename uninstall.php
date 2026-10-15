<?php

// Uninstall is deliberately conservative: it never cancels external contracts.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
$settings = get_option('mi_settings', []);
if (empty($settings['cleanup'])) {
    return;
}
require_once __DIR__ . '/mollie-incasso.php';
$plugin = new Lukraak\MollieIncasso\Plugin();
try {
    // A mode we cannot verify is retained, even when the cleanup checkbox is set.
    foreach ($plugin->store->select('SELECT * FROM ' . $plugin->store->table('customers')) as $customer) {
        $last = $plugin->store->select('SELECT MAX(created_at) AS latest FROM ' . $plugin->store->table('payments') . ' WHERE customer_id=%d AND mode=%s', [(int) $customer['id'], $customer['mode']]);
        $age = time() - strtotime(max($last[0]['latest'] ?? '', $customer['updated_at']) . ' UTC');
        if ($age < (int) $plugin->config->settings()['retention_days'] * DAY_IN_SECONDS) {
            throw new RuntimeException('Retention period still active.');
        }
        $plugin->service->assertErasure($customer);
    }
} catch (Throwable $e) {
    update_option('mi_cleanup_blocked', true, false);
    return;
}
foreach (['customers', 'consents', 'schemes', 'mandates', 'commands', 'payments', 'subscriptions', 'tokens', 'jobs', 'notifications', 'limits', 'checkouts'] as $table) {
    $plugin->store->query('DROP TABLE IF EXISTS ' . $plugin->store->table($table));
}
foreach (['mi_settings', 'mi_schema', 'mi_instance', 'mi_site_binding', 'mi_secret_test', 'mi_secret_live', 'mi_last_tick', 'mi_cron_error', 'mi_cleanup_blocked', 'mi_last_webhook_test', 'mi_last_webhook_live', 'mi_methods_test', 'mi_methods_live', 'mi_live_activation'] as $key) {
    delete_option($key);
}
get_role('administrator')?->remove_cap('manage_mollie_incasso');
global $wpdb;
$wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->esc_like('mi_setup_page_') . '%', $wpdb->esc_like('mi_wizard_plan_'). '%', $wpdb->esc_like('mi_woo_product_'). '%'));
