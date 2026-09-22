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
        add_action('admin_post_earthborne_preview_catalog', [$this, 'preview_catalog']);
        add_action('admin_post_earthborne_populate_catalog', [$this, 'populate_catalog']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_rest_routes(): void
    {
        register_rest_route('earthborne/v1', '/import-ring-series', [
            'methods' => 'POST',
            'permission_callback' => static fn(): bool => current_user_can('manage_woocommerce'),
            'callback' => [$this, 'rest_import_ring_series'],
            'args' => [
                'series' => ['required' => true, 'type' => 'array', 'items' => ['type' => 'string']],
            ],
        ]);
    }

    public function rest_import_ring_series(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $series = array_slice(array_values(array_unique(array_filter(array_map(
            static fn($value): string => sanitize_text_field((string) $value),
            (array) $request->get_param('series')
        )))), 0, 5);
        if ($series === []) return new WP_Error('missing_series', 'Provide one to five Stuller series.', ['status' => 400]);

        $results = [];
        foreach ($series as $number) {
            try {
                $results[] = $this->save_ring_series($number, $this->client()->fetch_series($number));
            } catch (Throwable $error) {
                $results[] = ['series' => $number, 'error' => $error->getMessage()];
            }
        }
        return rest_ensure_response(['processed' => count($results), 'results' => $results]);
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
            'earthborne_metal_markup' => 'floatval',
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
                <?php $this->input('earthborne_metal_markup', 'Metal merchandise markup', 'number', '1.5', 'step="0.01" min="1"'); ?>
                <?php $this->checkbox('earthborne_dry_run', 'Dry-run mode', true); ?>
                <?php $this->checkbox('earthborne_auto_fulfillment', 'Enable automatic fulfillment', false); ?>
            </table><?php submit_button(); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="earthborne_run_sync"><?php wp_nonce_field('earthborne_run_sync'); ?>
            <?php submit_button('Run inventory sync now', 'secondary'); ?>
        </form>
        <hr>
        <h2>Catalog population</h2>
        <p>Paste up to 50 exact Stuller SKUs. Previewing does not change products. Population is blocked until the preview succeeds and you type <code>POPULATE</code>.</p>
        <?php $this->catalog_notice(); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="earthborne_preview_catalog"><?php wp_nonce_field('earthborne_preview_catalog'); ?>
            <p><label for="earthborne_catalog_skus"><strong>Exact SKUs</strong></label></p>
            <textarea class="large-text code" rows="7" id="earthborne_catalog_skus" name="earthborne_catalog_skus" placeholder="One SKU per line or comma-separated"></textarea>
            <?php submit_button('Preview catalog batch', 'secondary'); ?>
        </form>
        <?php $this->catalog_preview(); ?>
        </div>
        <?php
    }

    private function catalog_notice(): void
    {
        $code = sanitize_key((string) ($_GET['catalog_result'] ?? ''));
        $messages = [
            'ready' => ['notice-info', 'Preview ready. Review every row before population.'],
            'done' => ['notice-success', 'Catalog batch completed.'],
            'blocked' => ['notice-error', 'Population blocked: type POPULATE exactly.'],
            'expired' => ['notice-error', 'Preview expired. Preview the SKUs again.'],
            'failed' => ['notice-error', 'Catalog request failed. Check the WooCommerce logs for details.'],
        ];
        if (!isset($messages[$code])) return;
        printf('<div class="notice %1$s inline"><p>%2$s</p></div>', esc_attr($messages[$code][0]), esc_html($messages[$code][1]));
    }

    private function catalog_preview(): void
    {
        $preview = get_transient($this->preview_key());
        if (!is_array($preview) || empty($preview['rows'])) return;
        echo '<h3>Prepared batch</h3><table class="widefat striped"><thead><tr><th>SKU</th><th>Name</th><th>Cost</th><th>Retail</th><th>Stock</th><th>Decision</th></tr></thead><tbody>';
        foreach ($preview['rows'] as $row) {
            printf('<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$d</td><td>%6$s</td></tr>', esc_html($row['sku']), esc_html($row['name']), esc_html($row['cost'] === null ? '—' : wp_strip_all_tags(wc_price($row['cost']))), esc_html($row['retail'] === null ? '—' : wp_strip_all_tags(wc_price($row['retail']))), (int) $row['quantity'], esc_html($row['decision']));
        }
        echo '</tbody></table>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
            <input type="hidden" name="action" value="earthborne_populate_catalog"><?php wp_nonce_field('earthborne_populate_catalog'); ?>
            <p><label for="earthborne_population_confirmation"><strong>Type POPULATE to create/update only the rows marked Ready</strong></label></p>
            <input id="earthborne_population_confirmation" name="earthborne_population_confirmation" type="text" autocomplete="off">
            <?php submit_button('Populate this batch', 'primary'); ?>
        </form>
        <?php
    }

    public function preview_catalog(): void
    {
        $this->authorize_admin_post('earthborne_preview_catalog');
        $raw = sanitize_textarea_field(wp_unslash((string) ($_POST['earthborne_catalog_skus'] ?? '')));
        $skus = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: []))));
        $skus = array_slice($skus, 0, 50);
        if ($skus === []) $this->catalog_redirect('failed');

        try {
            $rows = array_map([$this, 'prepare_catalog_row'], $this->client()->fetch_catalog($skus));
            set_transient($this->preview_key(), ['created' => time(), 'rows' => $rows], 30 * MINUTE_IN_SECONDS);
            $this->catalog_redirect('ready');
        } catch (Throwable $error) {
            $this->log('Catalog preview failed: ' . $error->getMessage(), 'error');
            $this->catalog_redirect('failed');
        }
    }

    public function populate_catalog(): void
    {
        $this->authorize_admin_post('earthborne_populate_catalog');
        if (sanitize_text_field(wp_unslash((string) ($_POST['earthborne_population_confirmation'] ?? ''))) !== 'POPULATE') {
            $this->catalog_redirect('blocked');
        }
        $preview = get_transient($this->preview_key());
        if (!is_array($preview) || empty($preview['rows'])) $this->catalog_redirect('expired');

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($preview['rows'] as $row) {
            if (($row['decision'] ?? '') !== 'Ready') { $counts['skipped']++; continue; }
            try {
                $existing_id = wc_get_product_id_by_sku($row['sku']);
                $product_id = $this->save_catalog_product($row, $existing_id);
                $product = wc_get_product($product_id);
                if (!$product) throw new RuntimeException('Could not reload saved product.');
                $this->attach_images($product, $row['images']);
                $existing_id ? $counts['updated']++ : $counts['created']++;
                $this->log(sprintf('Catalog %s SKU %s as product %d.', $existing_id ? 'updated' : 'created', $row['sku'], $product_id), 'info');
            } catch (Throwable $error) {
                $counts['failed']++;
                $this->log('Catalog SKU ' . ($row['sku'] ?? '?') . ' failed: ' . $error->getMessage(), 'error');
            }
        }
        delete_transient($this->preview_key());
        $this->log('Catalog batch result: ' . wp_json_encode($counts), $counts['failed'] ? 'warning' : 'info');
        $this->catalog_redirect('done');
    }

    private function save_catalog_product(array $row, int $existing_id): int
    {
        if ($this->is_ring_row($row)) {
            return $this->save_ring_product($row, $existing_id);
        }

        $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
        if (!$product) throw new RuntimeException('Could not load product.');
        $this->apply_catalog_fields($product, $row);
        return $product->save();
    }

    private function save_ring_series(string $series, array $rows): array
    {
        $approved = [];
        foreach ($rows as $row) {
            if (empty($row['orderable']) || ($row['cost'] ?? 0) <= 0 || !$this->is_ring_row($row)) continue;
            $text = strtolower(wp_json_encode([$row['name'] ?? '', $row['description'] ?? '', $row['attributes'] ?? []]) ?: '');
            if (str_contains($text, 'pearl') || str_contains($text, 'lab-grown') || str_contains($text, 'lab grown')) continue;
            $finish = strtolower((string) ($row['attributes']['Finished State'] ?? $row['attributes']['Finish State'] ?? ''));
            if ($finish !== '' && !str_contains($finish, 'polish')) continue;
            $metal = trim((string) ($row['attributes']['Quality'] ?? ''));
            $sizes = $this->normalized_ring_size_options($row);
            if ($metal === '' || count($sizes) < 2) continue;
            $approved[] = ['row' => $row, 'metal' => $metal, 'sizes' => $sizes];
        }
        if ($approved === []) throw new RuntimeException('No orderable metal and size combinations were found.');

        $parent_sku = 'EB-RING-' . sanitize_title($series);
        $parent_id = wc_get_product_id_by_sku($parent_sku);
        $parent = $parent_id ? new WC_Product_Variable($parent_id) : new WC_Product_Variable();
        $first = $approved[0]['row'];
        $name = preg_replace('/^(10K|14K|18K|Platinum|Palladium|Sterling Silver)\s+(Rose|White|Yellow|Palladium White)?\s*(Gold)?\s*/i', '', (string) ($first['name'] ?: 'Custom Ring'));
        $parent->set_name(trim((string) $name));
        if (!$parent_id) $parent->set_sku($parent_sku);
        $parent->set_status('publish');
        $parent->set_catalog_visibility('visible');
        $parent->set_manage_stock(false);
        $parent->set_stock_status('instock');
        $parent->set_category_ids($this->category_ids(['Rings', 'One-of-a-Kind & Ready-Made Jewelry']));
        $parent->set_short_description('<p>Custom ring with selectable metal and ring size.</p>');
        $parent->set_description('<p>This is a custom item. Please allow approximately 8 days for Stuller production before shipment.</p>');

        $metals = array_values(array_unique(array_map(static fn(array $item): string => $item['metal'], $approved)));
        $sizes = [];
        foreach ($approved as $item) $sizes = array_merge($sizes, array_keys($item['sizes']));
        $sizes = array_values(array_unique($sizes));
        usort($sizes, static fn(string $a, string $b): int => (float) $a <=> (float) $b);

        $metal_attribute = new WC_Product_Attribute();
        $metal_attribute->set_name('Metal');
        $metal_attribute->set_options($metals);
        $metal_attribute->set_visible(true);
        $metal_attribute->set_variation(true);
        $metal_attribute->set_position(0);
        $size_attribute = new WC_Product_Attribute();
        $size_attribute->set_name('Ring size');
        $size_attribute->set_options($sizes);
        $size_attribute->set_visible(true);
        $size_attribute->set_variation(true);
        $size_attribute->set_position(1);
        $parent->set_attributes([$metal_attribute, $size_attribute]);
        $parent_id = $parent->save();

        $existing = [];
        foreach ($parent->get_children() as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation) continue;
            $key = (string) $variation->get_meta('_earthborne_option_key');
            if ($key !== '') $existing[$key] = $variation;
        }

        $markup = max(1.0, (float) get_option('earthborne_metal_markup', 1.5));
        $active = [];
        foreach ($approved as $item) {
            foreach ($item['sizes'] as $size => $option) {
                $key = sanitize_title($item['metal']) . '|' . $size;
                $service = (float) $option['surcharge'];
                $price = round(((float) $item['row']['cost'] * $markup) + $service, 2);
                $variation = $existing[$key] ?? new WC_Product_Variation();
                $variation->set_parent_id($parent_id);
                $variation->set_status('publish');
                $variation->set_attributes(['metal' => $item['metal'], 'ring-size' => $size]);
                $variation->set_regular_price((string) $price);
                $variation->set_manage_stock(false);
                $variation->set_stock_status('instock');
                $variation->update_meta_data('_earthborne_option_key', $key);
                $variation->update_meta_data('_earthborne_stuller_sku', $item['row']['sku']);
                $variation->update_meta_data('_earthborne_ring_size', $size);
                $variation->update_meta_data('_earthborne_stuller_service_cost', $service);
                $variation->update_meta_data('_earthborne_source_cost', (float) $item['row']['cost']);
                $variation->update_meta_data('_earthborne_ring_size_stocked', !empty($option['stocked']) ? 'yes' : 'no');
                $variation->save();
                $active[$key] = true;
            }
        }
        foreach ($existing as $key => $variation) if (!isset($active[$key])) {
            $variation->set_status('private');
            $variation->save();
        }

        $parent = wc_get_product($parent_id);
        if ($parent) $this->attach_images($parent, $first['images'] ?? []);
        WC_Product_Variable::sync($parent_id);
        wc_delete_product_transients($parent_id);
        return ['series' => $series, 'parent_id' => $parent_id, 'metals' => count($metals), 'sizes' => count($sizes), 'variations' => count($active)];
    }

    private function save_ring_product(array $row, int $existing_id): int
    {
        $size_options = $this->normalized_ring_size_options($row);
        $sizes = array_keys($size_options);
        if ($sizes === []) throw new RuntimeException('Ring has no selectable sizes.');

        if ($existing_id) {
            wp_set_object_terms($existing_id, 'variable', 'product_type');
            $product = new WC_Product_Variable($existing_id);
        } else {
            $product = new WC_Product_Variable();
        }

        $this->apply_catalog_fields($product, $row);
        $product->set_manage_stock(false);

        $attribute = new WC_Product_Attribute();
        $attribute->set_id(0);
        $attribute->set_name('Ring size');
        $attribute->set_options($sizes);
        $attribute->set_position(0);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $product->set_attributes([$attribute]);

        $product_id = $product->save();
        $existing = [];
        foreach ($product->get_children() as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation) continue;
            $size = (string) $variation->get_meta('_earthborne_ring_size');
            if ($size !== '') $existing[$size] = $variation;
        }

        $markup = max(1.0, (float) get_option('earthborne_metal_markup', 1.5));
        foreach ($sizes as $size) {
            $option = $size_options[$size];
            $surcharge = (float) $option['surcharge'];
            $merchandise_cost = (float) $row['cost'];
            $variation_price = round(($merchandise_cost * $markup) + $surcharge, 2);
            $variation = $existing[$size] ?? new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_status('publish');
            $variation->set_attributes(['ring-size' => $size]);
            $variation->set_regular_price((string) $variation_price);
            $variation->set_manage_stock(false);
            $variation->set_stock_status('instock');
            $variation->update_meta_data('_earthborne_managed', 'yes');
            $variation->update_meta_data('_earthborne_stuller_sku', $row['sku']);
            $variation->update_meta_data('_earthborne_ring_size', $size);
            $variation->update_meta_data('_earthborne_ring_size_surcharge', $surcharge);
            $variation->update_meta_data('_earthborne_stuller_service_cost', $surcharge);
            $variation->update_meta_data('_earthborne_ring_size_stocked', !empty($option['stocked']) ? 'yes' : 'no');
            $variation->update_meta_data('_earthborne_source_cost', $merchandise_cost);
            $variation->save();
        }

        foreach ($existing as $size => $variation) {
            if (!in_array($size, $sizes, true)) {
                $variation->set_status('private');
                $variation->save();
            }
        }

        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
        return $product_id;
    }

    private function apply_catalog_fields(WC_Product $product, array $row): void
    {
        $product->set_name($row['name'] !== '' ? $row['name'] : $row['sku']);
        $product->set_sku($row['sku']);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_description(wp_kses_post($row['description']));
        $product->set_short_description(wp_kses_post($row['short_description']));
        $product->set_regular_price((string) $row['retail']);
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $row['quantity']);
        $product->set_stock_status('instock');
        $product->set_category_ids($this->category_ids($row['earthborne_categories']));
        $product->update_meta_data('_earthborne_managed', 'yes');
        $product->update_meta_data('_earthborne_source_cost', $row['cost']);
        $product->update_meta_data('_earthborne_stuller_product_id', $row['product_id']);
        $product->update_meta_data('_earthborne_stuller_attributes', $row['attributes']);
        $product->update_meta_data('_earthborne_stuller_images', $row['images']);
        $product->update_meta_data('_earthborne_ring_sizes', $row['ring_sizes'] ?? []);
        $product->update_meta_data('_earthborne_ring_size_options', $row['ring_size_options'] ?? []);
        $product->update_meta_data('_earthborne_last_sync_utc', gmdate('c'));
    }

    private function is_ring_row(array $row): bool
    {
        $text = strtolower(wp_json_encode([
            $row['product_type'] ?? '',
            $row['name'] ?? '',
            $row['categories'] ?? [],
            $row['attributes'] ?? [],
        ]) ?: '');
        return str_contains($text, 'ring');
    }

    private function normalized_ring_sizes(array $row): array
    {
        return array_keys($this->normalized_ring_size_options($row));
    }

    private function normalized_ring_size_options(array $row): array
    {
        $options = [];
        $source = !empty($row['ring_size_options']) && is_array($row['ring_size_options'])
            ? $row['ring_size_options']
            : array_map(static fn($size): array => ['size' => $size, 'surcharge' => 0, 'stocked' => false], $row['ring_sizes'] ?? []);

        foreach ($source as $option) {
            if (!is_array($option) || !isset($option['size']) || !is_numeric($option['size'])) continue;
            $number = (float) $option['size'];
            if ($number < 1 || $number > 20) continue;
            $label = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
            $options[$label] = [
                'surcharge' => max(0.0, (float) ($option['surcharge'] ?? 0)),
                'stocked' => (bool) ($option['stocked'] ?? false),
            ];
        }
        uksort($options, static fn(string $a, string $b): int => (float) $a <=> (float) $b);
        return $options;
    }

    private function prepare_catalog_row(array $row): array
    {
        $text = strtolower(wp_json_encode([$row['name'] ?? '', $row['description'] ?? '', $row['attributes'] ?? []]) ?: '');
        $decision = 'Ready';
        if (empty($row['available']) || (int) ($row['quantity'] ?? 0) < 1) $decision = 'Skip: out of stock';
        elseif (($row['cost'] ?? null) === null || (float) $row['cost'] <= 0) $decision = 'Skip: missing cost';
        elseif (str_contains($text, 'pearl')) $decision = 'Skip: pearl';
        elseif (str_contains($text, 'lab-grown') || str_contains($text, 'lab grown') || str_contains($text, 'laboratory grown')) $decision = 'Skip: lab-grown stone';
        elseif ($this->is_ring_row($row) && $this->normalized_ring_sizes($row) === []) $decision = 'Skip: ring without selectable sizes';
        $row['retail'] = isset($row['cost']) ? round((float) $row['cost'] * max(1.0, (float) get_option('earthborne_markup', 2.0)), 2) : null;
        $row['decision'] = $decision;
        $row['earthborne_categories'] = array_values(array_unique(array_merge(['Ready Made Jewelry'], $row['earthborne_categories'] ?? [])));
        return $row;
    }

    private function category_ids(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $term = term_exists($name, 'product_cat');
            if (!$term) $term = wp_insert_term($name, 'product_cat');
            if (!is_wp_error($term)) $ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
        }
        return array_values(array_unique($ids));
    }

    private function attach_images(WC_Product $product, array $images): void
    {
        if ($product->get_image_id() || $images === []) return;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ids = [];
        foreach (array_slice($images, 0, 8) as $image) {
            $url = esc_url_raw((string) ($image['url'] ?? ''));
            if ($url === '') continue;
            $id = media_sideload_image($url, $product->get_id(), $product->get_name(), 'id');
            if (!is_wp_error($id)) $ids[] = (int) $id;
        }
        if ($ids !== []) {
            $product->set_image_id(array_shift($ids));
            $product->set_gallery_image_ids($ids);
            $product->save();
        }
    }

    private function authorize_admin_post(string $nonce): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
        check_admin_referer($nonce);
    }

    private function preview_key(): string
    {
        return 'earthborne_catalog_preview_' . get_current_user_id();
    }

    private function catalog_redirect(string $result): never
    {
        wp_safe_redirect(add_query_arg(['page' => 'earthborne-automation', 'catalog_result' => $result], admin_url('admin.php')));
        exit;
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
            if (!$product) continue;
            $sku = $product->get_sku();
            if ($sku === '' && $product->is_type('variation')) {
                $sku = (string) $product->get_meta('_earthborne_stuller_sku');
                if ($sku === '') {
                    $parent = wc_get_product($product->get_parent_id());
                    $sku = $parent ? $parent->get_sku() : '';
                }
            }
            if ($sku === '') continue;
            $payload_item = ['sku' => $sku, 'quantity' => $item->get_quantity()];
            if ($product->is_type('variation')) {
                $ring_size = (string) $product->get_meta('_earthborne_ring_size');
                if ($ring_size !== '' && is_numeric($ring_size)) $payload_item['ring_size'] = (float) $ring_size;
            }
            $payload['items'][] = $payload_item;
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
