<?php

defined('ABSPATH') || exit;

final class Earthborne_Stuller_Client
{
    private string $base_url;
    private string $username;
    private string $password;

    public function __construct(array $settings)
    {
        $this->base_url = rtrim((string) ($settings['base_url'] ?? ''), '/');
        $this->username = (string) ($settings['username'] ?? '');
        $this->password = (string) ($settings['password'] ?? '');
    }

    public function is_configured(): bool
    {
        return $this->base_url !== '' && $this->username !== '' && $this->password !== '';
    }

    public function fetch_availability(array $skus): array
    {
        $path = (string) get_option('earthborne_availability_path', '');
        if ($path === '') {
            throw new RuntimeException('The Stuller availability endpoint path is not configured.');
        }

        $response = $this->request('POST', $path, ['skus' => array_values($skus)]);

        // ACCOUNT-SPECIFIC MAPPING: Use this filter to map Stuller's documented response
        // to rows shaped as: sku, available (bool), quantity (int|null), cost (float|null).
        $rows = apply_filters('earthborne_map_stuller_availability', [], $response, $skus);
        if (!is_array($rows)) {
            throw new UnexpectedValueException('Availability mapping must return an array.');
        }
        return $rows;
    }

    public function submit_order(array $payload): array
    {
        $path = (string) get_option('earthborne_order_path', '');
        if ($path === '') {
            throw new RuntimeException('The Stuller order endpoint path is not configured.');
        }

        // ACCOUNT-SPECIFIC MAPPING: Transform the neutral WooCommerce payload using
        // Stuller's current account documentation before enabling live fulfillment.
        $mapped = apply_filters('earthborne_map_stuller_order_request', [], $payload);
        if (!is_array($mapped) || $mapped === []) {
            throw new RuntimeException('The Stuller order request mapping is not configured.');
        }
        return $this->request('POST', $path, $mapped);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('Stuller API credentials are incomplete.');
        }

        $url = $this->base_url . '/' . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Earthborne-Automation/' . EARTHBORNE_AUTOMATION_VERSION,
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body, JSON_THROW_ON_ERROR);
        }

        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Stuller API returned HTTP %d: %s', $status, mb_substr($raw, 0, 500)));
        }
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Stuller API did not return a JSON object or array.');
        }
        return $decoded;
    }
}
