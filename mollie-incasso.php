<?php

/**
 * Plugin Name: Mollie Incasso
 * Description: Toestemming, losse SEPA-incasso’s en vaste Mollie-abonnementen zonder WooCommerce.
 * Version: 0.5.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Text Domain: mollie-incasso
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) {
    exit;
}
define('MOLLIE_INCASSO_VERSION', '0.5.0');
define('MOLLIE_INCASSO_FILE', __FILE__);
spl_autoload_register(static function ($class) {
    $prefix = 'Lukraak\MollieIncasso\\';
    if (str_starts_with($class, $prefix) && !str_starts_with($class, $prefix . 'Vendor\\')) {
        $file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
register_activation_hook(__FILE__, [Lukraak\MollieIncasso\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Lukraak\MollieIncasso\Plugin::class, 'deactivate']);
add_action('init', static function () {
    (new Lukraak\MollieIncasso\Plugin())->boot();
});

// Register the optional gateway before WooCommerce initializes Blocks/payment registries.
add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MOLLIE_INCASSO_FILE, true);
    }
    (new Lukraak\MollieIncasso\WooCommerceBridge(new Lukraak\MollieIncasso\Plugin()))->register();
});
