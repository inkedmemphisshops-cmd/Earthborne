<?php
/**
 * Plugin Name: Earthborne Commerce Automation
 * Description: Safe Stuller availability, pricing, and fulfillment automation for WooCommerce.
 * Version: 0.3.0
 * Author: Earthborne Jewelry
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

defined('ABSPATH') || exit;

define('EARTHBORNE_AUTOMATION_VERSION', '0.3.0');
define('EARTHBORNE_AUTOMATION_FILE', __FILE__);
define('EARTHBORNE_AUTOMATION_DIR', plugin_dir_path(__FILE__));

require_once EARTHBORNE_AUTOMATION_DIR . 'includes/class-earthborne-stuller-mapper.php';
require_once EARTHBORNE_AUTOMATION_DIR . 'includes/class-earthborne-stuller-client.php';
require_once EARTHBORNE_AUTOMATION_DIR . 'includes/class-earthborne-automation.php';

register_activation_hook(__FILE__, ['Earthborne_Automation', 'activate']);
register_deactivation_hook(__FILE__, ['Earthborne_Automation', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>Earthborne Commerce Automation requires WooCommerce.</p></div>';
        });
        return;
    }

    Earthborne_Automation::instance();
});
