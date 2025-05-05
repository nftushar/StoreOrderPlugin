<?php
class Store_Order_Sync {
    private $api_handler;

    public function __construct() {
        $this->api_handler = new Store_API_Handler();
        
        // Sync new orders
        add_action('woocommerce_new_order', [$this, 'sync_new_order'], 10, 2);
        
        // Sync order status changes
        add_action('woocommerce_order_status_changed', [$this, 'sync_order_status_change'], 10, 4);
        
        // Sync order notes
        add_action('woocommerce_order_note_added', [$this, 'sync_order_note'], 10, 2);
        
        // Handle incoming updates from Hub
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);
    }

    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'store_order_sync_log';
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(255) NOT NULL,
            request_data text NOT NULL,
            response_code varchar(10),
            response_body text,
            status varchar(20) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Schedule cleanup
        if (!wp_next_scheduled('store_order_sync_cleanup')) {
            wp_schedule_event(time(), 'daily', 'store_order_sync_cleanup');
        }
        
        // Add custom capabilities
        $roles = ['administrator', 'shop_manager'];
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('manage_store_order_sync');
            }
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('store_order_sync_cleanup');
        
        // Remove custom capabilities
        $roles = ['administrator', 'shop_manager'];
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->remove_cap('manage_store_order_sync');
            }
        }
    }

    public function sync_new_order($order_id, $order) {
        if (!apply_filters('store_order_sync_should_sync', true, $order_id)) {
            return;
        }
        
        $this->api_handler->send_order_to_hub($order);
    }

    public function sync_order_status_change($order_id, $from_status, $to_status, $order) {
        if (!apply_filters('store_order_sync_should_sync_status', true, $order_id, $to_status)) {
            return;
        }
        
        $this->api_handler->send_order_update_to_hub($order_id, [
            'status' => $to_status,
            'updated_at' => current_time('mysql'),
            'status_changed_from' => $from_status
        ]);
    }

    public function sync_order_note($comment_id, $order_id) {
        $comment = get_comment($comment_id);
        $order = wc_get_order($order_id);
        
        if (!apply_filters('store_order_sync_should_sync_note', true, $order_id, $comment_id)) {
            return;
        }
        
        $this->api_handler->send_order_update_to_hub($order_id, [
            'note' => [
                'content' => $comment->comment_content,
                'added_by' => $comment->comment_author,
                'date' => $comment->comment_date,
                'note_id' => $comment_id,
                'is_customer_note' => (bool) get_comment_meta($comment_id, 'is_customer_note', true)
            ]
        ]);
    }

    public function update_local_order($order_id, $data) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', __('Order not found', 'store-order-sync'));
        }
        
        // Update status if provided
        if (isset($data['status'])) {
            $order->set_status($data['status']);
        }
        
        // Add note if provided
        if (isset($data['note'])) {
            $is_customer_note = isset($data['note']['is_customer_note']) && $data['note']['is_customer_note'];
            $order->add_order_note($data['note']['content'], $is_customer_note, true);
        }
        
        $order->save();
        
        do_action('store_order_sync_after_local_update', $order_id, $data);
        
        return true;
    }

    public function register_webhook_endpoint() {
        register_rest_route('store-order-sync/v1', '/update-order/(?P<order_id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook_update'],
            'permission_callback' => [$this, 'verify_webhook_request'],
            'args' => [
                'order_id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'status' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return in_array($param, array_keys(wc_get_order_statuses()));
                    }
                ],
                'note' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return is_array($param) && isset($param['content']);
                    }
                ]
            ]
        ]);
    }

    public function verify_webhook_request($request) {
        $api_key = $request->get_header('X-API-KEY');
        $signature = $request->get_header('X-API-SIGNATURE');
        $timestamp = $request->get_header('X-API-TIMESTAMP');
        $nonce = $request->get_header('X-API-NONCE');
        
        // Basic validation
        if (empty($api_key) || empty($signature) || empty($timestamp) || empty($nonce)) {
            return false;
        }
        
        // Verify timestamp isn't too old (5 minutes)
        if (time() - $timestamp > 300) {
            return false;
        }
        
        // Verify API key matches
        if ($api_key !== get_option('store_order_sync_api_key')) {
            return false;
        }
        
        // Verify signature
        $secret_key = get_option('store_order_sync_secret_key');
        $message = $timestamp . $nonce . $request->get_route() . json_encode($request->get_params());
        $expected_signature = hash_hmac('sha256', $message, $secret_key);
        
        return hash_equals($expected_signature, $signature);
    }

    public function handle_webhook_update($request) {
        $order_id = $request['order_id'];
        $params = $request->get_params();
        
        $result = $this->update_local_order($order_id, $params);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message()
            ], 400);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'message' => __('Order updated successfully', 'store-order-sync')
        ]);
    }
}