<?php
class Store_API_Handler {
    private $api_url;
    private $api_key;
    private $secret_key;

    public function __construct() {
        $this->api_url = get_option('store_order_sync_hub_url');
        $this->api_key = get_option('store_order_sync_api_key');
        $this->secret_key = get_option('store_order_sync_secret_key');
        
        add_filter('cron_schedules', [$this, 'add_custom_cron_schedule']);
        add_action('store_order_sync_cleanup', [$this, 'cleanup_failed_syncs']);
    }

    public function add_custom_cron_schedule($schedules) {
        $schedules['every_15_minutes'] = [
            'interval' => 15 * 60,
            'display'  => __('Every 15 Minutes', 'store-order-sync')
        ];
        return $schedules;
    }

    public function cleanup_failed_syncs() {
        global $wpdb;
        $older_than = date('Y-m-d H:i:s', strtotime('-1 week'));
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}store_order_sync_log 
                 WHERE created_at < %s AND status = 'failed'",
                $older_than
            )
        );
    }

    public function send_order_to_hub($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return false;
        }
        
        $order_data = $this->prepare_order_data($order);
        $endpoint = trailingslashit($this->api_url) . 'wp-json/store-order-sync/v1/orders';
        
        return $this->make_api_request($endpoint, $order_data);
    }

    public function send_order_update_to_hub($order_id, $update_data) {
        $endpoint = trailingslashit($this->api_url) . 'wp-json/store-order-sync/v1/orders/' . $order_id;
        return $this->make_api_request($endpoint, $update_data, 'PUT');
    }

    private function prepare_order_data($order) {
        $order_data = $order->get_data();
        
        // Add additional useful data
        $order_data['customer'] = [
            'name' => $order->get_formatted_billing_full_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'address' => $order->get_address('billing')
        ];
        
        $order_data['line_items'] = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $order_data['line_items'][] = [
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $item->get_total(),
                'sku' => $product ? $product->get_sku() : '',
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id()
            ];
        }
        
        $order_data['shipping'] = $order->get_shipping_method();
        $order_data['payment_method'] = $order->get_payment_method_title();
        $order_data['notes'] = $this->get_order_notes($order);
        
        return $order_data;
    }

    private function get_order_notes($order) {
        $notes = [];
        $args = [
            'post_id' => $order->get_id(),
            'approve' => 'approve',
            'type' => 'order_note'
        ];
        
        $comments = get_comments($args);
        
        foreach ($comments as $comment) {
            $notes[] = [
                'content' => $comment->comment_content,
                'added_by' => $comment->comment_author,
                'date' => $comment->comment_date
            ];
        }
        
        return $notes;
    }

    private function make_api_request($url, $data, $method = 'POST') {
        $timestamp = time();
        $nonce = wp_generate_password(32, false);
        $body = json_encode($data);
        
        // Generate signature
        $message = $timestamp . $nonce . $url . $body;
        $signature = hash_hmac('sha256', $message, $this->secret_key);
        
        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-KEY' => $this->api_key,
                'X-API-SIGNATURE' => $signature,
                'X-API-TIMESTAMP' => $timestamp,
                'X-API-NONCE' => $nonce
            ],
            'body' => $body,
            'timeout' => 30,
            'redirection' => 5,
            'blocking' => false, // Async requests
            'data_format' => 'body'
        ];
        
        $response = wp_remote_request($url, $args);
        
        // Log the request
        $this->log_sync_attempt($url, $data, $response);
        
        return $response;
    }

    private function log_sync_attempt($url, $data, $response) {
        global $wpdb;
        
        $log_data = [
            'url' => $url,
            'request_data' => maybe_serialize($data),
            'response_code' => wp_remote_retrieve_response_code($response),
            'response_body' => maybe_serialize(wp_remote_retrieve_body($response)),
            'status' => is_wp_error($response) ? 'failed' : 'sent',
            'created_at' => current_time('mysql')
        ];
        
        $wpdb->insert("{$wpdb->prefix}store_order_sync_log", $log_data);
    }
}