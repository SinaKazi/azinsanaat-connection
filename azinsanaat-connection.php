<?php
/**
 * Plugin Name: Azinsanaat Connection
 * Description: اتصال به آذین صنعت و همگام‌سازی محصولات از طریق API ووکامرس.
 * Version:     1.8.9
 * Author:      Sina Kazemi
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azinsanaat_Connection')) {
    class Azinsanaat_Connection
    {
        const OPTION_KEY = 'azinsanaat_connection_options';
        const NONCE_ACTION_TEST = 'azinsanaat_connection_test_connection';
        const NONCE_ACTION_IMPORT = 'azinsanaat_connection_import_product';
        const NONCE_ACTION_META = 'azinsanaat_connection_product_meta';
        const NONCE_ACTION_MANUAL_SYNC = 'azinsanaat_connection_manual_sync';
        const CRON_HOOK = 'azinsanaat_connection_sync_products';
        const META_REMOTE_ID = '_azinsanaat_remote_id';
        const META_LAST_SYNC = '_azinsanaat_last_synced';
        const META_REMOTE_CONNECTION = '_azinsanaat_remote_connection';
        const NOTICE_CONNECTED_PRODUCTS = 'azinsanaat_connection_connected_notice';
        const CAPABILITY = 'manage_azinsanaat_connection';

        /**
         * Bootstraps plugin hooks.
         */
        public static function init(): void
        {
            add_filter('cron_schedules', [__CLASS__, 'register_cron_schedules']);
            add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled_sync'], 10, 1);
            add_action('init', [__CLASS__, 'ensure_cron_schedule']);
            add_action('init', [__CLASS__, 'ensure_plugin_capability']);
            add_action('update_option_' . self::OPTION_KEY, [__CLASS__, 'handle_options_updated'], 10, 3);

            if (!is_admin()) {
                return;
            }

            add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
            add_action('admin_init', [__CLASS__, 'register_settings']);
            add_action('admin_post_azinsanaat_test_connection', [__CLASS__, 'handle_test_connection']);
            add_action('admin_post_azinsanaat_import_product', [__CLASS__, 'handle_import_product']);
            add_action('admin_post_azinsanaat_manual_sync', [__CLASS__, 'handle_manual_sync']);
            add_action('add_meta_boxes_product', [__CLASS__, 'register_product_meta_box']);
            add_action('save_post_product', [__CLASS__, 'handle_save_product']);
            add_action('admin_notices', [__CLASS__, 'display_product_sync_notice']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
            add_action('wp_ajax_azinsanaat_fetch_remote_product', [__CLASS__, 'ajax_fetch_remote_product']);
            add_action('wp_ajax_azinsanaat_connect_simple_product', [__CLASS__, 'ajax_connect_simple_product']);
            add_action('wp_ajax_azinsanaat_connect_product_variations', [__CLASS__, 'ajax_connect_product_variations']);
            add_action('wp_ajax_azinsanaat_connect_simple_variation', [__CLASS__, 'ajax_connect_simple_variation']);
            add_action('wp_ajax_azinsanaat_import_product', [__CLASS__, 'ajax_import_product']);
        }

        /**
         * Registers plugin options.
         */
        public static function register_settings(): void
        {
            register_setting(
                'azinsanaat_connection_options_group',
                self::OPTION_KEY,
                [
                    'type'              => 'array',
                    'sanitize_callback' => [__CLASS__, 'sanitize_options'],
                    'default'           => [
                        'connections'   => [],
                        'sync_interval' => '15min',
                    ],
                ]
            );
        }

        /**
         * Defines admin pages.
         */
        public static function register_admin_pages(): void
        {
            add_menu_page(
                __('Azinsanaat Connection', 'azinsanaat-connection'),
                __('اتصال آذین صنعت', 'azinsanaat-connection'),
                self::get_required_capability(),
                'azinsanaat-connection',
                [__CLASS__, 'render_settings_page'],
                'dashicons-rest-api'
            );

            add_submenu_page(
                'azinsanaat-connection',
                __('تنظیمات اتصال', 'azinsanaat-connection'),
                __('تنظیمات', 'azinsanaat-connection'),
                self::get_required_capability(),
                'azinsanaat-connection',
                [__CLASS__, 'render_settings_page']
            );

            add_submenu_page(
                'azinsanaat-connection',
                __('محصولات وب‌سرویس', 'azinsanaat-connection'),
                __('محصولات', 'azinsanaat-connection'),
                self::get_required_capability(),
                'azinsanaat-connection-products',
                [__CLASS__, 'render_products_page']
            );

            add_submenu_page(
                'azinsanaat-connection',
                __('محصولات متصل شده', 'azinsanaat-connection'),
                __('محصولات متصل', 'azinsanaat-connection'),
                self::get_required_capability(),
                'azinsanaat-connection-linked-products',
                [__CLASS__, 'render_connected_products_page']
            );
        }

        protected static function get_required_capability(): string
        {
            $capability = apply_filters('azinsanaat_connection_required_capability', self::CAPABILITY);

            return is_string($capability) && $capability !== '' ? $capability : self::CAPABILITY;
        }

        protected static function current_user_can_manage_plugin(): bool
        {
            if (current_user_can(self::get_required_capability())) {
                return true;
            }

            return current_user_can('manage_woocommerce') || current_user_can('manage_options');
        }

        public static function ensure_plugin_capability(): void
        {
            if (!function_exists('get_role')) {
                return;
            }

            $roles = apply_filters('azinsanaat_connection_capability_roles', ['administrator', 'shop_manager']);
            if (!is_array($roles) || empty($roles)) {
                $roles = ['administrator', 'shop_manager'];
            }

            $capability = self::get_required_capability();

            foreach ($roles as $role_name) {
                $role_name = is_string($role_name) ? sanitize_key($role_name) : '';
                if ($role_name === '') {
                    continue;
                }

                $role = get_role($role_name);
                if (!$role || $role->has_cap($capability)) {
                    continue;
                }

                $role->add_cap($capability);
            }
        }

        /**
         * Sanitizes options before persisting.
         */
        public static function sanitize_options($input): array
        {
            $output = [];
            $output['sync_interval'] = isset($input['sync_interval'])
                ? self::sanitize_sync_interval($input['sync_interval'])
                : '15min';
            $default_connection_interval = $output['sync_interval'];

            $connections = [];
            if (isset($input['connections']) && is_array($input['connections'])) {
                foreach ($input['connections'] as $key => $connection) {
                    if (!is_array($connection)) {
                        continue;
                    }

                    $store_url = isset($connection['store_url']) ? esc_url_raw(trim((string) $connection['store_url'])) : '';
                    $consumer_key = isset($connection['consumer_key']) ? sanitize_text_field($connection['consumer_key']) : '';
                    $consumer_secret = isset($connection['consumer_secret']) ? sanitize_text_field($connection['consumer_secret']) : '';

                    if ($store_url === '' || $consumer_key === '' || $consumer_secret === '') {
                        continue;
                    }

                    $label = isset($connection['label']) ? sanitize_text_field($connection['label']) : '';
                    if ($label === '') {
                        $parsed_url = wp_parse_url($store_url, PHP_URL_HOST);
                        $label = $parsed_url ? $parsed_url : $store_url;
                    }

                    $id = isset($connection['id']) ? sanitize_key($connection['id']) : '';
                    if ($id === '') {
                        $id = sanitize_key(wp_unique_id('conn_'));
                    }

                    $connection_interval = isset($connection['sync_interval'])
                        ? self::sanitize_sync_interval($connection['sync_interval'])
                        : $default_connection_interval;

                    $connections[] = [
                        'id'              => $id,
                        'label'           => $label,
                        'store_url'       => $store_url,
                        'consumer_key'    => $consumer_key,
                        'consumer_secret' => $consumer_secret,
                        'sync_interval'   => $connection_interval,
                    ];
                }
            }

            if (empty($connections)) {
                $legacy_url = isset($input['store_url']) ? esc_url_raw(trim((string) $input['store_url'])) : '';
                $legacy_key = isset($input['consumer_key']) ? sanitize_text_field($input['consumer_key']) : '';
                $legacy_secret = isset($input['consumer_secret']) ? sanitize_text_field($input['consumer_secret']) : '';

                if ($legacy_url && $legacy_key && $legacy_secret) {
                    $connections[] = [
                        'id'              => 'default',
                        'label'           => wp_parse_url($legacy_url, PHP_URL_HOST) ?: $legacy_url,
                        'store_url'       => $legacy_url,
                        'consumer_key'    => $legacy_key,
                        'consumer_secret' => $legacy_secret,
                        'sync_interval'   => $default_connection_interval,
                    ];
                }
            }

            $output['connections'] = array_values($connections);

            return $output;
        }

        protected static function sanitize_sync_interval($value): string
        {
            $value = is_string($value) ? sanitize_text_field($value) : '';
            $intervals = self::get_sync_intervals();

            if (!isset($intervals[$value])) {
                $value = '15min';
            }

            return $value;
        }

        protected static function get_sync_intervals(): array
        {
            return [
                '15min' => [
                    'interval' => 15 * MINUTE_IN_SECONDS,
                    'label'    => __('هر ۱۵ دقیقه یک‌بار', 'azinsanaat-connection'),
                ],
                '30min' => [
                    'interval' => 30 * MINUTE_IN_SECONDS,
                    'label'    => __('هر ۳۰ دقیقه یک‌بار', 'azinsanaat-connection'),
                ],
                '1hour' => [
                    'interval' => HOUR_IN_SECONDS,
                    'label'    => __('هر ۱ ساعت یک‌بار', 'azinsanaat-connection'),
                ],
                '3hour' => [
                    'interval' => 3 * HOUR_IN_SECONDS,
                    'label'    => __('هر ۳ ساعت یک‌بار', 'azinsanaat-connection'),
                ],
            ];
        }

        protected static function normalize_connection(array $connection, ?string $fallback_id = null, ?string $fallback_interval = null): ?array
        {
            $store_url = isset($connection['store_url']) ? esc_url_raw(trim((string) $connection['store_url'])) : '';
            $consumer_key = isset($connection['consumer_key']) ? sanitize_text_field($connection['consumer_key']) : '';
            $consumer_secret = isset($connection['consumer_secret']) ? sanitize_text_field($connection['consumer_secret']) : '';

            if ($store_url === '' || $consumer_key === '' || $consumer_secret === '') {
                return null;
            }

            $label = isset($connection['label']) ? sanitize_text_field($connection['label']) : '';
            if ($label === '') {
                $parsed_url = wp_parse_url($store_url, PHP_URL_HOST);
                $label = $parsed_url ? $parsed_url : $store_url;
            }

            $id = isset($connection['id']) ? sanitize_key($connection['id']) : '';
            if ($id === '' && $fallback_id !== null) {
                $id = sanitize_key($fallback_id);
            }

            if ($id === '') {
                $id = sanitize_key('conn_' . md5($store_url . $consumer_key));
            }

            if ($id === '') {
                $id = sanitize_key(wp_unique_id('conn_'));
            }

            $interval = isset($connection['sync_interval'])
                ? self::sanitize_sync_interval($connection['sync_interval'])
                : ($fallback_interval ? self::sanitize_sync_interval($fallback_interval) : '15min');

            return [
                'id'              => $id,
                'label'           => $label,
                'store_url'       => $store_url,
                'consumer_key'    => $consumer_key,
                'consumer_secret' => $consumer_secret,
                'sync_interval'   => $interval,
            ];
        }

        protected static function get_plugin_options(): array
        {
            $raw_options = get_option(self::OPTION_KEY);
            if (!is_array($raw_options)) {
                $raw_options = [];
            }

            $options = [];
            $options['sync_interval'] = isset($raw_options['sync_interval'])
                ? self::sanitize_sync_interval($raw_options['sync_interval'])
                : '15min';
            $default_connection_interval = $options['sync_interval'];

            $connections = [];
            if (!empty($raw_options['connections']) && is_array($raw_options['connections'])) {
                foreach ($raw_options['connections'] as $key => $connection) {
                    if (!is_array($connection)) {
                        continue;
                    }

                    $normalized = self::normalize_connection(
                        $connection,
                        is_string($key) ? $key : null,
                        $default_connection_interval
                    );
                    if (!$normalized) {
                        continue;
                    }

                    $connections[$normalized['id']] = $normalized;
                }
            } elseif (!empty($raw_options['store_url']) && !empty($raw_options['consumer_key']) && !empty($raw_options['consumer_secret'])) {
                $legacy = [
                    'id'              => 'default',
                    'label'           => $raw_options['store_url'],
                    'store_url'       => $raw_options['store_url'],
                    'consumer_key'    => $raw_options['consumer_key'],
                    'consumer_secret' => $raw_options['consumer_secret'],
                ];

                $normalized = self::normalize_connection($legacy, 'default', $default_connection_interval);
                if ($normalized) {
                    $connections[$normalized['id']] = $normalized;
                }
            }

            $options['connections'] = array_values($connections);

            return $options;
        }

        protected static function get_connections_indexed(): array
        {
            $options = self::get_plugin_options();
            $indexed = [];

            foreach ($options['connections'] as $connection) {
                $indexed[$connection['id']] = $connection;
            }

            return $indexed;
        }

        protected static function get_connection_or_default(?string $connection_id)
        {
            $connections = self::get_connections_indexed();
            if (empty($connections)) {
                return null;
            }

            $connection_id = $connection_id ? sanitize_key($connection_id) : '';
            if ($connection_id && isset($connections[$connection_id])) {
                return $connections[$connection_id];
            }

            return reset($connections);
        }

        protected static function get_default_connection_id(): string
        {
            $connection = self::get_connection_or_default('');
            return $connection ? $connection['id'] : '';
        }

        protected static function get_product_connection_id(int $product_id): string
        {
            $stored = get_post_meta($product_id, self::META_REMOTE_CONNECTION, true);
            $stored = is_string($stored) ? sanitize_key($stored) : '';

            $connection = self::get_connection_or_default($stored ?: null);
            if (!$connection) {
                return '';
            }

            return $connection['id'];
        }

        /**
         * Outputs the settings page content.
         */
        public static function render_settings_page(): void
        {
            if (!self::current_user_can_manage_plugin()) {
                return;
            }

            $options = self::get_plugin_options();
            $connections = $options['connections'];
            $connection_message = self::get_transient_message('azinsanaat_connection_status_message');
            $default_sync_interval = $options['sync_interval'] ?? '15min';
            $has_connections = !empty($connections);
            $option_key = self::OPTION_KEY;
            $sync_intervals = self::get_sync_intervals();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('تنظیمات اتصال آذین صنعت', 'azinsanaat-connection'); ?></h1>
                <?php if ($connection_message) : ?>
                    <div class="notice notice-<?php echo esc_attr($connection_message['type']); ?>">
                        <p><?php echo esc_html($connection_message['message']); ?></p>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                    <?php
                    settings_fields('azinsanaat_connection_options_group');
                    ?>
                    <h2><?php esc_html_e('اتصالات وب‌سرویس', 'azinsanaat-connection'); ?></h2>
                    <p class="description"><?php esc_html_e('اطلاعات اتصال API هر فروشگاه ووکامرسی را در این بخش وارد کنید.', 'azinsanaat-connection'); ?></p>
                    <div id="azinsanaat-connections-container" class="azinsanaat-connections-container">
                        <?php if ($has_connections) : ?>
                            <?php foreach ($connections as $connection) :
                                $connection_id = esc_attr($connection['id']);
                                $connection_interval = $connection['sync_interval'] ?? $default_sync_interval;
                                ?>
                                <div class="azinsanaat-connection-item">
                                    <input type="hidden" name="<?php echo esc_attr($option_key); ?>[connections][<?php echo esc_attr($connection['id']); ?>][id]" value="<?php echo esc_attr($connection['id']); ?>">
                                    <p>
                                        <label for="azinsanaat-connection-label-<?php echo $connection_id; ?>"><?php esc_html_e('عنوان اتصال', 'azinsanaat-connection'); ?></label>
                                        <input id="azinsanaat-connection-label-<?php echo $connection_id; ?>" type="text" class="regular-text" name="<?php echo esc_attr($option_key); ?>[connections][<?php echo esc_attr($connection['id']); ?>][label]" value="<?php echo esc_attr($connection['label']); ?>">
                                        <span class="description"><?php esc_html_e('نام نمایشی برای شناسایی سریع این اتصال.', 'azinsanaat-connection'); ?></span>
                                    </p>
                                    <p>
                                        <label for="azinsanaat-connection-url-<?php echo $connection_id; ?>"><?php esc_html_e('آدرس فروشگاه', 'azinsanaat-connection'); ?></label>
                                        <input id="azinsanaat-connection-url-<?php echo $connection_id; ?>" type="url" class="regular-text" name="<?php echo esc_attr($option_key); ?>[connections][<?php echo esc_attr($connection['id']); ?>][store_url]" value="<?php echo esc_attr($connection['store_url']); ?>" required>
                                    </p>
                                    <p>
                                        <label for="azinsanaat-connection-key-<?php echo $connection_id; ?>"><?php esc_html_e('Consumer Key', 'azinsanaat-connection'); ?></label>
                                        <input id="azinsanaat-connection-key-<?php echo $connection_id; ?>" type="text" class="regular-text" name="<?php echo esc_attr($option_key); ?>[connections][<?php echo esc_attr($connection['id']); ?>][consumer_key]" value="<?php echo esc_attr($connection['consumer_key']); ?>" required>
                                    </p>
                                    <p>
                                        <label for="azinsanaat-connection-secret-<?php echo $connection_id; ?>"><?php esc_html_e('Consumer Secret', 'azinsanaat-connection'); ?></label>
                                        <input id="azinsanaat-connection-secret-<?php echo $connection_id; ?>" type="text" class="regular-text" name="<?php echo esc_attr($option_key); ?>[connections][<?php echo esc_attr($connection['id']); ?>][consumer_secret]" value="<?php echo esc_attr($connection['consumer_secret']); ?>" required>
                                    </p>
                                    <p>
                                        <label for="azinsanaat-connection-sync-<?php echo $connection_id; ?>"><?php esc_html_e('بازه زمانی همگام‌سازی خودکار', 'azinsanaat-connection'); ?></label>
                                        <select id="azinsanaat-connection-sync-<?php echo $connection_id; ?>" name="<?php echo esc_attr($option_key); ?>[connections][<?php echo esc_attr($connection['id']); ?>][sync_interval]">
                                            <?php foreach ($sync_intervals as $key => $interval) : ?>
                                                <option value="<?php echo esc_attr($key); ?>" <?php selected($connection_interval, $key); ?>><?php echo esc_html($interval['label']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="description"><?php esc_html_e('زمان‌بندی اجرای خودکار به‌روزرسانی قیمت و موجودی محصولات متصل.', 'azinsanaat-connection'); ?></span>
                                    </p>
                                    <p class="azinsanaat-connection-actions">
                                        <button type="button" class="button-link-delete azinsanaat-remove-connection"><?php esc_html_e('حذف این اتصال', 'azinsanaat-connection'); ?></button>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p class="description azinsanaat-no-connections"><?php esc_html_e('هیچ اتصالی ثبت نشده است. روی «افزودن اتصال جدید» کلیک کنید.', 'azinsanaat-connection'); ?></p>
                        <?php endif; ?>
                    </div>
                    <p>
                        <button type="button" class="button button-secondary" id="azinsanaat-add-connection"><?php esc_html_e('افزودن اتصال جدید', 'azinsanaat-connection'); ?></button>
                    </p>
                    <?php submit_button(__('ذخیره تنظیمات', 'azinsanaat-connection')); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1.5rem;">
                    <?php wp_nonce_field(self::NONCE_ACTION_TEST); ?>
                    <input type="hidden" name="action" value="azinsanaat_test_connection">
                    <?php if ($has_connections) : ?>
                        <label for="azinsanaat-test-connection" class="screen-reader-text"><?php esc_html_e('انتخاب اتصال برای بررسی', 'azinsanaat-connection'); ?></label>
                        <select id="azinsanaat-test-connection" name="connection_id">
                            <?php foreach ($connections as $connection) : ?>
                                <option value="<?php echo esc_attr($connection['id']); ?>"><?php echo esc_html($connection['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php submit_button(__('بررسی وضعیت اتصال', 'azinsanaat-connection'), 'secondary', 'submit', false); ?>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('برای بررسی وضعیت اتصال ابتدا حداقل یک اتصال ثبت کنید.', 'azinsanaat-connection'); ?></p>
                        <?php submit_button(__('بررسی وضعیت اتصال', 'azinsanaat-connection'), 'secondary', 'submit', false, ['disabled' => 'disabled', 'aria-disabled' => 'true']); ?>
                    <?php endif; ?>
                </form>
                <script type="text/html" id="azinsanaat-connection-template">
                    <div class="azinsanaat-connection-item">
                        <input type="hidden" name="<?php echo esc_attr($option_key); ?>[connections][__key__][id]" value="__key__">
                        <p>
                            <label for="azinsanaat-connection-label-__key__"><?php esc_html_e('عنوان اتصال', 'azinsanaat-connection'); ?></label>
                            <input id="azinsanaat-connection-label-__key__" type="text" class="regular-text" name="<?php echo esc_attr($option_key); ?>[connections][__key__][label]" value="">
                            <span class="description"><?php esc_html_e('نام نمایشی برای شناسایی سریع این اتصال.', 'azinsanaat-connection'); ?></span>
                        </p>
                        <p>
                            <label for="azinsanaat-connection-url-__key__"><?php esc_html_e('آدرس فروشگاه', 'azinsanaat-connection'); ?></label>
                            <input id="azinsanaat-connection-url-__key__" type="url" class="regular-text" name="<?php echo esc_attr($option_key); ?>[connections][__key__][store_url]" value="" required>
                        </p>
                        <p>
                            <label for="azinsanaat-connection-key-__key__"><?php esc_html_e('Consumer Key', 'azinsanaat-connection'); ?></label>
                            <input id="azinsanaat-connection-key-__key__" type="text" class="regular-text" name="<?php echo esc_attr($option_key); ?>[connections][__key__][consumer_key]" value="" required>
                        </p>
                        <p>
                            <label for="azinsanaat-connection-secret-__key__"><?php esc_html_e('Consumer Secret', 'azinsanaat-connection'); ?></label>
                            <input id="azinsanaat-connection-secret-__key__" type="text" class="regular-text" name="<?php echo esc_attr($option_key); ?>[connections][__key__][consumer_secret]" value="" required>
                        </p>
                        <p>
                            <label for="azinsanaat-connection-sync-__key__"><?php esc_html_e('بازه زمانی همگام‌سازی خودکار', 'azinsanaat-connection'); ?></label>
                            <select id="azinsanaat-connection-sync-__key__" name="<?php echo esc_attr($option_key); ?>[connections][__key__][sync_interval]">
                                <?php foreach ($sync_intervals as $key => $interval) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($default_sync_interval, $key); ?>><?php echo esc_html($interval['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="description"><?php esc_html_e('زمان‌بندی اجرای خودکار به‌روزرسانی قیمت و موجودی محصولات متصل.', 'azinsanaat-connection'); ?></span>
                        </p>
                        <p class="azinsanaat-connection-actions">
                            <button type="button" class="button-link-delete azinsanaat-remove-connection"><?php esc_html_e('حذف این اتصال', 'azinsanaat-connection'); ?></button>
                        </p>
                    </div>
                </script>
                <style>
                    .azinsanaat-connections-container .azinsanaat-connection-item {
                        border: 1px solid #ccd0d4;
                        padding: 15px;
                        margin-bottom: 15px;
                        background: #fff;
                    }

                    .azinsanaat-connections-container .azinsanaat-connection-item label {
                        display: block;
                        font-weight: 600;
                        margin-bottom: 4px;
                    }

                    .azinsanaat-connections-container .azinsanaat-connection-item input.regular-text {
                        width: 100%;
                        max-width: 100%;
                    }

                    .azinsanaat-connections-container .azinsanaat-connection-item select {
                        min-width: 200px;
                    }

                    .azinsanaat-connection-actions {
                        margin-top: 10px;
                    }
                </style>
            </div>
            <?php
        }

        /**
         * Enqueues admin assets for plugin admin pages.
         */
        public static function enqueue_admin_assets(string $hook): void
        {
            if ('toplevel_page_azinsanaat-connection' === $hook) {
                wp_enqueue_script(
                    'azinsanaat-settings-page',
                    plugin_dir_url(__FILE__) . 'assets/js/settings-page.js',
                    ['jquery'],
                    '1.0.0',
                    true
                );

                wp_localize_script(
                    'azinsanaat-settings-page',
                    'AzinsanaatSettingsPage',
                    [
                        'messages' => [
                            'noConnections' => __('هیچ اتصالی ثبت نشده است. روی «افزودن اتصال جدید» کلیک کنید.', 'azinsanaat-connection'),
                        ],
                    ]
                );
            }

            $is_products_page = $hook === 'azinsanaat-connection_page_azinsanaat-connection-products'
                || 0 === strpos($hook, 'azinsanaat-connection_page_azinsanaat-connection-products-')
                || 0 === strpos($hook, 'azinsanaat-connection_page_azinsanaat-connection-products-network');

            if ($is_products_page) {
                wp_enqueue_style(
                    'azinsanaat-products-page',
                    plugin_dir_url(__FILE__) . 'assets/css/products-page.css',
                    [],
                    '1.0.0'
                );

                wp_enqueue_script(
                    'azinsanaat-products-page',
                    plugin_dir_url(__FILE__) . 'assets/js/products-page.js',
                    ['jquery'],
                    '1.2.0',
                    true
                );

                wp_localize_script(
                    'azinsanaat-products-page',
                    'AzinsanaatProductsPage',
                    [
                        'ajaxUrl'  => admin_url('admin-ajax.php'),
                        'messages' => [
                            'genericError' => __('خطا در پردازش درخواست. لطفاً دوباره تلاش کنید.', 'azinsanaat-connection'),
                            'networkError' => __('خطایی در ارتباط با سرور رخ داد.', 'azinsanaat-connection'),
                            'editLinkLabel'=> __('مشاهده پیش‌نویس', 'azinsanaat-connection'),
                        ],
                    ]
                );
            }

            if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
                return;
            }

            $screen = get_current_screen();
            if (!$screen || 'product' !== $screen->post_type) {
                return;
            }

            global $post;
            $post_id = ($post instanceof WP_Post) ? (int) $post->ID : 0;
            $options = self::get_plugin_options();
            $connections = $options['connections'];
            $current_connection = $post_id ? self::get_product_connection_id($post_id) : (!empty($connections) ? $connections[0]['id'] : '');

            wp_register_script(
                'azinsanaat-product-meta',
                plugin_dir_url(__FILE__) . 'assets/js/product-meta-box.js',
                ['jquery'],
                '1.4.0',
                true
            );

            wp_localize_script(
                'azinsanaat-product-meta',
                'AzinsanaatProductMeta',
                [
                    'ajaxUrl'        => admin_url('admin-ajax.php'),
                    'nonce'          => wp_create_nonce(self::NONCE_ACTION_META),
                    'productId'      => $post_id,
                    'currentRemoteId'=> $post_id ? (int) get_post_meta($post_id, self::META_REMOTE_ID, true) : 0,
                    'currentConnection' => $current_connection,
                    'hasConnections' => !empty($connections),
                    'strings'        => [
                        'fetching'                  => __('در حال دریافت اطلاعات...', 'azinsanaat-connection'),
                        'fetchButton'               => __('دریافت', 'azinsanaat-connection'),
                        'connectSimple'             => __('اتصال و همگام‌سازی', 'azinsanaat-connection'),
                        'connectSimpleToVariation'  => __('اتصال به متغیر انتخابی', 'azinsanaat-connection'),
                        'saveVariations'            => __('ذخیره اتصال متغیرها', 'azinsanaat-connection'),
                        'noVariationsFound'         => __('متغیری برای این محصول در ووکامرس یافت نشد.', 'azinsanaat-connection'),
                        'selectVariationPlaceholder'=> __('انتخاب متغیر', 'azinsanaat-connection'),
                        'simpleVariationDescription'=> __('برای همگام‌سازی قیمت و موجودی با یکی از متغیرهای ووکامرس، آن را انتخاب کنید.', 'azinsanaat-connection'),
                        'success'                   => __('عملیات با موفقیت انجام شد.', 'azinsanaat-connection'),
                        'error'                     => __('در انجام عملیات خطایی رخ داد.', 'azinsanaat-connection'),
                        'duplicateVariation'        => __('هر متغیر ووکامرس فقط باید به یک متغیر وب‌سرویس متصل شود.', 'azinsanaat-connection'),
                        'missingProduct'            => __('برای استفاده از این بخش ابتدا محصول را ذخیره کنید.', 'azinsanaat-connection'),
                        'invalidRemote'             => __('شناسه محصول معتبر نیست.', 'azinsanaat-connection'),
                        'selectVariationRequired'   => __('لطفاً یک متغیر را انتخاب کنید.', 'azinsanaat-connection'),
                        'noMappings'                => __('حداقل یک متغیر باید انتخاب شود.', 'azinsanaat-connection'),
                        'missingConnection'         => __('لطفاً یک اتصال را انتخاب کنید.', 'azinsanaat-connection'),
                        'noConnectionsConfigured'   => __('هیچ اتصالی برای استفاده از وب‌سرویس ثبت نشده است.', 'azinsanaat-connection'),
                    ],
                ]
            );

            wp_enqueue_script('azinsanaat-product-meta');
        }

        /**
         * Handles the connection testing.
         */
        public static function handle_test_connection(): void
        {
            if (!self::current_user_can_manage_plugin()) {
                wp_die(__('شما اجازه دسترسی ندارید.', 'azinsanaat-connection'));
            }

            check_admin_referer(self::NONCE_ACTION_TEST);

            $connection_id = isset($_POST['connection_id']) ? sanitize_key(wp_unslash($_POST['connection_id'])) : '';
            $client = self::get_api_client($connection_id ?: null);
            if (is_wp_error($client)) {
                self::set_transient_message('azinsanaat_connection_status_message', [
                    'type'    => 'error',
                    'message' => $client->get_error_message(),
                ]);
                wp_safe_redirect(self::get_settings_page_url());
                exit;
            }

            $response = $client->get('products', ['per_page' => 1]);
            if (is_wp_error($response)) {
                self::set_transient_message('azinsanaat_connection_status_message', [
                    'type'    => 'error',
                    'message' => $response->get_error_message(),
                ]);
            } else {
                $status = wp_remote_retrieve_response_code($response);
                if ($status >= 200 && $status < 300) {
                    self::set_transient_message('azinsanaat_connection_status_message', [
                        'type'    => 'success',
                        'message' => __('اتصال با موفقیت برقرار شد.', 'azinsanaat-connection'),
                    ]);
                } else {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $message = $body['message'] ?? sprintf(__('پاسخ نامعتبر از سرور (کد: %s).', 'azinsanaat-connection'), $status);
                    self::set_transient_message('azinsanaat_connection_status_message', [
                        'type'    => 'error',
                        'message' => $message,
                    ]);
                }
            }

            wp_safe_redirect(self::get_settings_page_url());
            exit;
        }

        /**
         * Renders remote products list.
         */
        public static function render_products_page(): void
        {
            if (!self::current_user_can_manage_plugin()) {
                return;
            }

            $options = self::get_plugin_options();
            $connections = $options['connections'];
            if (empty($connections)) {
                ?>
                <div class="wrap">
                    <h1><?php esc_html_e('محصولات وب‌سرویس آذین صنعت', 'azinsanaat-connection'); ?></h1>
                    <div class="notice notice-warning"><p><?php esc_html_e('برای مشاهده محصولات ابتدا حداقل یک اتصال فعال تعریف کنید.', 'azinsanaat-connection'); ?></p></div>
                </div>
                <?php
                return;
            }

            $requested_connection = isset($_GET['connection_id']) ? sanitize_key(wp_unslash($_GET['connection_id'])) : '';
            $selected_connection = self::get_connection_or_default($requested_connection ?: null);
            if (!$selected_connection) {
                $selected_connection = reset($connections);
            }

            $selected_connection_id = $selected_connection['id'];
            $selected_connection_label = $selected_connection['label'];

            $client = self::get_api_client($selected_connection_id);
            $products = [];
            $error_message = '';
            $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
            $per_page = 20;
            $stock_filter = isset($_GET['stock_status']) ? sanitize_text_field(wp_unslash($_GET['stock_status'])) : '';
            $allowed_stock_statuses = [
                'instock'    => __('موجود', 'azinsanaat-connection'),
                'outofstock' => __('ناموجود', 'azinsanaat-connection'),
            ];
            if ($stock_filter === '' || !array_key_exists($stock_filter, $allowed_stock_statuses)) {
                $stock_filter = 'instock';
            }
            $total_pages = 1;
            $total_remote_products_count = 0;

            if (is_wp_error($client)) {
                $error_message = $client->get_error_message();
            } else {
                $request_args = [
                    'per_page' => $per_page,
                    'page'     => $current_page,
                    'status'   => 'publish',
                ];

                $request_args['stock_status'] = $stock_filter;

                $response = $client->get('products', $request_args);

                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                } else {
                    $status = wp_remote_retrieve_response_code($response);
                    if ($status < 200 || $status >= 300) {
                        $body = json_decode(wp_remote_retrieve_body($response), true);
                        $error_message = $body['message'] ?? sprintf(__('پاسخ نامعتبر از سرور (کد: %s).', 'azinsanaat-connection'), $status);
                    } else {
                        $decoded = json_decode(wp_remote_retrieve_body($response), true);
                        if (!is_array($decoded)) {
                            $error_message = __('پاسخ نامعتبر از سرور دریافت شد.', 'azinsanaat-connection');
                        } else {
                            $products = $decoded;
                            if (!empty($products)) {
                                usort($products, function ($item_a, $item_b) {
                                    $priority = [
                                        'instock'     => 0,
                                        'onbackorder' => 1,
                                        'outofstock'  => 2,
                                    ];

                                    $a_status = isset($item_a['stock_status']) ? (string) $item_a['stock_status'] : '';
                                    $b_status = isset($item_b['stock_status']) ? (string) $item_b['stock_status'] : '';
                                    $a_priority = $priority[$a_status] ?? 3;
                                    $b_priority = $priority[$b_status] ?? 3;

                                    if ($a_priority === $b_priority) {
                                        return 0;
                                    }

                                    return ($a_priority < $b_priority) ? -1 : 1;
                                });
                            }
                            $total_pages = (int) wp_remote_retrieve_header($response, 'x-wp-totalpages');
                            if ($total_pages < 1) {
                                $total_pages = 1;
                            }
                            $total_remote_products_count = (int) wp_remote_retrieve_header($response, 'x-wp-total');
                            if ($total_remote_products_count < 0) {
                                $total_remote_products_count = 0;
                            }
                        }
                    }
                }
            }

            $connected_remote_ids = [];
            $connected_products_count_by_connection = [];
            $product_variation_details = [];

            $connected_posts = get_posts([
                'post_type'      => 'product',
                'post_status'    => ['publish', 'pending', 'draft', 'private'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => self::META_REMOTE_ID,
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);

            foreach ($connected_posts as $connected_post_id) {
                $remote_id = (int) get_post_meta($connected_post_id, self::META_REMOTE_ID, true);
                $connection_id = get_post_meta($connected_post_id, self::META_REMOTE_CONNECTION, true);
                $connection_id = is_string($connection_id) ? sanitize_key($connection_id) : '';

                if ($remote_id) {
                    $key = $connection_id ? $connection_id . '|' . $remote_id : '|' . $remote_id;
                    $connected_remote_ids[$key] = $connected_post_id;
                    if ($connection_id !== '') {
                        if (!isset($connected_products_count_by_connection[$connection_id])) {
                            $connected_products_count_by_connection[$connection_id] = 0;
                        }
                        $connected_products_count_by_connection[$connection_id]++;
                    }
                }
            }

            if (!empty($products)) {
                $products = array_values(array_filter($products, function ($product) use ($selected_connection_id, $connected_remote_ids) {
                    $remote_product_id = isset($product['id']) ? (int) $product['id'] : 0;
                    if (!$remote_product_id) {
                        return true;
                    }

                    $connection_lookup_key = $selected_connection_id . '|' . $remote_product_id;

                    return !isset($connected_remote_ids[$connection_lookup_key]);
                }));
            }

            if (!$error_message && !empty($products)) {
                foreach ($products as $product_data) {
                    $remote_product_id = isset($product_data['id']) ? (int) $product_data['id'] : 0;
                    $product_type = $product_data['type'] ?? '';
                    $has_variations = ($product_type === 'variable') || (!empty($product_data['variations']));

                    if (!$remote_product_id || !$has_variations) {
                        continue;
                    }

                    $variations_response = self::fetch_remote_variations($client, $remote_product_id);

                    if (is_wp_error($variations_response)) {
                        $product_variation_details[$remote_product_id] = [
                            'error'      => $variations_response->get_error_message(),
                            'variations' => [],
                        ];
                        continue;
                    }

                    $formatted_variations = array_map(function ($variation) {
                        return [
                            'id'             => isset($variation['id']) ? (int) $variation['id'] : 0,
                            'attributes'     => self::format_variation_attributes($variation['attributes'] ?? []),
                            'price'          => $variation['price'] ?? '',
                            'regular_price'  => $variation['regular_price'] ?? '',
                            'sale_price'     => $variation['sale_price'] ?? '',
                            'stock_status'   => self::format_stock_status($variation['stock_status'] ?? ''),
                            'stock_quantity' => $variation['stock_quantity'] ?? null,
                        ];
                    }, $variations_response);

                    $product_variation_details[$remote_product_id] = [
                        'error'      => '',
                        'variations' => $formatted_variations,
                    ];
                }
            }

            $notice = self::get_transient_message('azinsanaat_connection_import_notice');

            $site_categories = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);

            $site_categories_error = '';
            if (is_wp_error($site_categories)) {
                $site_categories_error = $site_categories->get_error_message();
                $site_categories = [];
            }

            $bulk_creation_url = self::get_bulk_product_creation_url($selected_connection_id);
            $available_import_sections = self::get_available_import_sections();

            ?>
            <div class="wrap azinsanaat-products-page">
                <h1><?php esc_html_e('محصولات وب‌سرویس آذین صنعت', 'azinsanaat-connection'); ?></h1>
                <p class="description"><?php echo esc_html(sprintf(__('اتصال فعال: %s', 'azinsanaat-connection'), $selected_connection_label)); ?></p>
                <?php if ($bulk_creation_url) : ?>
                    <p class="azinsanaat-products-actions">
                        <a class="button button-primary" href="<?php echo esc_url($bulk_creation_url); ?>">
                            <?php esc_html_e('ساخت محصول گروهی', 'azinsanaat-connection'); ?>
                        </a>
                    </p>
                <?php endif; ?>
                <?php if ($error_message) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html($error_message); ?></p></div>
                <?php endif; ?>
                <?php
                $selected_connection_connected_count = $connected_products_count_by_connection[$selected_connection_id] ?? 0;
                ?>
                <div class="notice notice-info azinsanaat-products-summary">
                    <p>
                        <?php if ($total_remote_products_count > 0) : ?>
                            <?php
                            printf(
                                esc_html__('تعداد محصولات متصل شده این وب‌سرویس: %1$s از %2$s محصول موجود.', 'azinsanaat-connection'),
                                esc_html(number_format_i18n($selected_connection_connected_count)),
                                esc_html(number_format_i18n($total_remote_products_count))
                            );
                            ?>
                        <?php else : ?>
                            <?php
                            printf(
                                esc_html__('تعداد محصولات متصل شده این وب‌سرویس: %s', 'azinsanaat-connection'),
                                esc_html(number_format_i18n($selected_connection_connected_count))
                            );
                            ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($notice) : ?>
                    <div class="notice notice-<?php echo esc_attr($notice['type']); ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
                <?php endif; ?>
                <form method="get" class="azinsanaat-connection__filters">
                    <input type="hidden" name="page" value="azinsanaat-connection-products">
                    <label for="azinsanaat-connection-id" class="screen-reader-text"><?php esc_html_e('انتخاب اتصال', 'azinsanaat-connection'); ?></label>
                    <select id="azinsanaat-connection-id" name="connection_id">
                        <?php foreach ($connections as $connection_option) : ?>
                            <option value="<?php echo esc_attr($connection_option['id']); ?>" <?php selected($selected_connection_id, $connection_option['id']); ?>><?php echo esc_html($connection_option['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="azinsanaat-stock-status" class="screen-reader-text"><?php esc_html_e('فیلتر وضعیت موجودی', 'azinsanaat-connection'); ?></label>
                    <select id="azinsanaat-stock-status" name="stock_status">
                        <?php foreach ($allowed_stock_statuses as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($stock_filter, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button(__('اعمال فیلتر', 'azinsanaat-connection'), 'secondary', '', false); ?>
                </form>
                <?php if (!$error_message) : ?>
                    <div class="azinsanaat-products-search">
                        <div class="azinsanaat-products-search-field">
                            <label for="azinsanaat-products-search" class="screen-reader-text"><?php esc_html_e('جستجو در نتایج فعلی', 'azinsanaat-connection'); ?></label>
                            <input
                                type="search"
                                id="azinsanaat-products-search"
                                class="azinsanaat-products-search-input"
                                placeholder="<?php esc_attr_e('جستجو در محصولات نمایش داده شده...', 'azinsanaat-connection'); ?>"
                                autocomplete="off"
                            >
                            <button type="button" class="button button-secondary azinsanaat-products-search-button">
                                <?php esc_html_e('جستجو', 'azinsanaat-connection'); ?>
                            </button>
                        </div>
                        <p class="description"><?php esc_html_e('برای اعمال جستجو عبارت مورد نظر را وارد کرده و دکمه جستجو را بزنید (یا کلید اینتر را فشار دهید).', 'azinsanaat-connection'); ?></p>
                        <p class="description azinsanaat-products-search-empty" style="display:none;">
                            <?php esc_html_e('هیچ محصولی با عبارت جستجو شده در نتایج فعلی یافت نشد.', 'azinsanaat-connection'); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!$error_message && empty($products)) : ?>
                    <p><?php esc_html_e('هیچ محصولی یافت نشد.', 'azinsanaat-connection'); ?></p>
                <?php elseif (!$error_message) : ?>
                    <table class="widefat striped azinsanaat-products-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('ردیف', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('شناسه', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('نام', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('قیمت', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('وضعیت موجودی', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('تعداد موجودی', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('دسته‌بندی سایت', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('ویرایش محصول', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('موارد واردسازی', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('عملیات', 'azinsanaat-connection'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $index => $product) : ?>
                            <tr>
                                <td><?php echo esc_html(($index + 1) + (($current_page - 1) * $per_page)); ?></td>
                                <td><?php echo esc_html($product['id']); ?></td>
                                <td><?php echo esc_html($product['name']); ?></td>
                                <td><?php echo isset($product['price']) ? esc_html($product['price']) : '—'; ?></td>
                                <td><?php echo esc_html(self::format_stock_status($product['stock_status'] ?? '')); ?></td>
                                <td><?php echo isset($product['stock_quantity']) ? esc_html($product['stock_quantity']) : '—'; ?></td>
                                <?php
                                $remote_product_id = (int) ($product['id'] ?? 0);
                                $connection_lookup_key = $remote_product_id ? $selected_connection_id . '|' . $remote_product_id : '';
                                $is_connected = $connection_lookup_key && isset($connected_remote_ids[$connection_lookup_key]);
                                $connected_product_id = $is_connected ? (int) $connected_remote_ids[$connection_lookup_key] : 0;
                                $form_id = 'azinsanaat-import-form-' . $remote_product_id;
                                ?>
                                <td>
                                    <?php if ($site_categories_error) : ?>
                                        <p class="description"><?php echo esc_html($site_categories_error); ?></p>
                                    <?php elseif (empty($site_categories)) : ?>
                                        <p class="description"><?php esc_html_e('هیچ دسته‌بندی محصولی در سایت یافت نشد.', 'azinsanaat-connection'); ?></p>
                                    <?php else :
                                        $select_id = 'azinsanaat-site-category-' . (int) $product['id'];
                                        ?>
                                        <label class="screen-reader-text" for="<?php echo esc_attr($select_id); ?>"><?php esc_html_e('انتخاب دسته‌بندی برای ساخت محصول', 'azinsanaat-connection'); ?></label>
                                        <select
                                            id="<?php echo esc_attr($select_id); ?>"
                                            name="site_category_id"
                                            class="azinsanaat-site-category-select"
                                            form="<?php echo esc_attr($form_id); ?>"
                                        >
                                            <option value=""><?php esc_html_e('انتخاب دسته‌بندی...', 'azinsanaat-connection'); ?></option>
                                            <?php foreach ($site_categories as $term) : ?>
                                                <option value="<?php echo esc_attr($term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    if ($connected_product_id) {
                                        $edit_link = get_edit_post_link($connected_product_id);
                                        if ($edit_link) {
                                            ?>
                                            <a href="<?php echo esc_url($edit_link); ?>">
                                                <?php esc_html_e('ویرایش', 'azinsanaat-connection'); ?>
                                            </a>
                                            <?php
                                        } else {
                                            echo '—';
                                        }
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <fieldset class="azinsanaat-import-options">
                                        <legend class="screen-reader-text"><?php esc_html_e('انتخاب موارد واردسازی از وب‌سرویس', 'azinsanaat-connection'); ?></legend>
                                        <input type="hidden" name="import_sections_submitted" value="1" form="<?php echo esc_attr($form_id); ?>">
                                        <?php foreach ($available_import_sections as $section_key => $section_label) :
                                            $option_id = sprintf('azinsanaat-import-%s-%d', $section_key, (int) $product['id']);
                                            ?>
                                            <label for="<?php echo esc_attr($option_id); ?>">
                                                <input
                                                    type="checkbox"
                                                    id="<?php echo esc_attr($option_id); ?>"
                                                    name="import_sections[]"
                                                    value="<?php echo esc_attr($section_key); ?>"
                                                    form="<?php echo esc_attr($form_id); ?>"
                                                    checked
                                                >
                                                <?php echo esc_html($section_label); ?>
                                            </label><br>
                                        <?php endforeach; ?>
                                    </fieldset>
                                </td>
                                <td>
                                    <form
                                        id="<?php echo esc_attr($form_id); ?>"
                                        method="post"
                                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        class="azinsanaat-import-form"
                                        data-product-id="<?php echo esc_attr($product['id']); ?>"
                                        data-connection-id="<?php echo esc_attr($selected_connection_id); ?>"
                                    >
                                        <?php wp_nonce_field(self::NONCE_ACTION_IMPORT); ?>
                                        <input type="hidden" name="action" value="azinsanaat_import_product">
                                        <input type="hidden" name="product_id" value="<?php echo esc_attr($product['id']); ?>">
                                        <input type="hidden" name="connection_id" value="<?php echo esc_attr($selected_connection_id); ?>">
                                        <?php
                                        submit_button(
                                            __('دریافت و ساخت پیش‌نویس', 'azinsanaat-connection'),
                                            'secondary azinsanaat-import-button',
                                            'submit',
                                            false,
                                            $is_connected ? ['disabled' => 'disabled', 'aria-disabled' => 'true'] : []
                                        );
                                        ?>
                                        <span class="spinner" aria-hidden="true"></span>
                                        <div class="azinsanaat-import-feedback" aria-live="polite"></div>
                                    </form>
                                <?php if ($is_connected) : ?>
                                    <p class="description"><?php esc_html_e('این محصول قبلاً متصل شده است.', 'azinsanaat-connection'); ?></p>
                                <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($product_variation_details[$remote_product_id])) :
                                $variation_info = $product_variation_details[$remote_product_id];
                                ?>
                                <tr class="azinsanaat-product-variations-row">
                                    <td colspan="9">
                                        <?php if (!empty($variation_info['error'])) : ?>
                                            <p class="description"><?php echo esc_html($variation_info['error']); ?></p>
                                        <?php elseif (!empty($variation_info['variations'])) : ?>
                                            <table class="widefat striped azinsanaat-product-variations-table">
                                                <thead>
                                                <tr>
                                                    <th><?php esc_html_e('شناسه متغیر', 'azinsanaat-connection'); ?></th>
                                                    <th><?php esc_html_e('ویژگی‌ها', 'azinsanaat-connection'); ?></th>
                                                    <th><?php esc_html_e('قیمت', 'azinsanaat-connection'); ?></th>
                                                    <th><?php esc_html_e('قیمت حراج', 'azinsanaat-connection'); ?></th>
                                                    <th><?php esc_html_e('وضعیت موجودی', 'azinsanaat-connection'); ?></th>
                                                    <th><?php esc_html_e('تعداد موجودی', 'azinsanaat-connection'); ?></th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($variation_info['variations'] as $variation) : ?>
                                                    <tr>
                                                        <td><?php echo esc_html($variation['id'] ?: '—'); ?></td>
                                                        <td><?php echo esc_html($variation['attributes'] ?: '—'); ?></td>
                                                        <td><?php echo esc_html($variation['price'] !== '' ? $variation['price'] : ($variation['regular_price'] !== '' ? $variation['regular_price'] : '—')); ?></td>
                                                        <td><?php echo esc_html($variation['sale_price'] !== '' ? $variation['sale_price'] : '—'); ?></td>
                                                        <td><?php echo esc_html($variation['stock_status']); ?></td>
                                                        <td><?php echo isset($variation['stock_quantity']) ? esc_html($variation['stock_quantity']) : '—'; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php else : ?>
                                            <p class="description"><?php esc_html_e('متغیری برای این محصول یافت نشد.', 'azinsanaat-connection'); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                    if ($total_pages > 1) {
                        $base_url = menu_page_url('azinsanaat-connection-products', false);
                        $query_args = [
                            'connection_id' => $selected_connection_id,
                        ];

                        if ($stock_filter !== '') {
                            $query_args['stock_status'] = $stock_filter;
                        }

                        $base_link = add_query_arg(array_merge($query_args, ['paged' => '%#%']), $base_url);
                        $pagination_links = paginate_links([
                            'base'      => $base_link,
                            'format'    => '',
                            'total'     => max(1, $total_pages),
                            'current'   => $current_page,
                            'prev_text' => __('« قبلی', 'azinsanaat-connection'),
                            'next_text' => __('بعدی »', 'azinsanaat-connection'),
                            'type'      => 'array',
                        ]);

                        if (!empty($pagination_links)) {
                            echo '<div class="tablenav"><div class="tablenav-pages"><span class="pagination-links">';
                            foreach ($pagination_links as $pagination_link) {
                                echo wp_kses_post($pagination_link);
                            }
                            echo '</span></div></div>';
                        }
                    }
                    ?>
                <?php endif; ?>
            </div>
            <?php
        }

        public static function render_connected_products_page(): void
        {
            if (!self::current_user_can_manage_plugin()) {
                return;
            }

            $requested_connection = isset($_GET['connection_id']) ? sanitize_key(wp_unslash($_GET['connection_id'])) : '';
            $meta_query = [
                [
                    'key'     => self::META_REMOTE_ID,
                    'compare' => 'EXISTS',
                ],
            ];

            if ($requested_connection !== '') {
                $meta_query[] = [
                    'key'   => self::META_REMOTE_CONNECTION,
                    'value' => $requested_connection,
                ];
            }

            $connected_products = get_posts([
                'post_type'      => 'product',
                'post_status'    => ['publish', 'pending', 'draft', 'private'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => $meta_query,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);

            $notice = self::get_transient_message(self::NOTICE_CONNECTED_PRODUCTS);
            $connection_map = [];
            $available_connections = self::get_connections_indexed();
            foreach ($available_connections as $connection) {
                $connection_map[$connection['id']] = $connection['label'];
            }
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('محصولات متصل شده به وب‌سرویس', 'azinsanaat-connection'); ?></h1>
                <?php if (!empty($available_connections)) : ?>
                    <form method="get" class="azinsanaat-connection__filters">
                        <input type="hidden" name="page" value="azinsanaat-connection-linked-products">
                        <label for="azinsanaat-connected-filter" class="screen-reader-text"><?php esc_html_e('فیلتر براساس وب‌سرویس', 'azinsanaat-connection'); ?></label>
                        <select id="azinsanaat-connected-filter" name="connection_id">
                            <option value=""><?php esc_html_e('همه وب‌سرویس‌ها', 'azinsanaat-connection'); ?></option>
                            <?php foreach ($available_connections as $connection) : ?>
                                <option value="<?php echo esc_attr($connection['id']); ?>" <?php selected($requested_connection, $connection['id']); ?>><?php echo esc_html($connection['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php submit_button(__('اعمال فیلتر', 'azinsanaat-connection'), 'secondary', '', false); ?>
                    </form>
                <?php endif; ?>
                <?php if ($notice) : ?>
                    <div class="notice notice-<?php echo esc_attr($notice['type']); ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
                <?php endif; ?>
                <?php if (empty($connected_products)) : ?>
                    <p><?php esc_html_e('هنوز هیچ محصولی به وب‌سرویس متصل نشده است.', 'azinsanaat-connection'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('ردیف', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('نام محصول', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('اتصال', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('شناسه وب‌سرویس', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('تعداد متغیرهای متصل', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('آخرین همگام‌سازی', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('عملیات', 'azinsanaat-connection'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($connected_products as $index => $product_id) :
                            $remote_id = (int) get_post_meta($product_id, self::META_REMOTE_ID, true);
                            $last_sync = self::get_formatted_sync_time($product_id);
                            $variation_count = self::get_connected_variations_count($product_id);
                            $connection_id = get_post_meta($product_id, self::META_REMOTE_CONNECTION, true);
                            $connection_label = '';
                            if ($connection_id) {
                                $connection_id = sanitize_key($connection_id);
                                $connection_label = $connection_map[$connection_id] ?? $connection_id;
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html($index + 1); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>">
                                        <?php echo esc_html(get_the_title($product_id)); ?>
                                    </a>
                                </td>
                                <td><?php echo $connection_label ? esc_html($connection_label) : '—'; ?></td>
                                <td><?php echo $remote_id ? esc_html($remote_id) : '—'; ?></td>
                                <td><?php echo esc_html($variation_count); ?></td>
                                <td><?php echo $last_sync ? esc_html($last_sync) : '—'; ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field(self::NONCE_ACTION_MANUAL_SYNC); ?>
                                        <input type="hidden" name="action" value="azinsanaat_manual_sync">
                                        <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">
                                        <?php submit_button(__('سینک مجدد', 'azinsanaat-connection'), 'secondary', 'submit', false); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Handles importing a product from remote API.
         */
        public static function handle_import_product(): void
        {
            if (!self::current_user_can_manage_plugin()) {
                wp_die(__('شما اجازه دسترسی ندارید.', 'azinsanaat-connection'));
            }

            check_admin_referer(self::NONCE_ACTION_IMPORT);

            if (!class_exists('WooCommerce')) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => __('افزونه ووکامرس فعال نیست.', 'azinsanaat-connection'),
                ]);
                wp_safe_redirect(self::get_products_page_url());
                exit;
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            if (!$product_id) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => __('شناسه محصول نامعتبر است.', 'azinsanaat-connection'),
                ]);
                $redirect_args = [];
                $requested_connection = isset($_POST['connection_id']) ? sanitize_key(wp_unslash($_POST['connection_id'])) : '';
                if ($requested_connection) {
                    $redirect_args['connection_id'] = $requested_connection;
                }
                wp_safe_redirect(self::get_products_page_url($redirect_args));
                exit;
            }

            $connection_id = isset($_POST['connection_id']) ? sanitize_key(wp_unslash($_POST['connection_id'])) : '';
            $site_category_id = isset($_POST['site_category_id']) ? absint(wp_unslash($_POST['site_category_id'])) : 0;
            $sections_submitted = isset($_POST['import_sections_submitted']);
            $raw_import_sections = null;
            if ($sections_submitted) {
                $raw_import_sections = isset($_POST['import_sections']) ? wp_unslash((array) $_POST['import_sections']) : [];
            }
            $import_sections = self::prepare_import_sections($raw_import_sections, $sections_submitted);

            $result = self::import_remote_product(
                $product_id,
                $connection_id ?: null,
                $site_category_id ?: null,
                $import_sections
            );

            if (is_wp_error($result)) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => $result->get_error_message(),
                ]);
            } else {
                $product_name = $result['product_name'] ?: $product_id;
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'success',
                    'message' => sprintf(__('محصول "%s" با موفقیت به حالت در انتظار بررسی ایجاد شد.', 'azinsanaat-connection'), $product_name),
                ]);
            }

            $redirect_args = [];
            if ($connection_id) {
                $redirect_args['connection_id'] = $connection_id;
            }
            wp_safe_redirect(self::get_products_page_url($redirect_args));
            exit;
        }

        public static function ajax_import_product(): void
        {
            if (!self::current_user_can_manage_plugin()) {
                wp_send_json_error([
                    'message' => __('شما اجازه دسترسی ندارید.', 'azinsanaat-connection'),
                ], 403);
            }

            check_ajax_referer(self::NONCE_ACTION_IMPORT, 'nonce');

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            if (!$product_id) {
                wp_send_json_error([
                    'message' => __('شناسه محصول نامعتبر است.', 'azinsanaat-connection'),
                ], 400);
            }

            $connection_id = isset($_POST['connection_id']) ? sanitize_key(wp_unslash($_POST['connection_id'])) : '';
            $site_category_id = isset($_POST['site_category_id']) ? absint(wp_unslash($_POST['site_category_id'])) : 0;
            $sections_submitted = isset($_POST['import_sections_submitted']);
            $raw_import_sections = null;
            if ($sections_submitted) {
                $raw_import_sections = isset($_POST['import_sections']) ? wp_unslash((array) $_POST['import_sections']) : [];
            }
            $import_sections = self::prepare_import_sections($raw_import_sections, $sections_submitted);
            $result = self::import_remote_product($product_id, $connection_id ?: null, $site_category_id ?: null, $import_sections);
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                ]);
            }

            $product_name = $result['product_name'] ?: $product_id;
            $response = [
                'message' => sprintf(__('محصول "%s" با موفقیت به حالت در انتظار بررسی ایجاد شد.', 'azinsanaat-connection'), $product_name),
                'post_id' => $result['post_id'],
            ];

            $edit_url = get_edit_post_link($result['post_id'], 'raw');
            if ($edit_url) {
                $response['edit_url'] = esc_url_raw($edit_url);
            }

            wp_send_json_success($response);
        }

        protected static function import_remote_product(int $product_id, ?string $connection_id = null, ?int $site_category_id = null, ?array $import_sections = null)
        {
            if (!class_exists('WooCommerce')) {
                return new WP_Error('azinsanaat_wc_inactive', __('افزونه ووکامرس فعال نیست.', 'azinsanaat-connection'));
            }

            $client = self::get_api_client($connection_id);
            if (is_wp_error($client)) {
                return $client;
            }

            $response = $client->get('products/' . $product_id);
            if (is_wp_error($response)) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);

            if ($status_code < 200 || $status_code >= 300) {
                $message = is_array($decoded) && isset($decoded['message'])
                    ? $decoded['message']
                    : sprintf(__('پاسخ نامعتبر از سرور (کد: %s).', 'azinsanaat-connection'), $status_code);

                return new WP_Error('azinsanaat_invalid_response', $message);
            }

            if (!is_array($decoded)) {
                return new WP_Error('azinsanaat_invalid_body', __('پاسخ نامعتبر از سرور دریافت شد.', 'azinsanaat-connection'));
            }

            $normalized_sections = self::normalize_import_sections($import_sections);
            $result = self::create_pending_product($decoded, $site_category_id, $connection_id ?: null, $normalized_sections);
            if (is_wp_error($result)) {
                return $result;
            }

            return [
                'post_id'      => (int) $result,
                'product_name' => $decoded['name'] ?? '',
            ];
        }

        /**
         * Creates a pending WooCommerce product locally.
         */
        protected static function create_pending_product(array $data, ?int $site_category_id = null, ?string $connection_id = null, array $import_sections = [])
        {
            $import_sections = self::normalize_import_sections($import_sections);
            $should_import_title = in_array('title', $import_sections, true);
            $should_import_featured_image = in_array('featured_image', $import_sections, true);
            $should_import_gallery = in_array('gallery', $import_sections, true);
            $should_import_description = in_array('description', $import_sections, true);

            $name = $data['name'] ?? '';
            if ($should_import_title && !$name) {
                return new WP_Error('azinsanaat_missing_name', __('نام محصول در پاسخ API یافت نشد.', 'azinsanaat-connection'));
            }

            $sku = $data['sku'] ?? '';
            if ($sku && function_exists('wc_get_product_id_by_sku')) {
                $existing_id = wc_get_product_id_by_sku($sku);
                if ($existing_id) {
                    return new WP_Error('azinsanaat_product_exists', __('محصولی با این SKU قبلاً وجود دارد.', 'azinsanaat-connection'));
                }
            }

            $short_description = '';
            $post_content = '';

            if ($should_import_description) {
                $short_description = isset($data['short_description']) ? wp_kses_post($data['short_description']) : '';
                $short_description = preg_replace('/<img[^>]*>/i', '', $short_description);
                $post_content = isset($data['description']) ? wp_kses_post($data['description']) : '';
            }

            $post_data = [
                'post_title'   => ($should_import_title && $name !== '') ? wp_strip_all_tags($name) : '',
                'post_status'  => 'pending',
                'post_type'    => 'product',
                'post_excerpt' => $short_description,
                'post_content' => $post_content,
            ];

            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                return $post_id;
            }

            $remote_product_id = isset($data['id']) ? (int) $data['id'] : 0;
            if ($remote_product_id > 0) {
                update_post_meta($post_id, self::META_REMOTE_ID, $remote_product_id);
            }

            if ($connection_id) {
                update_post_meta($post_id, self::META_REMOTE_CONNECTION, sanitize_key($connection_id));
            }

            if ($sku) {
                update_post_meta($post_id, '_sku', sanitize_text_field($sku));
            }

            if (isset($data['regular_price'])) {
                update_post_meta($post_id, '_regular_price', wc_clean($data['regular_price']));
                update_post_meta($post_id, '_price', wc_clean($data['regular_price']));
            } elseif (isset($data['price'])) {
                update_post_meta($post_id, '_price', wc_clean($data['price']));
            }

            if (isset($data['stock_status'])) {
                update_post_meta($post_id, '_stock_status', sanitize_text_field($data['stock_status']));
            }

            if (isset($data['stock_quantity'])) {
                update_post_meta($post_id, '_stock', intval($data['stock_quantity']));
            }

            $selected_category_id = self::normalize_product_category_id($site_category_id);
            $category_ids = [];

            if ($selected_category_id) {
                $category_ids = [$selected_category_id];
            } elseif (!empty($data['categories']) && is_array($data['categories'])) {
                $category_ids = self::resolve_existing_term_ids($data['categories'], 'product_cat');
            }

            if (!empty($category_ids)) {
                wp_set_object_terms($post_id, $category_ids, 'product_cat');
            }

            if (!empty($data['tags']) && is_array($data['tags'])) {
                $tag_names = array_map(function ($tag) {
                    return $tag['name'] ?? '';
                }, $data['tags']);
                $tag_names = array_filter($tag_names);
                if (!empty($tag_names)) {
                    wp_set_object_terms($post_id, $tag_names, 'product_tag');
                }
            }

            $manage_stock = !empty($data['manage_stock']) ? 'yes' : 'no';
            update_post_meta($post_id, '_manage_stock', $manage_stock);

            if (!empty($data['images']) && is_array($data['images']) && ($should_import_featured_image || $should_import_gallery)) {
                $gallery_ids = [];
                $featured_id = 0;

                foreach ($data['images'] as $image) {
                    $image_url = $image['src'] ?? '';
                    if (!$image_url) {
                        continue;
                    }

                    $image_url = esc_url_raw($image_url);

                    if ($should_import_featured_image && $featured_id === 0) {
                        $attachment_id = self::set_featured_image_from_url($post_id, $image_url);
                        if (is_wp_error($attachment_id) || !$attachment_id) {
                            continue;
                        }

                        $featured_id = $attachment_id;
                        if (!$should_import_gallery) {
                            continue;
                        }
                    }

                    if ($should_import_gallery) {
                        $attachment_id = self::sideload_product_image($post_id, $image_url);
                        if (is_wp_error($attachment_id) || !$attachment_id) {
                            continue;
                        }

                        $gallery_ids[] = $attachment_id;
                    }
                }

                if ($should_import_gallery && !empty($gallery_ids)) {
                    update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
                }
            }

            return $post_id;
        }

        protected static function get_available_import_sections(): array
        {
            return [
                'title'          => __('عنوان', 'azinsanaat-connection'),
                'featured_image' => __('عکس شاخص', 'azinsanaat-connection'),
                'gallery'        => __('گالری محصول', 'azinsanaat-connection'),
                'description'    => __('توضیحات', 'azinsanaat-connection'),
            ];
        }

        protected static function get_default_import_sections(): array
        {
            return array_keys(self::get_available_import_sections());
        }

        protected static function normalize_import_sections(?array $sections): array
        {
            if ($sections === null) {
                return self::get_default_import_sections();
            }

            $available = array_keys(self::get_available_import_sections());
            $sanitized = array_map(static function ($value) {
                return sanitize_key((string) $value);
            }, $sections);
            $sanitized = array_filter($sanitized);
            $sanitized = array_values(array_intersect($sanitized, $available));

            return array_values(array_unique($sanitized));
        }

        protected static function prepare_import_sections($sections, bool $submitted = false): array
        {
            if ($sections === null) {
                return $submitted ? [] : self::get_default_import_sections();
            }

            if (!is_array($sections)) {
                $sections = [$sections];
            }

            return self::normalize_import_sections($sections);
        }

        /**
         * Finds existing term IDs for the provided term data without creating new terms.
         */
        protected static function resolve_existing_term_ids(array $terms, string $taxonomy): array
        {
            $term_ids = [];

            foreach ($terms as $term_data) {
                if (!is_array($term_data)) {
                    continue;
                }

                $name = isset($term_data['name']) ? trim(wp_strip_all_tags((string) $term_data['name'])) : '';
                if ($name === '') {
                    continue;
                }

                $term = get_term_by('name', $name, $taxonomy);
                if (!$term || is_wp_error($term)) {
                    $slug_source = isset($term_data['slug']) ? (string) $term_data['slug'] : $name;
                    $slug = sanitize_title($slug_source);
                    if ($slug) {
                        $term = get_term_by('slug', $slug, $taxonomy);
                    }
                }

                if ($term && !is_wp_error($term)) {
                    $term_ids[] = (int) $term->term_id;
                }
            }

            return array_values(array_unique($term_ids));
        }

        protected static function normalize_product_category_id(?int $term_id): int
        {
            $term_id = $term_id ? absint($term_id) : 0;

            if (!$term_id) {
                return 0;
            }

            $term = get_term($term_id, 'product_cat');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return (int) $term->term_id;
            }

            return 0;
        }

        public static function handle_manual_sync(): void
        {
            if (!self::current_user_can_manage_plugin()) {
                wp_die(__('شما اجازه دسترسی ندارید.', 'azinsanaat-connection'));
            }

            check_admin_referer(self::NONCE_ACTION_MANUAL_SYNC);

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            if (!$product_id) {
                self::set_transient_message(self::NOTICE_CONNECTED_PRODUCTS, [
                    'type'    => 'error',
                    'message' => __('محصول انتخاب شده یافت نشد.', 'azinsanaat-connection'),
                ]);
                wp_safe_redirect(self::get_connected_products_page_url());
                exit;
            }

            $remote_id = (int) get_post_meta($product_id, self::META_REMOTE_ID, true);
            if (!$remote_id) {
                self::set_transient_message(self::NOTICE_CONNECTED_PRODUCTS, [
                    'type'    => 'error',
                    'message' => __('برای این محصول شناسه وب‌سرویس ثبت نشده است.', 'azinsanaat-connection'),
                ]);
                wp_safe_redirect(self::get_connected_products_page_url());
                exit;
            }

            $connection_id = self::get_product_connection_id($product_id);
            if (!$connection_id) {
                self::set_transient_message(self::NOTICE_CONNECTED_PRODUCTS, [
                    'type'    => 'error',
                    'message' => __('هیچ اتصال معتبری برای این محصول یافت نشد.', 'azinsanaat-connection'),
                ]);
                wp_safe_redirect(self::get_connected_products_page_url());
                exit;
            }

            $result = self::sync_product_with_remote($product_id, $remote_id, $connection_id);

            if (is_wp_error($result)) {
                self::set_transient_message(self::NOTICE_CONNECTED_PRODUCTS, [
                    'type'    => 'error',
                    'message' => $result->get_error_message(),
                ]);
            } else {
                self::set_transient_message(self::NOTICE_CONNECTED_PRODUCTS, [
                    'type'    => 'success',
                    'message' => __('محصول با موفقیت همگام‌سازی شد.', 'azinsanaat-connection'),
                ]);
            }

            wp_safe_redirect(self::get_connected_products_page_url());
            exit;
        }

        protected static function set_featured_image_from_url(int $post_id, string $image_url)
        {
            $attachment_id = self::sideload_product_image($post_id, $image_url);

            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
            }

            return $attachment_id;
        }

        protected static function sideload_product_image(int $post_id, string $image_url)
        {
            if (empty($image_url)) {
                return false;
            }

            if (!function_exists('media_sideload_image')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            return media_sideload_image($image_url, $post_id, null, 'id');
        }

        protected static function format_stock_status(string $status): string
        {
            switch ($status) {
                case 'instock':
                    return __('موجود', 'azinsanaat-connection');
                case 'outofstock':
                    return __('ناموجود', 'azinsanaat-connection');
                case 'onbackorder':
                    return __('در انتظار تأمین', 'azinsanaat-connection');
                default:
                    return $status !== '' ? $status : '—';
            }
        }

        public static function register_product_meta_box(): void
        {
            add_meta_box(
                'azinsanaat-product-sync',
                __('همگام‌سازی با آذین صنعت', 'azinsanaat-connection'),
                [__CLASS__, 'render_product_meta_box'],
                'product',
                'side'
            );
        }

        public static function render_product_meta_box(WP_Post $post): void
        {
            $options = self::get_plugin_options();
            $connections = $options['connections'];
            $has_connections = !empty($connections);
            $current_connection_id = self::get_product_connection_id($post->ID);
            $remote_id = get_post_meta($post->ID, self::META_REMOTE_ID, true);
            $last_sync = get_post_meta($post->ID, self::META_LAST_SYNC, true);

            wp_nonce_field('azinsanaat_save_product_meta', 'azinsanaat_product_meta_nonce');
            ?>
            <div id="azinsanaat-product-meta-box" data-product-id="<?php echo esc_attr($post->ID); ?>">
                <?php if ($has_connections) : ?>
                    <p>
                        <label for="azinsanaat-connection-select"><?php esc_html_e('انتخاب اتصال', 'azinsanaat-connection'); ?></label>
                        <select id="azinsanaat-connection-select" name="azinsanaat_remote_connection" class="widefat">
                            <?php foreach ($connections as $connection) : ?>
                                <option value="<?php echo esc_attr($connection['id']); ?>" <?php selected($current_connection_id, $connection['id']); ?>><?php echo esc_html($connection['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('برای اتصال محصول ابتدا در تنظیمات افزونه حداقل یک اتصال API ثبت کنید.', 'azinsanaat-connection'); ?></p>
                <?php endif; ?>
                <p>
                    <label for="azinsanaat-remote-product-id"><?php esc_html_e('شناسه محصول در وب‌سرویس آذین صنعت', 'azinsanaat-connection'); ?></label>
                    <span class="azinsanaat-product-meta-input">
                        <input type="number" id="azinsanaat-remote-product-id" name="azinsanaat_remote_product_id" value="<?php echo esc_attr($remote_id); ?>" class="widefat" min="1" step="1">
                        <button type="button" class="button azinsanaat-fetch-button"><?php esc_html_e('دریافت', 'azinsanaat-connection'); ?></button>
                    </span>
                </p>
                <p class="description"><?php esc_html_e('برای همگام‌سازی فوری، شناسه محصول را وارد و روی دکمه دریافت کلیک کنید.', 'azinsanaat-connection'); ?></p>
                <div class="azinsanaat-meta-messages"></div>
                <div class="azinsanaat-meta-results">
                    <div class="azinsanaat-meta-static">
                        <p><strong><?php esc_html_e('شناسه متصل فعلی:', 'azinsanaat-connection'); ?></strong> <span class="azinsanaat-current-remote-id"><?php echo $remote_id ? esc_html($remote_id) : '—'; ?></span></p>
                        <?php
                        $formatted = '';
                        if ($last_sync) {
                            $timestamp = (int) $last_sync;
                            $format = get_option('date_format') . ' - ' . get_option('time_format');
                            $formatted = function_exists('wp_date') ? wp_date($format, $timestamp) : date_i18n($format, $timestamp);
                        }
                        ?>
                        <p class="azinsanaat-last-sync"><strong><?php esc_html_e('آخرین همگام‌سازی:', 'azinsanaat-connection'); ?></strong> <span><?php echo $formatted ? esc_html($formatted) : '—'; ?></span></p>
                    </div>
                    <div class="azinsanaat-meta-dynamic"></div>
                </div>
            </div>
            <style>
                #azinsanaat-product-meta-box .azinsanaat-product-meta-input {
                    display: flex;
                    gap: 6px;
                    align-items: center;
                }

                #azinsanaat-product-meta-box .azinsanaat-meta-results {
                    margin-top: 10px;
                }

                #azinsanaat-product-meta-box .azinsanaat-simple-actions {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                    margin-top: 10px;
                }

                #azinsanaat-product-meta-box .azinsanaat-simple-variation-wrapper {
                    display: flex;
                    flex-direction: column;
                    gap: 6px;
                }

                #azinsanaat-product-meta-box .azinsanaat-meta-dynamic table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 8px;
                }

                #azinsanaat-product-meta-box .azinsanaat-meta-dynamic table th,
                #azinsanaat-product-meta-box .azinsanaat-meta-dynamic table td {
                    border: 1px solid #ddd;
                    padding: 4px;
                    font-size: 12px;
                    text-align: right;
                }

                #azinsanaat-product-meta-box .azinsanaat-meta-dynamic table th {
                    background-color: #f9f9f9;
                }

                #azinsanaat-product-meta-box .azinsanaat-meta-messages .notice {
                    margin: 10px 0 0;
                }
            </style>
            <?php
        }

        public static function handle_save_product(int $post_id): void
        {
            if (!isset($_POST['azinsanaat_product_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['azinsanaat_product_meta_nonce'])), 'azinsanaat_save_product_meta')) {
                return;
            }

            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            if (wp_is_post_revision($post_id)) {
                return;
            }

            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            $remote_id = isset($_POST['azinsanaat_remote_product_id']) ? absint(wp_unslash($_POST['azinsanaat_remote_product_id'])) : 0;

            if (!$remote_id) {
                delete_post_meta($post_id, self::META_REMOTE_ID);
                delete_post_meta($post_id, self::META_LAST_SYNC);
                delete_post_meta($post_id, self::META_REMOTE_CONNECTION);
                return;
            }

            $connection_id = isset($_POST['azinsanaat_remote_connection']) ? sanitize_key(wp_unslash($_POST['azinsanaat_remote_connection'])) : '';
            if (!$connection_id) {
                $connection_id = self::get_default_connection_id();
            }

            if (!$connection_id) {
                self::add_product_sync_notice($post_id, __('ابتدا یک اتصال معتبر انتخاب کنید.', 'azinsanaat-connection'), 'error');
                return;
            }

            update_post_meta($post_id, self::META_REMOTE_ID, $remote_id);
            update_post_meta($post_id, self::META_REMOTE_CONNECTION, $connection_id);

            $result = self::sync_product_with_remote($post_id, $remote_id, $connection_id);

            if (is_wp_error($result)) {
                self::add_product_sync_notice($post_id, $result->get_error_message(), 'error');
            } else {
                self::add_product_sync_notice($post_id, __('قیمت و موجودی این محصول با موفقیت همگام‌سازی شد.', 'azinsanaat-connection'));
            }
        }

        public static function display_product_sync_notice(): void
        {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || !isset($screen->post_type) || 'product' !== $screen->post_type) {
                return;
            }

            $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!$post_id) {
                return;
            }

            $key = self::get_product_sync_notice_key($post_id);
            $notice = get_transient($key);

            if (!$notice) {
                return;
            }

            delete_transient($key);

            $type = $notice['type'] ?? 'success';
            $message = $notice['message'] ?? '';

            if (!$message) {
                return;
            }

            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($type), esc_html($message));
        }

        protected static function add_product_sync_notice(int $post_id, string $message, string $type = 'success'): void
        {
            set_transient(
                self::get_product_sync_notice_key($post_id),
                [
                    'message' => $message,
                    'type'    => $type,
                ],
                MINUTE_IN_SECONDS
            );
        }

        protected static function get_product_sync_notice_key(int $post_id): string
        {
            return 'azinsanaat_product_sync_notice_' . $post_id;
        }

        public static function ajax_fetch_remote_product(): void
        {
            check_ajax_referer(self::NONCE_ACTION_META, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => __('شما اجازه دسترسی ندارید.', 'azinsanaat-connection')]);
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(['message' => __('افزونه ووکامرس فعال نیست.', 'azinsanaat-connection')]);
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $remote_id = isset($_POST['remote_id']) ? absint($_POST['remote_id']) : 0;
            $connection_id = isset($_POST['connection_id']) ? sanitize_key(wp_unslash($_POST['connection_id'])) : '';

            if (!$product_id) {
                wp_send_json_error(['message' => __('برای استفاده از این قابلیت ابتدا محصول را ذخیره کنید.', 'azinsanaat-connection')]);
            }

            if (!$remote_id) {
                wp_send_json_error(['message' => __('شناسه محصول نامعتبر است.', 'azinsanaat-connection')]);
            }

            if (!$connection_id) {
                $connection_id = self::get_product_connection_id($product_id);
            }

            if (!$connection_id) {
                wp_send_json_error(['message' => __('هیچ اتصال معتبری انتخاب نشده است.', 'azinsanaat-connection')]);
            }

            $client = self::get_api_client($connection_id);
            if (is_wp_error($client)) {
                wp_send_json_error(['message' => $client->get_error_message()]);
            }

            $response = $client->get('products/' . $remote_id);
            if (is_wp_error($response)) {
                wp_send_json_error(['message' => $response->get_error_message()]);
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $message = $body['message'] ?? __('دریافت اطلاعات محصول با خطا مواجه شد.', 'azinsanaat-connection');
                wp_send_json_error(['message' => $message]);
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) {
                wp_send_json_error(['message' => __('پاسخ نامعتبر از وب‌سرویس دریافت شد.', 'azinsanaat-connection')]);
            }

            $product_info = [
                'id'             => (int) ($data['id'] ?? $remote_id),
                'name'           => $data['name'] ?? '',
                'type'           => $data['type'] ?? 'simple',
                'price'          => $data['price'] ?? '',
                'regular_price'  => $data['regular_price'] ?? '',
                'stock_status'   => self::format_stock_status($data['stock_status'] ?? ''),
                'stock_quantity' => $data['stock_quantity'] ?? null,
            ];

            $is_variable = ($product_info['type'] === 'variable' || !empty($data['variations']));
            $remote_variations = [];
            $local_variations = self::get_local_variations_for_product($product_id);
            $allow_simple_variation_link = false;

            if ($is_variable) {
                $variations = self::fetch_remote_variations($client, $product_info['id']);
                if (is_wp_error($variations)) {
                    wp_send_json_error(['message' => $variations->get_error_message()]);
                }

                $remote_variations = array_map(function ($variation) {
                    return [
                        'id'                     => (int) ($variation['id'] ?? 0),
                        'price'                  => $variation['price'] ?? '',
                        'regular_price'          => $variation['regular_price'] ?? '',
                        'stock_status'           => self::format_stock_status($variation['stock_status'] ?? ''),
                        'stock_quantity'         => $variation['stock_quantity'] ?? null,
                        'attributes'             => self::format_variation_attributes($variation['attributes'] ?? []),
                    ];
                }, $variations);

                foreach ($remote_variations as &$remote_variation) {
                    foreach ($local_variations as $local_variation) {
                        $matches_connection = empty($local_variation['connection_id']) || $local_variation['connection_id'] === $connection_id;
                        if ((int) $local_variation['remote_id'] === (int) $remote_variation['id'] && $matches_connection) {
                            $remote_variation['connected_variation_id'] = $local_variation['id'];
                            break;
                        }
                    }
                }
                unset($remote_variation);
            } elseif (!empty($local_variations)) {
                foreach ($local_variations as &$local_variation) {
                    $matches_connection = empty($local_variation['connection_id']) || $local_variation['connection_id'] === $connection_id;
                    if ((int) $local_variation['remote_id'] === (int) $product_info['id'] && $matches_connection) {
                        $local_variation['connected'] = true;
                    }
                }
                unset($local_variation);

                $allow_simple_variation_link = true;
            }

            $current_remote_id = (int) get_post_meta($product_id, self::META_REMOTE_ID, true);

            wp_send_json_success([
                'remote_id'         => $product_info['id'],
                'product'           => $product_info,
                'is_variable'       => $is_variable,
                'remote_variations' => $remote_variations,
                'local_variations'  => $local_variations,
                'current_remote_id' => $current_remote_id,
                'last_sync'         => self::get_formatted_sync_time($product_id),
                'connection_id'     => $connection_id,
                'allow_simple_variation_link' => $allow_simple_variation_link,
            ]);
        }

        public static function ajax_connect_simple_product(): void
        {
            check_ajax_referer(self::NONCE_ACTION_META, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => __('شما اجازه دسترسی ندارید.', 'azinsanaat-connection')]);
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(['message' => __('افزونه ووکامرس فعال نیست.', 'azinsanaat-connection')]);
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $remote_id = isset($_POST['remote_id']) ? absint($_POST['remote_id']) : 0;
            $connection_id = isset($_POST['connection_id']) ? sanitize_key(wp_unslash($_POST['connection_id'])) : '';

            if (!$product_id || !$remote_id) {
                wp_send_json_error(['message' => __('شناسه‌های ارسالی نامعتبر هستند.', 'azinsanaat-connection')]);
            }

            if (!$connection_id) {
                $connection_id = self::get_product_connection_id($product_id);
            }

            if (!$connection_id) {
                wp_send_json_error(['message' => __('هیچ اتصال معتبری انتخاب نشده است.', 'azinsanaat-connection')]);
            }

            update_post_meta($product_id, self::META_REMOTE_ID, $remote_id);
            update_post_meta($product_id, self::META_REMOTE_CONNECTION, $connection_id);

            $result = self::sync_product_with_remote($product_id, $remote_id, $connection_id);
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }

            wp_send_json_success([
                'remote_id' => $remote_id,
                'last_sync' => self::get_formatted_sync_time($product_id),
                'connection_id' => $connection_id,
            ]);
        }

        public static function ajax_connect_product_variations(): void
        {
            check_ajax_referer(self::NONCE_ACTION_META, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => __('شما اجازه دسترسی ندارید.', 'azinsanaat-connection')]);
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(['message' => __('افزونه ووکامرس فعال نیست.', 'azinsanaat-connection')]);
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $remote_id = isset($_POST['remote_id']) ? absint($_POST['remote_id']) : 0;
            $mappings = isset($_POST['mappings']) && is_array($_POST['mappings']) ? $_POST['mappings'] : [];
            $connection_id = isset($_POST['connection_id']) ? sanitize_key(wp_unslash($_POST['connection_id'])) : '';

            if (!$product_id || !$remote_id) {
                wp_send_json_error(['message' => __('شناسه‌های ارسالی نامعتبر هستند.', 'azinsanaat-connection')]);
            }

            if (empty($mappings)) {
                wp_send_json_error(['message' => __('هیچ متغیری برای اتصال ارسال نشده است.', 'azinsanaat-connection')]);
            }

            if (!$connection_id) {
                $connection_id = self::get_product_connection_id($product_id);
            }

            if (!$connection_id) {
                wp_send_json_error(['message' => __('هیچ اتصال معتبری انتخاب نشده است.', 'azinsanaat-connection')]);
            }

            $used_local_variations = [];
            $connected_count = 0;

            foreach ($mappings as $remote_variation_id => $local_variation_id) {
                $remote_variation_id = absint($remote_variation_id);
                $local_variation_id = absint($local_variation_id);

                if (!$remote_variation_id || !$local_variation_id) {
                    continue;
                }

                if ((int) get_post_field('post_parent', $local_variation_id) !== $product_id) {
                    wp_send_json_error(['message' => __('یکی از متغیرهای انتخابی متعلق به این محصول نیست.', 'azinsanaat-connection')]);
                }

                if (in_array($local_variation_id, $used_local_variations, true)) {
                    wp_send_json_error(['message' => __('هر متغیر ووکامرس باید فقط به یک متغیر وب‌سرویس متصل شود.', 'azinsanaat-connection')]);
                }

                $used_local_variations[] = $local_variation_id;

                update_post_meta($local_variation_id, self::META_REMOTE_ID, $remote_variation_id);
                update_post_meta($local_variation_id, self::META_REMOTE_CONNECTION, $connection_id);

                $result = self::sync_variation_with_remote($local_variation_id, $remote_id, $remote_variation_id, $connection_id);
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                }

                $connected_count++;
            }

            if ($connected_count === 0) {
                wp_send_json_error(['message' => __('حداقل یک متغیر باید انتخاب شود.', 'azinsanaat-connection')]);
            }

            update_post_meta($product_id, self::META_REMOTE_ID, $remote_id);
            update_post_meta($product_id, self::META_REMOTE_CONNECTION, $connection_id);
            update_post_meta($product_id, self::META_LAST_SYNC, current_time('timestamp'));

            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }

            wp_send_json_success([
                'remote_id' => $remote_id,
                'last_sync' => self::get_formatted_sync_time($product_id),
                'connection_id' => $connection_id,
            ]);
        }

        public static function ajax_connect_simple_variation(): void
        {
            check_ajax_referer(self::NONCE_ACTION_META, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['message' => __('شما اجازه دسترسی ندارید.', 'azinsanaat-connection')]);
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(['message' => __('افزونه ووکامرس فعال نیست.', 'azinsanaat-connection')]);
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $remote_id = isset($_POST['remote_id']) ? absint($_POST['remote_id']) : 0;
            $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
            $connection_id = isset($_POST['connection_id']) ? sanitize_key(wp_unslash($_POST['connection_id'])) : '';

            if (!$product_id || !$remote_id || !$variation_id) {
                wp_send_json_error(['message' => __('شناسه‌های ارسالی نامعتبر هستند.', 'azinsanaat-connection')]);
            }

            if ((int) get_post_field('post_parent', $variation_id) !== $product_id) {
                wp_send_json_error(['message' => __('متغیر انتخاب‌شده متعلق به این محصول نیست.', 'azinsanaat-connection')]);
            }

            if (!$connection_id) {
                $connection_id = self::get_product_connection_id($product_id);
            }

            if (!$connection_id) {
                wp_send_json_error(['message' => __('هیچ اتصال معتبری انتخاب نشده است.', 'azinsanaat-connection')]);
            }

            $existing_variations = get_posts([
                'post_type'      => 'product_variation',
                'post_parent'    => $product_id,
                'post_status'    => ['publish', 'private', 'draft'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'post__not_in'   => [$variation_id],
                'meta_query'     => [
                    [
                        'key'   => self::META_REMOTE_ID,
                        'value' => $remote_id,
                    ],
                ],
            ]);

            foreach ($existing_variations as $existing_variation_id) {
                delete_post_meta($existing_variation_id, self::META_REMOTE_ID);
                delete_post_meta($existing_variation_id, self::META_LAST_SYNC);
                delete_post_meta($existing_variation_id, self::META_REMOTE_CONNECTION);
            }

            update_post_meta($variation_id, self::META_REMOTE_ID, $remote_id);
            update_post_meta($variation_id, self::META_REMOTE_CONNECTION, $connection_id);
            update_post_meta($product_id, self::META_REMOTE_ID, $remote_id);
            update_post_meta($product_id, self::META_REMOTE_CONNECTION, $connection_id);

            $result = self::sync_product_with_remote($product_id, $remote_id, $connection_id);
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }

            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }

            wp_send_json_success([
                'remote_id'    => $remote_id,
                'variation_id' => $variation_id,
                'last_sync'    => self::get_formatted_sync_time($product_id),
                'connection_id'=> $connection_id,
            ]);
        }

        protected static function sync_product_with_remote(int $product_id, int $remote_id, ?string $connection_id = null)
        {
            $connection_id = $connection_id ? sanitize_key($connection_id) : '';
            if (!$connection_id) {
                $connection_id = self::get_product_connection_id($product_id);
            }

            if (!$connection_id) {
                return new WP_Error('azinsanaat_missing_connection', __('هیچ اتصال معتبری برای این محصول پیدا نشد.', 'azinsanaat-connection'));
            }

            $client = self::get_api_client($connection_id);
            if (is_wp_error($client)) {
                return $client;
            }

            $response = $client->get('products/' . $remote_id);
            if (is_wp_error($response)) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $message = $body['message'] ?? __('خطا در دریافت اطلاعات محصول از وب‌سرویس.', 'azinsanaat-connection');

                return new WP_Error('azinsanaat_sync_failed', $message);
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) {
                return new WP_Error('azinsanaat_invalid_response', __('پاسخ نامعتبر از وب‌سرویس دریافت شد.', 'azinsanaat-connection'));
            }

            $clean_callback = function_exists('wc_clean') ? 'wc_clean' : 'sanitize_text_field';

            $product_type = $data['type'] ?? '';

            if ($product_type !== 'variable') {
                if (isset($data['regular_price']) && $data['regular_price'] !== '') {
                    $regular_price = call_user_func($clean_callback, $data['regular_price']);
                    update_post_meta($product_id, '_regular_price', $regular_price);
                    update_post_meta($product_id, '_price', $regular_price);
                } elseif (isset($data['price']) && $data['price'] !== '') {
                    $price = call_user_func($clean_callback, $data['price']);
                    update_post_meta($product_id, '_price', $price);
                    delete_post_meta($product_id, '_regular_price');
                }
            }

            if (isset($data['stock_status']) && $data['stock_status'] !== '') {
                update_post_meta($product_id, '_stock_status', sanitize_text_field($data['stock_status']));
            }

            if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null && $data['stock_quantity'] !== '') {
                $quantity = (int) $data['stock_quantity'];
                update_post_meta($product_id, '_manage_stock', 'yes');
                update_post_meta($product_id, '_stock', $quantity);
            } else {
                update_post_meta($product_id, '_manage_stock', 'no');
                delete_post_meta($product_id, '_stock');
            }

            if ($product_type === 'variable' || !empty($data['variations'])) {
                $variations_result = self::sync_variable_children($product_id, $remote_id, $client, $connection_id);
                if (is_wp_error($variations_result)) {
                    return $variations_result;
                }
            } else {
                self::sync_simple_variations($product_id, $remote_id, $data, $connection_id);
            }

            update_post_meta($product_id, self::META_LAST_SYNC, current_time('timestamp'));

            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }

            update_post_meta($product_id, self::META_REMOTE_CONNECTION, $connection_id);
            return true;
        }

        protected static function sync_variable_children(int $product_id, int $remote_product_id, $client, string $connection_id)
        {
            $variation_ids = get_posts([
                'post_type'      => 'product_variation',
                'post_parent'    => $product_id,
                'post_status'    => ['publish', 'private', 'draft'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => self::META_REMOTE_ID,
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);

            if (empty($variation_ids)) {
                return true;
            }

            foreach ($variation_ids as $variation_id) {
                $remote_variation_id = (int) get_post_meta($variation_id, self::META_REMOTE_ID, true);
                if (!$remote_variation_id) {
                    continue;
                }

                update_post_meta($variation_id, self::META_REMOTE_CONNECTION, $connection_id);

                $result = self::sync_variation_with_remote($variation_id, $remote_product_id, $remote_variation_id, $connection_id, $client);
                if (is_wp_error($result)) {
                    return $result;
                }
            }

            return true;
        }

        protected static function sync_simple_variations(int $product_id, int $remote_product_id, array $remote_product_data, string $connection_id): void
        {
            $variation_ids = get_posts([
                'post_type'      => 'product_variation',
                'post_parent'    => $product_id,
                'post_status'    => ['publish', 'private', 'draft'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'   => self::META_REMOTE_ID,
                        'value' => $remote_product_id,
                    ],
                ],
            ]);

            if (empty($variation_ids)) {
                return;
            }

            foreach ($variation_ids as $variation_id) {
                update_post_meta($variation_id, self::META_REMOTE_CONNECTION, $connection_id);
                self::apply_simple_remote_data_to_variation($variation_id, $remote_product_data);
            }
        }

        protected static function apply_simple_remote_data_to_variation(int $variation_id, array $remote_product_data): void
        {
            $clean_callback = function_exists('wc_clean') ? 'wc_clean' : 'sanitize_text_field';

            if (isset($remote_product_data['regular_price']) && $remote_product_data['regular_price'] !== '') {
                update_post_meta($variation_id, '_regular_price', call_user_func($clean_callback, $remote_product_data['regular_price']));
            } else {
                delete_post_meta($variation_id, '_regular_price');
            }

            if (isset($remote_product_data['sale_price']) && $remote_product_data['sale_price'] !== '') {
                update_post_meta($variation_id, '_sale_price', call_user_func($clean_callback, $remote_product_data['sale_price']));
            } else {
                delete_post_meta($variation_id, '_sale_price');
            }

            if (isset($remote_product_data['price']) && $remote_product_data['price'] !== '') {
                update_post_meta($variation_id, '_price', call_user_func($clean_callback, $remote_product_data['price']));
            } else {
                delete_post_meta($variation_id, '_price');
            }

            if (isset($remote_product_data['stock_status']) && $remote_product_data['stock_status'] !== '') {
                update_post_meta($variation_id, '_stock_status', sanitize_text_field($remote_product_data['stock_status']));
            }

            if (array_key_exists('stock_quantity', $remote_product_data) && $remote_product_data['stock_quantity'] !== null && $remote_product_data['stock_quantity'] !== '') {
                update_post_meta($variation_id, '_stock', (int) $remote_product_data['stock_quantity']);
                update_post_meta($variation_id, '_manage_stock', !empty($remote_product_data['manage_stock']) ? 'yes' : 'no');
            } else {
                update_post_meta($variation_id, '_manage_stock', 'no');
                delete_post_meta($variation_id, '_stock');
            }

            update_post_meta($variation_id, self::META_LAST_SYNC, current_time('timestamp'));
        }

        protected static function sync_variation_with_remote(int $variation_id, int $remote_product_id, int $remote_variation_id, ?string $connection_id = null, $client = null)
        {
            if (!$client) {
                $connection_id = $connection_id ? sanitize_key($connection_id) : '';
                if (!$connection_id) {
                    $parent_id = (int) wp_get_post_parent_id($variation_id);
                    $connection_id = $parent_id ? self::get_product_connection_id($parent_id) : '';
                }

                if (!$connection_id) {
                    return new WP_Error('azinsanaat_missing_connection', __('هیچ اتصال معتبری برای این متغیر یافت نشد.', 'azinsanaat-connection'));
                }

                $client = self::get_api_client($connection_id);
                if (is_wp_error($client)) {
                    return $client;
                }
            }

            $endpoint = sprintf('products/%d/variations/%d', $remote_product_id, $remote_variation_id);
            $response = $client->get($endpoint);
            if (is_wp_error($response)) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $message = $body['message'] ?? __('خطا در دریافت اطلاعات متغیر از وب‌سرویس.', 'azinsanaat-connection');

                return new WP_Error('azinsanaat_variation_sync_failed', $message);
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) {
                return new WP_Error('azinsanaat_invalid_variation', __('پاسخ نامعتبر برای متغیر دریافت شد.', 'azinsanaat-connection'));
            }

            $clean_callback = function_exists('wc_clean') ? 'wc_clean' : 'sanitize_text_field';

            if (isset($data['regular_price']) && $data['regular_price'] !== '') {
                update_post_meta($variation_id, '_regular_price', call_user_func($clean_callback, $data['regular_price']));
            } else {
                delete_post_meta($variation_id, '_regular_price');
            }

            if (isset($data['sale_price']) && $data['sale_price'] !== '') {
                update_post_meta($variation_id, '_sale_price', call_user_func($clean_callback, $data['sale_price']));
            } else {
                delete_post_meta($variation_id, '_sale_price');
            }

            if (isset($data['price']) && $data['price'] !== '') {
                update_post_meta($variation_id, '_price', call_user_func($clean_callback, $data['price']));
            } else {
                delete_post_meta($variation_id, '_price');
            }

            if (isset($data['stock_status']) && $data['stock_status'] !== '') {
                update_post_meta($variation_id, '_stock_status', sanitize_text_field($data['stock_status']));
            }

            if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null && $data['stock_quantity'] !== '') {
                update_post_meta($variation_id, '_stock', (int) $data['stock_quantity']);
                update_post_meta($variation_id, '_manage_stock', !empty($data['manage_stock']) ? 'yes' : 'no');
            } else {
                update_post_meta($variation_id, '_manage_stock', 'no');
                delete_post_meta($variation_id, '_stock');
            }

            update_post_meta($variation_id, self::META_LAST_SYNC, current_time('timestamp'));

            $parent_id = (int) wp_get_post_parent_id($variation_id);
            if ($parent_id && function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($parent_id);
            }

            return true;
        }

        protected static function fetch_remote_variations($client, int $remote_product_id)
        {
            $page = 1;
            $per_page = 50;
            $variations = [];

            do {
                $response = $client->get(sprintf('products/%d/variations', $remote_product_id), [
                    'per_page' => $per_page,
                    'page'     => $page,
                ]);

                if (is_wp_error($response)) {
                    return $response;
                }

                $status = wp_remote_retrieve_response_code($response);
                if ($status < 200 || $status >= 300) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $message = $body['message'] ?? __('خطا در دریافت متغیرهای محصول.', 'azinsanaat-connection');

                    return new WP_Error('azinsanaat_variations_request_failed', $message);
                }

                $batch = json_decode(wp_remote_retrieve_body($response), true);
                if (!is_array($batch)) {
                    return new WP_Error('azinsanaat_invalid_variations_response', __('پاسخ نامعتبر از متغیرها دریافت شد.', 'azinsanaat-connection'));
                }

                if (!empty($batch)) {
                    $variations = array_merge($variations, $batch);
                }

                $page++;
            } while (!empty($batch) && count($batch) === $per_page);

            return $variations;
        }

        protected static function get_local_variations_for_product(int $product_id): array
        {
            if (!function_exists('wc_get_product')) {
                return [];
            }

            $variation_ids = get_posts([
                'post_type'      => 'product_variation',
                'post_parent'    => $product_id,
                'post_status'    => ['publish', 'private', 'draft'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);

            $variations = [];
            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }

                $variations[] = [
                    'id'        => $variation_id,
                    'name'      => $variation->get_formatted_name(),
                    'remote_id' => (int) get_post_meta($variation_id, self::META_REMOTE_ID, true),
                    'connection_id' => sanitize_key((string) get_post_meta($variation_id, self::META_REMOTE_CONNECTION, true)),
                ];
            }

            return $variations;
        }

        protected static function format_variation_attributes(array $attributes): string
        {
            $parts = [];
            foreach ($attributes as $attribute) {
                $name = $attribute['name'] ?? '';
                $value = $attribute['option'] ?? ($attribute['value'] ?? '');

                if ($name || $value) {
                    if ($name && $value) {
                        $parts[] = sprintf('%s: %s', $name, $value);
                    } elseif ($value) {
                        $parts[] = $value;
                    } elseif ($name) {
                        $parts[] = $name;
                    }
                }
            }

            return implode(' | ', $parts);
        }

        protected static function get_formatted_sync_time(int $post_id): string
        {
            $timestamp = (int) get_post_meta($post_id, self::META_LAST_SYNC, true);
            if (!$timestamp) {
                return '';
            }

            $format = get_option('date_format') . ' - ' . get_option('time_format');

            return function_exists('wp_date') ? wp_date($format, $timestamp) : date_i18n($format, $timestamp);
        }

        protected static function get_connected_variations_count(int $product_id): int
        {
            $query = new WP_Query([
                'post_type'      => 'product_variation',
                'post_parent'    => $product_id,
                'post_status'    => ['publish', 'private', 'draft'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => self::META_REMOTE_ID,
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);

            $count = (int) $query->found_posts;
            wp_reset_postdata();

            return $count;
        }

        protected static function get_connected_products_page_url(): string
        {
            return admin_url('admin.php?page=azinsanaat-connection-linked-products');
        }

        public static function run_scheduled_sync(?string $connection_id = null): void
        {
            $connection_id = $connection_id ? sanitize_key($connection_id) : '';

            $meta_query = [
                [
                    'key'     => self::META_REMOTE_ID,
                    'compare' => 'EXISTS',
                ],
            ];

            if ($connection_id) {
                $meta_query[] = [
                    'key'   => self::META_REMOTE_CONNECTION,
                    'value' => $connection_id,
                ];
            }

            $products = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'meta_query'     => $meta_query,
            ]);

            if (empty($products)) {
                return;
            }

            foreach ($products as $product_id) {
                $remote_id = (int) get_post_meta($product_id, self::META_REMOTE_ID, true);
                if (!$remote_id) {
                    continue;
                }

                $product_connection_id = $connection_id ?: self::get_product_connection_id($product_id);
                if (!$product_connection_id) {
                    continue;
                }

                if ($connection_id && $product_connection_id !== $connection_id) {
                    continue;
                }

                $result = self::sync_product_with_remote($product_id, $remote_id, $product_connection_id);

                if (is_wp_error($result)) {
                    error_log(sprintf('Azinsanaat Connection: sync failed for product #%1$d - %2$s', $product_id, $result->get_error_message()));
                }
            }
        }

        public static function register_cron_schedules(array $schedules): array
        {
            foreach (self::get_sync_intervals() as $key => $interval) {
                $schedules['azinsanaat_' . $key] = [
                    'interval' => $interval['interval'],
                    'display'  => $interval['label'],
                ];
            }

            return $schedules;
        }

        public static function ensure_cron_schedule(): void
        {
            self::clear_legacy_cron_events();
            self::schedule_events_for_connections();
        }

        protected static function schedule_events_for_connections(?array $connections = null): void
        {
            if ($connections === null) {
                $options = self::get_plugin_options();
                $connections = $options['connections'];
            }

            if (empty($connections) || !is_array($connections)) {
                return;
            }

            foreach ($connections as $connection) {
                if (!is_array($connection) || empty($connection['id'])) {
                    continue;
                }

                $interval_key = isset($connection['sync_interval']) ? (string) $connection['sync_interval'] : '';
                self::schedule_connection_event($connection['id'], $interval_key);
            }
        }

        protected static function schedule_connection_event(string $connection_id, string $interval_key): void
        {
            $intervals = self::get_sync_intervals();
            $interval_key = $interval_key ? self::sanitize_sync_interval($interval_key) : '15min';

            if (!isset($intervals[$interval_key])) {
                $interval_key = '15min';
            }

            $recurrence = 'azinsanaat_' . $interval_key;
            $hook_args = [$connection_id];

            if (!wp_next_scheduled(self::CRON_HOOK, $hook_args)) {
                wp_schedule_event(time() + $intervals[$interval_key]['interval'], $recurrence, self::CRON_HOOK, $hook_args);
            }
        }

        protected static function clear_scheduled_event(): void
        {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        protected static function clear_legacy_cron_events(): void
        {
            wp_clear_scheduled_hook(self::CRON_HOOK, []);
        }

        public static function handle_options_updated($old_value, $value, $option): void
        {
            self::clear_scheduled_event();
            $connections = is_array($value) && isset($value['connections']) && is_array($value['connections'])
                ? array_values($value['connections'])
                : [];
            self::schedule_events_for_connections($connections);
        }

        public static function activate(): void
        {
            self::ensure_plugin_capability();
            self::clear_scheduled_event();
            self::schedule_events_for_connections();
        }

        public static function deactivate(): void
        {
            self::clear_scheduled_event();
        }

        /**
         * Retrieves an API client instance.
         */
        protected static function get_api_client(?string $connection_id = null)
        {
            $connection = self::get_connection_or_default($connection_id);
            if (!$connection) {
                return new WP_Error('azinsanaat_missing_credentials', __('ابتدا تنظیمات اتصال را تکمیل کنید.', 'azinsanaat-connection'));
            }

            return new class($connection) {
                private $connection;

                public function __construct(array $connection)
                {
                    $this->connection = $connection;
                }

                public function get(string $endpoint, array $params = [])
                {
                    $url = $this->build_url($endpoint, $params);
                    $response = wp_remote_get($url, [
                        'timeout' => 30,
                        'headers' => [
                            'Accept' => 'application/json',
                        ],
                    ]);

                    if (is_wp_error($response)) {
                        return $response;
                    }

                    $status_code = wp_remote_retrieve_response_code($response);
                    if ($status_code === 401) {
                        return new WP_Error(
                            'azinsanaat_unauthorized',
                            __('دسترسی به API امکان‌پذیر نیست. کلیدها را بررسی کنید.', 'azinsanaat-connection')
                        );
                    }

                    if ($status_code === 403) {
                        return new WP_Error(
                            'azinsanaat_forbidden',
                            __('کلیدهای API اجازه مشاهده این بخش را ندارند. در ووکامرس (ووکامرس ← تنظیمات ← پیشرفته ← REST API) سطح دسترسی را روی «خواندن» یا «خواندن/نوشتن» تنظیم کنید.', 'azinsanaat-connection')
                        );
                    }

                    return $response;
                }

                private function build_url(string $endpoint, array $params = []): string
                {
                    $base = trailingslashit($this->connection['store_url']);
                    $endpoint = ltrim($endpoint, '/');
                    $url = $base . 'wp-json/wc/v3/' . $endpoint;
                    $query_args = array_merge($params, [
                        'consumer_key'    => $this->connection['consumer_key'],
                        'consumer_secret' => $this->connection['consumer_secret'],
                    ]);

                    return add_query_arg($query_args, $url);
                }
            };
        }

        /**
         * Stores a transient admin notice message.
         */
        protected static function set_transient_message(string $key, array $message): void
        {
            set_transient($key, $message, MINUTE_IN_SECONDS);
        }

        /**
         * Retrieves a transient message and deletes it.
         */
        protected static function get_transient_message(string $key)
        {
            $message = get_transient($key);
            if ($message) {
                delete_transient($key);
            }

            return $message;
        }

        protected static function get_settings_page_url(): string
        {
            return admin_url('admin.php?page=azinsanaat-connection');
        }

        protected static function get_products_page_url(array $args = []): string
        {
            $url = admin_url('admin.php?page=azinsanaat-connection-products');

            if (empty($args)) {
                return $url;
            }

            $sanitized = [];
            foreach ($args as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $key = sanitize_key($key);
                if ($key === '') {
                    continue;
                }

                if (!is_scalar($value)) {
                    continue;
                }

                $value = (string) $value;
                if ($value === '') {
                    continue;
                }

                $sanitized[$key] = $value;
            }

            return !empty($sanitized) ? add_query_arg($sanitized, $url) : $url;
        }

        /**
         * Returns the destination URL for bulk product creation.
         */
        protected static function get_bulk_product_creation_url(string $connection_id = ''): string
        {
            $default_url = admin_url('admin.php?import=woocommerce_products_csv');

            if ($connection_id !== '') {
                $default_url = add_query_arg('connection_id', $connection_id, $default_url);
            }

            $filtered = apply_filters('azinsanaat_connection_bulk_creation_url', $default_url, $connection_id);

            return is_string($filtered) && $filtered !== '' ? $filtered : $default_url;
        }
    }
}

register_activation_hook(__FILE__, ['Azinsanaat_Connection', 'activate']);
register_deactivation_hook(__FILE__, ['Azinsanaat_Connection', 'deactivate']);

Azinsanaat_Connection::init();
