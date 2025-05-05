<?php
class Store_Plugin_Settings {
    public function __construct() {
        add_action('admin_init', [$this, 'init_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('Store Order Sync Settings', 'store-order-sync'),
            __('Order Sync Settings', 'store-order-sync'),
            'manage_options',
            'store-order-sync',
            [$this, 'render_settings_page']
        );
    }

    public function init_settings() {
        register_setting('store_order_sync_settings', 'store_order_sync_hub_url');
        register_setting('store_order_sync_settings', 'store_order_sync_api_key');
        register_setting('store_order_sync_settings', 'store_order_sync_secret_key');
        register_setting('store_order_sync_settings', 'store_order_sync_debug_mode');
        
        add_settings_section(
            'store_order_sync_main',
            __('API Connection Settings', 'store-order-sync'),
            [$this, 'render_section_info'],
            'store-order-sync'
        );
        
        add_settings_field(
            'store_order_sync_hub_url',
            __('Hub Website URL', 'store-order-sync'),
            [$this, 'render_hub_url_field'],
            'store-order-sync',
            'store_order_sync_main'
        );
        
        add_settings_field(
            'store_order_sync_api_key',
            __('API Key', 'store-order-sync'),
            [$this, 'render_api_key_field'],
            'store-order-sync',
            'store_order_sync_main'
        );
        
        add_settings_field(
            'store_order_sync_secret_key',
            __('Secret Key', 'store-order-sync'),
            [$this, 'render_secret_key_field'],
            'store-order-sync',
            'store_order_sync_main'
        );
        
        add_settings_field(
            'store_order_sync_debug_mode',
            __('Debug Mode', 'store-order-sync'),
            [$this, 'render_debug_mode_field'],
            'store-order-sync',
            'store_order_sync_main'
        );
    }

    public function render_section_info() {
        echo '<p>' . __('Configure the connection to your Hub website.', 'store-order-sync') . '</p>';
    }

    public function render_hub_url_field() {
        $value = get_option('store_order_sync_hub_url');
        echo '<input type="url" name="store_order_sync_hub_url" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://your-hub-site.com">';
    }

    public function render_api_key_field() {
        $value = get_option('store_order_sync_api_key');
        echo '<input type="text" name="store_order_sync_api_key" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_secret_key_field() {
        $value = get_option('store_order_sync_secret_key');
        echo '<input type="password" name="store_order_sync_secret_key" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_debug_mode_field() {
        $value = get_option('store_order_sync_debug_mode');
        echo '<label><input type="checkbox" name="store_order_sync_debug_mode" value="1" ' . checked(1, $value, false) . '> ' . __('Enable debug logging', 'store-order-sync') . '</label>';
        echo '<p class="description">' . __('When enabled, detailed logs will be kept of all sync attempts.', 'store-order-sync') . '</p>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check for settings errors
        settings_errors('store_order_sync_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('store_order_sync_settings');
                do_settings_sections('store-order-sync');
                submit_button(__('Save Settings', 'store-order-sync'));
                ?>
            </form>
            
            <h2><?php _e('Sync Logs', 'store-order-sync'); ?></h2>
            <?php $this->render_sync_logs(); ?>
        </div>
        <?php
    }

    private function render_sync_logs() {
        global $wpdb;
        
        $logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}store_order_sync_log 
             ORDER BY created_at DESC 
             LIMIT 50"
        );
        
        if (empty($logs)) {
            echo '<p>' . __('No sync attempts logged yet.', 'store-order-sync') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Date', 'store-order-sync') . '</th>';
        echo '<th>' . __('URL', 'store-order-sync') . '</th>';
        echo '<th>' . __('Status', 'store-order-sync') . '</th>';
        echo '<th>' . __('Response', 'store-order-sync') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at)) . '</td>';
            echo '<td>' . esc_html($log->url) . '</td>';
            echo '<td>' . esc_html($log->status) . ' (' . esc_html($log->response_code) . ')</td>';
            echo '<td>' . esc_html(substr($log->response_body, 0, 100)) . '...</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
}