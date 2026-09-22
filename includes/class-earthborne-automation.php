<?php

defined('ABSPATH') || exit;

final class Earthborne_Automation
{
    private const CRON_HOOK = 'earthborne_inventory_sync';
    private static ?self $instance = null;
    private WC_Logger $logger;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private function __construct()
    {
        $this->logger = wc_get_logger();
        add_action(self::CRON_HOOK, [$this, 'sync_inventory']);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_submit_order']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_earthborne_run_sync', [$this, 'manual_sync']);
    }

    public function admin_menu(): void
    {
        add_submenu_page('woocommerce', 'Earthborne Automation', 'Earthborne Automation', 'manage_woocommerce', 'earthborne-automation', [$this, 'settings_page']);
    }

    public function register_settings(): void
    {
        $fields = [
            'earthborne_stuller_base_url' => 'esc_url_raw',
            'earthborne_stuller_username' => 'sanitize_text_field',
            'earthborne_stuller_password' => 'sanitize_text_field',
            'earthborne_availability_path' => 'sanitize_text_field',
            'earthborne_order_path' => 'sanitize_text_field',
            'earthborne_stuller_store_number' => 'sanitize_text_field',
            'earthborne_stuller_order_type' => 'sanitize_text_field',
            'earthborne_stuller_oos_type' => 'sanitize_text_field',
            'earthborne_markup' => 'floatval',
            'earthborne_dry_run' => 'rest_sanitize_boolean',
            'earthborne_auto_fulfillment' => 'rest_sanitize_boolean',
        ];
        foreach ($fields as $name => $sanitize) {
            register_setting('earthborne_automation', $name, ['sanitize_callback' => $sanitize]);
        }
    }

    public function settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        ?>
        <div class="wrap"><h1>Earthborne Automation</h1>
        <p>Fulfillment remains blocked until dry-run is off, automatic fulfillment is on, and the account-specific API mapping is installed.</p>
        <form method="post" action="options.php">
            <?php settings_fields('earthborne_automation'); ?>
            <table class="form-table" role="presentation">
                <?php $this->input('earthborne_stuller_base_url', 'Stuller API base URL', 'url'); ?>
                <?php $this->input('earthborne_stuller_username', 'API username'); ?>
                <?php $this->input('earthborne_stuller_password', 'API password', 'password'); ?>
                <?php $this->input('earthborne_availability_path', 'Product endpoint path', 'text', '/v2/products'); ?>
                <?php $this->input('earthborne_order_path', 'Order endpoint path', 'text', '/v2/orders/submitorder'); ?>
                <?php $this->input('earthborne_stuller_store_number', 'Stuller store number'); ?>
                <?php $this->input('earthborne_stuller_order_type', 'Stuller order type', 'text', 'PACKANDSHIP'); ?>
                <?php $this->input('earthborne_stuller_oos_type', 'Out-of-stock instruction', 'text', 'Backorder'); ?>
                <?php $this->input('earthborne_markup', 'Retail markup', 'number', '2.0', 'step="0.01" min="1"'); ?>
                <?php $this->checkbox('earthborne_dry_run', 'Dry-run mode', true); ?>
                <?php $this->checkbox('earthborne_auto_fulfillment', 'Enable automatic fulfillment', false); ?>
            </table><?php submit_button(); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="earthborne_run_sync"><?php wp_nonce_field('earthborne_run_sync'); ?>
            <?php submit_button('Run inventory sync now', 'secondary'); ?>
        </form></div>
        <?php
    }

    private function input(string $name, string $label, string $type = 'text', string $default = '', string $extra = ''): void
    {
        printf('<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" name="%1$s" type="%3$s" value="%4$s" %5$s></td></tr>', esc_attr($name), esc_html($label), esc_attr($type), esc_attr((string) get_option($name, $default)), $extra);
    }

    private function checkbox(string $name, string $label, bool $default): void
    {
        printf('<tr><th scope="row">%1$s</th><td><input name="%2$s" type="hidden" value="0"><label><input name="%2$s" type="checkbox" value="1" %3$s> Enabled</label></td></tr>', esc_html($label), esc_attr($name), checked((bool) get_option($name, $default), true, false));
    }

    public function manual_sync(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
        check_admin_referer('earthborne_run_sync');
        $this->sync_inventory();
        wp_safe_redirect(add_query_arg(['page' => 'earthborne-automation', 'synced' => '1'], admin_url('admin.php')));
        exit;
    }

    public function sync_inventory(): void
    {
        $products = wc_get_products(['limit' => -1, 'status' => ['publish', 'private'], 'return' => 'objects']);
        $by_sku = [];
        foreach ($products as $product) {
            $candidates = $product->is_type('variable') ? $product->get_children() : [$product->get_id()];
            foreach ($candidates as $id) {
                $item = wc_get_product($id);
                if ($item && $item->get_sku() !== '') $by_sku[$item->get_sku()] = $item;
            }
        }
        if ($by_sku === []) return;

        try {
            $client = $this->client();
            foreach (array_chunk(array_keys($by_sku), 50) as $skus) {
                foreach ($client->fetch_availability($skus) as $row) {
                    $sku = (string) ($row['sku'] ?? '');
                    if ($sku === '' || !isset($by_sku[$sku])) continue;
                    $product = $by_sku[$sku];
                    $available = (bool) ($row['available'] ?? false);
                    $quantity = isset($row['quantity']) ? max(0, (int) $row['quantity']) : null;
                    $product->set_manage_stock($quantity !== null);
                    if ($quantity !== null) $product->set_stock_quantity($quantity);
                    $product->set_stock_status($available ? 'instock' : 'outofstock');
                    if (isset($row['cost']) && is_numeric($row['cost'])) {
                        $price = round((float) $row['cost'] * max(1.0, (float) get_option('earthborne_markup', 2.0)), 2);
                        $product->set_regular_price((string) $price);
                    }
                    $product->update_meta_data('_earthborne_last_sync_utc', gmdate('c'));
                    $product->update_meta_data('_earthborne_source_status', $available ? 'available' : 'unavailable');
                    $product->update_meta_data('_earthborne_source_cost', $row['cost'] ?? null);
                    $product->update_meta_data('_earthborne_stuller_product_id', $row['product_id'] ?? null);
                    $product->update_meta_data('_earthborne_stuller_categories', $row['categories'] ?? []);
                    $product->update_meta_data('_earthborne_stuller_attributes', $row['attributes'] ?? []);
                    $product->update_meta_data('_earthborne_stuller_images', $row['images'] ?? []);
                    $product->update_meta_data('_earthborne_stuller_configuration', $row['configuration'] ?? []);
                    $product->save();
                }
            }
            $this->log('Inventory sync completed.', 'info');
        } catch (Throwable $error) {
            $this->log('Inventory sync failed: ' . $error->getMessage(), 'error');
        }
    }

    public function maybe_submit_order(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_earthborne_stuller_submission_id') !== '') return;
        if (!(bool) get_option('earthborne_auto_fulfillment', false)) return;

        $shipping = $order->get_address('shipping');
        $billing = $order->get_address('billing');
        $shipping['name'] = trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));
        $billing['name'] = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        $payload = [
            'merchant_order_id' => (string) $order_id,
            'purchase_order_number' => (string) $order->get_order_number(),
            'created_at' => $order->get_date_created() ? $order->get_date_created()->date(DATE_ATOM) : gmdate('c'),
            'email' => (string) $order->get_billing_email(),
            'phone' => (string) $order->get_billing_phone(),
            'shipping' => $shipping,
            'billing' => $billing,
            'items' => [],
        ];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || $product->get_sku() === '') continue;
            $payload['items'][] = ['sku' => $product->get_sku(), 'quantity' => $item->get_quantity()];
        }
        if ($payload['items'] === []) return;

        if ((bool) get_option('earthborne_dry_run', true)) {
            $order->add_order_note('Earthborne dry run: Stuller fulfillment payload validated but not sent.');
            return;
        }

        try {
            $result = $this->client()->submit_order($payload);
            $submission_id = $this->client()->extract_order_confirmation($result);
            $submission_id = (string) apply_filters('earthborne_extract_stuller_order_id', $submission_id, $result);
            if ($submission_id === '') throw new RuntimeException('Stuller response mapping did not provide an order ID.');
            $order->update_meta_data('_earthborne_stuller_submission_id', $submission_id);
            $order->save();
            $order->add_order_note('Submitted to Stuller. Confirmation: ' . sanitize_text_field($submission_id));
        } catch (Throwable $error) {
            $order->add_order_note('Stuller submission failed and needs review: ' . $error->getMessage());
            $this->log('Order ' . $order_id . ' submission failed: ' . $error->getMessage(), 'error');
        }
    }

    private function client(): Earthborne_Stuller_Client
    {
        return new Earthborne_Stuller_Client([
            'base_url' => get_option('earthborne_stuller_base_url', ''),
            'username' => get_option('earthborne_stuller_username', ''),
            'password' => get_option('earthborne_stuller_password', ''),
        ]);
    }

    private function log(string $message, string $level): void
    {
        $this->logger->log($level, $message, ['source' => 'earthborne-automation']);
    }
}
