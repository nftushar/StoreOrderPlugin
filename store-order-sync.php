<?php
/**
 * Plugin Name: Store Order Sync
 * Description: Receives updates from the Hub site and updates WooCommerce orders accordingly.
 * Version: 1.2
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

add_action('rest_api_init', function () {
    error_log('Store Sync REST: registering routes');
    register_rest_route('store-sync/v1', '/update-order', [
        'methods'             => 'POST',
        'callback'            => 'store_receive_order_update',
        'permission_callback' => 'store_sync_permission_check',
    ]);

    // Test endpoint to verify route
    register_rest_route('store-sync/v1', '/test', [
        'methods'             => 'GET',
        'callback'            => function () {
            return new WP_REST_Response(['message' => 'Test OK'], 200);
        },
        'permission_callback' => '__return_true',
    ]);

    // Debug endpoint to check if order exists
    register_rest_route('store-sync/v1', '/order-exists', [
        'methods'             => 'GET',
        'callback'            => 'store_order_exists',
        'permission_callback' => '__return_true',
    ]);
});

// ✅ API Key Auth Check
function store_sync_permission_check($request) {
    $api_key = $request->get_header('x-api-key');
    $ok      = ($api_key === 'hd8F#9d@2mKz$G7P');
    error_log("Store Sync Auth: api_key={$api_key}, ok=" . ($ok ? 'true' : 'false'));
    return $ok;
}

// Debug function to check order existence
function store_order_exists($request) {
    $id     = intval($request->get_param('order_id'));
    $exists = wc_get_order($id) ? true : false;
    return new WP_REST_Response(['order_id' => $id, 'exists' => $exists], 200);
}

// Main order update callback
function store_receive_order_update($request) {
    $params = $request->get_json_params();
    error_log('Store Sync Received: ' . print_r($params, true));

    $order_id = isset($params['order_id']) ? intval($params['order_id']) : 0;
    $status   = sanitize_text_field($params['status'] ?? '');
    $note     = sanitize_text_field($params['note'] ?? '');

    if (!$order_id) {
        return new WP_REST_Response(['error' => 'Missing order_id'], 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_REST_Response(['error' => 'Order not found'], 404);
    }

    error_log("Store Sync Updating Order ID: {$order_id}");

    if ($status) {
        $order->update_status($status, 'Updated by Hub');
    }

    if ($note) {
        $order->add_order_note($note);
    }

    $order->save();

    return new WP_REST_Response(['message' => 'Order updated successfully.'], 200);
}
