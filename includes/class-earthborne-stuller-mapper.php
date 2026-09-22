<?php

defined('ABSPATH') || exit;

/**
 * Converts Stuller v2 Product, Virtual Product, Gem, and Order responses into
 * stable Earthborne records. Stuller field names remain isolated in this class.
 */
final class Earthborne_Stuller_Mapper
{
    public function map_catalog_response(array $response, string $source = 'products'): array
    {
        $items = $this->items($response, $source);
        $mapped = [];

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $row = $source === 'gems' ? $this->map_gem($item) : $this->map_product($item);
            if ($row['sku'] !== '') $mapped[] = $row;
        }

        return $mapped;
    }

    public function map_availability_response(array $response, array $requested_skus = []): array
    {
        $rows = $this->map_catalog_response($response, 'products');
        if ($requested_skus === []) return $rows;

        $wanted = array_fill_keys(array_map('strval', $requested_skus), true);
        return array_values(array_filter($rows, static fn(array $row): bool => isset($wanted[$row['sku']])));
    }

    public function map_order_request(array $payload, array $settings = []): array
    {
        $lines = [];
        foreach (($payload['items'] ?? []) as $index => $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku === '') continue;
            $configured = ['Number' => $sku];
            foreach (['ring_size' => 'RingSize', 'chain_length' => 'ChainLength'] as $from => $to) {
                if (isset($item[$from]) && is_numeric($item[$from])) $configured[$to] = (float) $item[$from];
            }
            if (!empty($item['stones']) && is_array($item['stones'])) $configured['Stones'] = $item['stones'];
            if (!empty($item['engravings']) && is_array($item['engravings'])) $configured['Engravings'] = $item['engravings'];
            $lines[] = [
                'Items' => [$configured],
                'Quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'Item' => $sku,
                'LineNumber' => (string) ($index + 1),
                'CustomerLineReference' => (string) ($item['line_reference'] ?? ''),
            ];
        }

        $shipping = $payload['shipping'] ?? [];
        $billing = $payload['billing'] ?? [];
        return [
            'CustomerData' => [
                'OrderNumber' => (string) ($payload['merchant_order_id'] ?? ''),
                'OrderDate' => (string) ($payload['created_at'] ?? gmdate('c')),
                'EmailConfirmation' => [
                    'SendOrderConfirmation' => false,
                    'SendShipmentConfirmation' => false,
                    'ToAddress' => (string) ($payload['email'] ?? ''),
                ],
            ],
            'Contact' => [
                'Name' => (string) ($shipping['name'] ?? ''),
                'Phone' => (string) ($payload['phone'] ?? ''),
                'EmailAddress' => (string) ($payload['email'] ?? ''),
            ],
            'ShipToAddress' => [
                'Address' => $this->map_address($shipping),
                'ShipComplete' => true,
                'RemovePricing' => true,
            ],
            'BillToAddress' => [
                'Address' => $this->map_address($billing),
                'SameAsShipTo' => $billing === [] || $billing === $shipping,
            ],
            'Lines' => $lines,
            'Type' => (string) ($settings['order_type'] ?? 'PACKANDSHIP'),
            'Version' => 2.0,
            'OrderID' => (string) ($payload['merchant_order_id'] ?? ''),
            'PurchaseOrderNumber' => (string) ($payload['purchase_order_number'] ?? $payload['merchant_order_id'] ?? ''),
            'IfOosType' => (string) ($settings['if_oos_type'] ?? 'Backorder'),
            'TestMode' => (bool) ($settings['test_mode'] ?? true),
            'StoreNumber' => (string) ($settings['store_number'] ?? ''),
        ];
    }

    public function extract_order_confirmation(array $response): string
    {
        if (empty($response['Created'])) return '';
        return trim((string) ($response['ConfirmationNumber'] ?? ''));
    }

    private function map_product(array $item): array
    {
        $quantity = max(0, (int) floor((float) ($item['OnHand'] ?? 0)));
        $orderable = (bool) ($item['Orderable'] ?? false);
        $on_price_list = !array_key_exists('IsOnPriceList', $item) || (bool) $item['IsOnPriceList'];
        $cost = $this->money($item['CustomerSpecificPrice'] ?? null);
        if ($cost === null) $cost = $this->money($item['Price'] ?? null);

        $attributes = $this->name_value_pairs($item['Specifications'] ?? []);
        $attributes += $this->descriptive_elements($item['DescriptiveElementGroup'] ?? []);

        return [
            'source' => 'product',
            'product_id' => isset($item['Id']) ? (int) $item['Id'] : null,
            'sku' => trim((string) ($item['SKU'] ?? '')),
            'name' => trim((string) ($item['Description'] ?? $item['ShortDescription'] ?? '')),
            'short_description' => trim((string) ($item['ShortDescription'] ?? '')),
            'description' => trim((string) ($item['LongGroupDescription'] ?? $item['GroupDescription'] ?? '')),
            'available' => $orderable && $on_price_list && $quantity > 0,
            'orderable' => $orderable,
            'quantity' => $quantity,
            'cost' => $cost,
            'currency' => $this->currency($item['CustomerSpecificPrice'] ?? $item['Price'] ?? null),
            'status' => (string) ($item['Status'] ?? ''),
            'categories' => $this->categories($item),
            'earthborne_categories' => $this->earthborne_categories($item),
            'attributes' => $attributes,
            'images' => $this->images($item),
            'videos' => $this->videos($item),
            'ring_sizable' => (bool) ($item['RingSizable'] ?? false),
            'ring_size' => isset($item['RingSize']) ? (float) $item['RingSize'] : null,
            'ring_sizes' => $this->ring_sizes($item),
            'ring_size_options' => $this->ring_size_options($item),
            'configuration_model_id' => isset($item['ConfigurationModelId']) ? (int) $item['ConfigurationModelId'] : null,
            'configuration' => is_array($item['ConfigurationModel'] ?? null) ? $item['ConfigurationModel'] : [],
            'ready_to_wear' => (bool) ($item['ReadyToWear'] ?? false),
            'product_type' => (string) ($item['ProductType'] ?? ''),
            'raw' => $item,
        ];
    }

    private function map_gem(array $item): array
    {
        $sku = (string) ($item['SKU'] ?? $item['Sku'] ?? $item['ItemNumber'] ?? $item['StockNumber'] ?? $item['SerialNumber'] ?? '');
        $price = $item['CustomerSpecificPrice'] ?? $item['Price'] ?? $item['SellPrice'] ?? null;
        $quantity = isset($item['OnHand']) ? max(0, (int) $item['OnHand']) : 1;
        $active = !array_key_exists('Orderable', $item) || (bool) $item['Orderable'];
        $stone_type = (string) ($item['StoneType'] ?? $item['GemType'] ?? $item['Variety'] ?? $item['Description'] ?? 'Gemstone');
        $lab = stripos($stone_type . ' ' . json_encode($item), 'lab-grown') !== false || stripos($stone_type, 'lab grown') !== false;

        return [
            'source' => 'gem',
            'product_id' => isset($item['Id']) ? (int) $item['Id'] : null,
            'sku' => trim($sku),
            'name' => trim((string) ($item['Description'] ?? $item['Name'] ?? $stone_type)),
            'short_description' => trim($stone_type),
            'description' => trim((string) ($item['Comments'] ?? $item['Description'] ?? '')),
            'available' => $active && $quantity > 0,
            'orderable' => $active,
            'quantity' => $quantity,
            'cost' => $this->money($price),
            'currency' => $this->currency($price),
            'status' => $active ? 'available' : 'unavailable',
            'categories' => ['Loose Gemstones'],
            'earthborne_categories' => $lab ? [] : ['Loose Gemstones', 'One-of-a-Kind Stones'],
            'attributes' => $this->gem_attributes($item),
            'images' => $this->images($item),
            'videos' => $this->videos($item),
            'ring_sizable' => false,
            'ring_size' => null,
            'ring_sizes' => [],
            'configuration_model_id' => null,
            'configuration' => [],
            'ready_to_wear' => true,
            'product_type' => 'Loose Gemstone',
            'natural' => !$lab,
            'raw' => $item,
        ];
    }

    private function items(array $response, string $source): array
    {
        foreach ($source === 'gems' ? ['Gems', 'Gemstones', 'Diamonds', 'Stones', 'Products', 'Results', 'Items'] : ['Products', 'Results', 'Items'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) return $response[$key];
        }
        return array_is_list($response) ? $response : [];
    }

    private function images(array $item): array
    {
        $result = [];
        foreach (['Images', 'GroupImages', 'FullySetImages'] as $bucket) {
            foreach (($item[$bucket] ?? []) as $image) {
                if (!is_array($image)) continue;
                $url = trim((string) ($image['ZoomUrl'] ?? $image['FullUrl'] ?? $image['Url'] ?? ''));
                if ($url !== '') $result[$url] = ['url' => $url, 'sort_order' => (int) ($image['SortOrder'] ?? 0), 'angle' => (string) ($image['Angle'] ?? '')];
            }
        }
        usort($result, static fn(array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
        return array_values($result);
    }

    private function videos(array $item): array
    {
        $urls = [];
        foreach (['Videos', 'GroupVideos'] as $bucket) foreach (($item[$bucket] ?? []) as $video) {
            $url = is_array($video) ? (string) ($video['DownloadUrl'] ?? $video['Url'] ?? '') : '';
            if ($url !== '') $urls[$url] = $url;
        }
        return array_values($urls);
    }

    private function categories(array $item): array
    {
        $result = [];
        foreach (($item['WebCategories'] ?? []) as $category) {
            if (!is_array($category)) continue;
            $value = trim((string) ($category['Path'] ?? $category['Name'] ?? ''));
            if ($value !== '') $result[$value] = $value;
        }
        foreach (['MerchandisingArea', 'MerchandisingCategory1', 'MerchandisingCategory2', 'MerchandisingCategory3', 'MerchandisingCategory4', 'MerchandisingCategory5'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') $result[$value] = $value;
        }
        return array_values($result);
    }

    private function earthborne_categories(array $item): array
    {
        $haystack = strtolower(implode(' ', array_merge($this->categories($item), [
            (string) ($item['Description'] ?? ''), (string) ($item['ProductType'] ?? ''), (string) ($item['Collection'] ?? ''),
        ])));
        $rules = [
            'Wedding' => ['engagement', 'bridal', 'wedding', 'band', 'semi-mount'],
            'Birthstone Jewelry' => ['birthstone'],
            'Zodiac' => ['zodiac'],
            'Charms' => ['charm'],
            'Jewelry Trends' => ['trend', 'fashion'],
            'Loose Gemstones' => ['loose stone', 'loose gemstone'],
            'Turquoise' => ['turquoise'],
        ];
        $result = [];
        foreach ($rules as $category => $needles) foreach ($needles as $needle) if (str_contains($haystack, $needle)) {
            $result[$category] = $category;
            break;
        }
        return array_values($result);
    }

    private function descriptive_elements(array $group): array
    {
        return $this->name_value_pairs($group['DescriptiveElements'] ?? []);
    }

    private function name_value_pairs(array $pairs): array
    {
        $result = [];
        foreach ($pairs as $pair) if (is_array($pair)) {
            $name = trim((string) ($pair['Name'] ?? ''));
            $value = trim((string) ($pair['DisplayValue'] ?? $pair['Value'] ?? ''));
            if ($name !== '' && $value !== '') $result[$name] = $value;
        }
        return $result;
    }

    private function gem_attributes(array $item): array
    {
        $map = ['StoneType','GemType','Variety','Shape','CaratWeight','Weight','Color','Clarity','Cut','Length','Width','Depth','Lab','CertificateNumber','SerialNumber','CountryOfOrigin','Treatment'];
        $result = [];
        foreach ($map as $key) if (isset($item[$key]) && $item[$key] !== '') $result[$key] = $item[$key];
        return $result;
    }

    private function ring_sizes(array $item): array
    {
        return array_values(array_map(
            static fn(array $option): float => (float) $option['size'],
            $this->ring_size_options($item)
        ));
    }

    private function ring_size_options(array $item): array
    {
        $result = [];
        foreach (($item['ConfigurationModel']['RingSizeOptions'] ?? []) as $option) {
            if (!is_array($option) || !isset($option['Size']) || !is_numeric($option['Size'])) continue;
            $size = (float) $option['Size'];
            $key = rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.');
            $result[$key] = [
                'size' => $size,
                'surcharge' => max(0.0, $this->money($option['Price'] ?? null) ?? 0.0),
                'stocked' => (bool) ($option['IsStockedSize'] ?? false),
            ];
        }
        uksort($result, static fn(string $a, string $b): int => (float) $a <=> (float) $b);
        return array_values($result);
    }

    private function money(mixed $value): ?float
    {
        if (is_array($value)) $value = $value['Value'] ?? null;
        return is_numeric($value) ? (float) $value : null;
    }

    private function currency(mixed $value): string
    {
        return is_array($value) ? (string) ($value['CurrencyCode'] ?? 'USD') : 'USD';
    }

    private function map_address(array $address): array
    {
        return [
            'Name' => (string) ($address['name'] ?? ''),
            'AddressLine1' => (string) ($address['address_1'] ?? ''),
            'AddressLine2' => (string) ($address['address_2'] ?? ''),
            'City' => (string) ($address['city'] ?? ''),
            'State' => (string) ($address['state'] ?? ''),
            'PostalCode' => (string) ($address['postcode'] ?? ''),
            'Province' => (string) ($address['state'] ?? ''),
            'Country' => (string) ($address['country'] ?? 'US'),
            'Phone' => (string) ($address['phone'] ?? ''),
        ];
    }
}
