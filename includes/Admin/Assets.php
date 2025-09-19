<?php

namespace KosTeams\YandexPayments\Admin;

/**
 * Менеджер ресурсов (CSS/JS)
 * 
 * Управляет подключением стилей и скриптов плагина
 * в административной панели и на фронтенде
 * 
 * @package KosTeams\YandexPayments\Admin
 */
class Assets {
    
    /**
     * URL к директории плагина
     * 
     * @var string
     */
    private string $plugin_url;
    
    /**
     * Версия ресурсов (для кэширования)
     * 
     * @var string
     */
    private string $version;
    
    /**
     * Режим отладки
     * 
     * @var bool
     */
    private bool $debug_mode;
    
    /**
     * Конструктор
     * 
     * @param string $plugin_url URL плагина
     */
    public function __construct(string $plugin_url) {
        $this->plugin_url = $plugin_url;
        $this->version = KOSTEAMS_YANDEX_VERSION;
        $this->debug_mode = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG;
    }
    
    /**
     * Подключить ресурсы для админки
     * 
     * @param string $hook_suffix Суффикс хука страницы
     */
    public function enqueueAdminAssets(string $hook_suffix): void {
        // Проверяем, нужно ли подключать ресурсы на этой странице
        if (!$this->shouldEnqueueAdmin($hook_suffix)) {
            return;
        }
        
        // Подключаем стили админки
        $this->enqueueAdminStyles();
        
        // Подключаем скрипты админки
        $this->enqueueAdminScripts();
        
        // Локализация скриптов
        $this->localizeAdminScripts();
    }
    
    /**
     * Подключить ресурсы для фронтенда
     */
    public function enqueueFrontendAssets(): void {
        // Проверяем, нужно ли подключать ресурсы
        if (!$this->shouldEnqueueFrontend()) {
            return;
        }
        
        // Подключаем стили фронтенда
        $this->enqueueFrontendStyles();
        
        // Подключаем скрипты фронтенда
        $this->enqueueFrontendScripts();
        
        // Локализация скриптов
        $this->localizeFrontendScripts();
    }
    
    /**
     * Проверить, нужно ли подключать ресурсы в админке
     * 
     * @param string $hook_suffix
     * @return bool
     */
    private function shouldEnqueueAdmin(string $hook_suffix): bool {
        // Страницы настроек WooCommerce
        if ($hook_suffix === 'woocommerce_page_wc-settings') {
            $tab = $_GET['tab'] ?? '';
            $section = $_GET['section'] ?? '';
            
            if ($tab === 'checkout' && strpos($section, 'kosteams-yandex') === 0) {
                return true;
            }
        }
        
        // Страницы плагина
        if (strpos($hook_suffix, 'kosteams-yandex') !== false) {
            return true;
        }
        
        // Страницы заказов
        if (in_array($hook_suffix, ['shop_order', 'edit.php', 'post.php'])) {
            $post_type = $_GET['post_type'] ?? get_post_type();
            if ($post_type === 'shop_order') {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Проверить, нужно ли подключать ресурсы на фронтенде
     * 
     * @return bool
     */
    private function shouldEnqueueFrontend(): bool {
        // Страница оформления заказа
        if (is_checkout()) {
            return true;
        }
        
        // Страница оплаты заказа
        if (is_checkout_pay_page()) {
            return true;
        }
        
        // Каталог товаров (для бейджей)
        if (is_shop() || is_product_category() || is_product_tag()) {
            return true;
        }
        
        // Страница товара (для калькулятора рассрочки)
        if (is_product()) {
            return true;
        }
        
        // Страница корзины (для предварительного расчета)
        if (is_cart()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Подключить стили админки
     */
    private function enqueueAdminStyles(): void {
        $suffix = $this->debug_mode ? '' : '.min';
        
        // Основные стили админки
        wp_enqueue_style(
            'kosteams-yandex-admin',
            $this->plugin_url . 'assets/css/admin' . $suffix . '.css',
            ['woocommerce_admin_styles'],
            $this->version
        );
        
        // Стили для настроек
        wp_enqueue_style(
            'kosteams-yandex-settings',
            $this->plugin_url . 'assets/css/settings' . $suffix . '.css',
            ['kosteams-yandex-admin'],
            $this->version
        );
        
        // Добавляем inline стили для кастомизации
        $this->addInlineAdminStyles();
    }
    
    /**
     * Подключить скрипты админки
     */
    private function enqueueAdminScripts(): void {
        $suffix = $this->debug_mode ? '' : '.min';
        
        // jQuery и зависимости
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-tabs');
        wp_enqueue_script('jquery-ui-dialog');
        
        // Основной скрипт админки
        wp_enqueue_script(
            'kosteams-yandex-admin',
            $this->plugin_url . 'assets/js/admin' . $suffix . '.js',
            ['jquery', 'wc-enhanced-select'],
            $this->version,
            true
        );
        
        // Скрипт для настроек
        wp_enqueue_script(
            'kosteams-yandex-settings',
            $this->plugin_url . 'assets/js/settings' . $suffix . '.js',
            ['kosteams-yandex-admin'],
            $this->version,
            true
        );
        
        // Chart.js для статистики
        if ($this->isStatsPage()) {
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
                [],
                '3.9.1',
                true
            );
            
            wp_enqueue_script(
                'kosteams-yandex-stats',
                $this->plugin_url . 'assets/js/stats' . $suffix . '.js',
                ['chartjs'],
                $this->version,
                true
            );
        }
    }
    
    /**
     * Локализация скриптов админки
     */
    private function localizeAdminScripts(): void {
        wp_localize_script('kosteams-yandex-admin', 'kosteamsYandexAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kosteams_yandex_admin'),
            'strings' => [
                'confirm_reset' => __('Вы уверены, что хотите сбросить настройки?', 'kosteams-payments-for-yandex'),
                'test_success' => __('Тестовое соединение успешно!', 'kosteams-payments-for-yandex'),
                'test_failed' => __('Ошибка соединения. Проверьте настройки.', 'kosteams-payments-for-yandex'),
                'saving' => __('Сохранение...', 'kosteams-payments-for-yandex'),
                'saved' => __('Сохранено', 'kosteams-payments-for-yandex'),
                'error' => __('Произошла ошибка', 'kosteams-payments-for-yandex')
            ],
            'settings' => [
                'test_mode' => get_option('kosteams_yandex_test_mode', false),
                'debug_mode' => get_option('kosteams_yandex_debug_mode', false)
            ]
        ]);
    }
    
    /**
     * Подключить стили фронтенда
     */
    private function enqueueFrontendStyles(): void {
        $suffix = $this->debug_mode ? '' : '.min';
        
        // Основные стили фронтенда
        wp_enqueue_style(
            'kosteams-yandex-frontend',
            $this->plugin_url . 'assets/css/frontend' . $suffix . '.css',
            [],
            $this->version
        );
        
        // Стили для checkout
        if (is_checkout() || is_checkout_pay_page()) {
            wp_enqueue_style(
                'kosteams-yandex-checkout',
                $this->plugin_url . 'assets/css/checkout' . $suffix . '.css',
                ['kosteams-yandex-frontend'],
                $this->version
            );
        }
        
        // Добавляем inline стили
        $this->addInlineFrontendStyles();
    }
    
    /**
     * Подключить скрипты фронтенда
     */
    private function enqueueFrontendScripts(): void {
        $suffix = $this->debug_mode ? '' : '.min';
        
        // Основной скрипт фронтенда
        wp_enqueue_script(
            'kosteams-yandex-frontend',
            $this->plugin_url . 'assets/js/frontend' . $suffix . '.js',
            ['jquery'],
            $this->version,
            true
        );
        
        // Скрипт для checkout
        if (is_checkout() || is_checkout_pay_page()) {
            wp_enqueue_script(
                'kosteams-yandex-checkout',
                $this->plugin_url . 'assets/js/checkout' . $suffix . '.js',
                ['kosteams-yandex-frontend', 'wc-checkout'],
                $this->version,
                true
            );
            
            // SDK Яндекс Pay (если включен)
            if ($this->isYandexPayEnabled()) {
                wp_enqueue_script(
                    'yandex-pay-sdk',
                    'https://pay.yandex.ru/sdk/v1/pay.js',
                    [],
                    null,
                    true
                );
            }
        }
        
        // Калькулятор рассрочки
        if (is_product() && $this->isYandexSplitEnabled()) {
            wp_enqueue_script(
                'kosteams-yandex-split-calculator',
                $this->plugin_url . 'assets/js/split-calculator' . $suffix . '.js',
                ['kosteams-yandex-frontend'],
                $this->version,
                true
            );
        }
    }
    
    /**
     * Локализация скриптов фронтенда
     */
    private function localizeFrontendScripts(): void {
        $localize_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'checkout_url' => wc_get_checkout_url(),
            'nonce' => wp_create_nonce('kosteams_yandex_frontend'),
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'strings' => [
                'loading' => __('Загрузка...', 'kosteams-payments-for-yandex'),
                'error' => __('Произошла ошибка', 'kosteams-payments-for-yandex'),
                'split_available' => __('Доступна рассрочка', 'kosteams-payments-for-yandex'),
                'split_from' => __('от', 'kosteams-payments-for-yandex'),
                'per_month' => __('в месяц', 'kosteams-payments-for-yandex')
            ]
        ];
        
        // Добавляем настройки Яндекс Pay
        if ($this->isYandexPayEnabled()) {
            $localize_data['yandex_pay'] = [
                'merchant_id' => get_option('kosteams_yandex_merchant_id'),
                'button_style' => get_option('kosteams_yandex_button_style', 'black'),
                'test_mode' => get_option('kosteams_yandex_test_mode', false)
            ];
        }
        
        // Добавляем настройки Яндекс Сплит
        if ($this->isYandexSplitEnabled()) {
            $localize_data['yandex_split'] = [
                'min_amount' => get_option('kosteams_yandex_min_split_amount', 3000),
                'max_amount' => get_option('kosteams_yandex_max_split_amount', 150000),
                'periods' => get_option('kosteams_yandex_split_periods', [2, 4])
            ];
        }
        
        wp_localize_script('kosteams-yandex-frontend', 'kosteamsYandex', $localize_data);
    }
    
    /**
     * Добавить inline стили для админки
     */
    private function addInlineAdminStyles(): void {
        $custom_css = "
            .kosteams-yandex-admin .dashboard-widget {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .kosteams-yandex-admin .status-ok { color: #00a32a; }
            .kosteams-yandex-admin .status-error { color: #d63638; }
            .kosteams-yandex-admin .status-warning { color: #dba617; }
            
            .kosteams-promo-block {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                padding: 30px;
                border-radius: 8px;
                margin-top: 30px;
            }
        ";
        
        wp_add_inline_style('kosteams-yandex-admin', $custom_css);
    }
    
    /**
     * Добавить inline стили для фронтенда
     */
    private function addInlineFrontendStyles(): void {
        $button_style = get_option('kosteams_yandex_button_style', 'black');
        
        $custom_css = "
            .yandex-pay-button {
                background: " . ($button_style === 'black' ? '#000' : '#fff') . ";
                color: " . ($button_style === 'black' ? '#fff' : '#000') . ";
            }
            
            .yandex-split-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 4px 8px;
                background: #ffe4b5;
                border-radius: 4px;
                font-size: 12px;
            }
        ";
        
        wp_add_inline_style('kosteams-yandex-frontend', $custom_css);
    }
    
    /**
     * Проверить, включен ли Яндекс Pay
     * 
     * @return bool
     */
    private function isYandexPayEnabled(): bool {
        $settings = get_option('woocommerce_kosteams-yandex-pay_settings', []);
        return isset($settings['enabled']) && $settings['enabled'] === 'yes';
    }
    
    /**
     * Проверить, включен ли Яндекс Сплит
     * 
     * @return bool
     */
    private function isYandexSplitEnabled(): bool {
        $settings = get_option('woocommerce_kosteams-yandex-split_settings', []);
        return isset($settings['enabled']) && $settings['enabled'] === 'yes';
    }
    
    /**
     * Проверить, находимся ли на странице статистики
     * 
     * @return bool
     */
    private function isStatsPage(): bool {
        return isset($_GET['page']) && $_GET['page'] === 'kosteams-yandex-stats';
    }
    
    /**
     * Получить URL к ресурсу
     * 
     * @param string $path Путь к файлу относительно директории assets
     * @return string
     */
    public function getAssetUrl(string $path): string {
        return $this->plugin_url . 'assets/' . ltrim($path, '/');
    }
    
    /**
     * Предзагрузить критические ресурсы
     */
    public function preloadCriticalAssets(): void {
        if (!is_checkout()) {
            return;
        }
        
        // Предзагружаем критические стили
        echo '<link rel="preload" href="' . esc_url($this->getAssetUrl('css/checkout.min.css')) . 
             '" as="style">' . PHP_EOL;
        
        // Предзагружаем SDK Яндекс Pay
        if ($this->isYandexPayEnabled()) {
            echo '<link rel="preconnect" href="https://pay.yandex.ru">' . PHP_EOL;
            echo '<link rel="dns-prefetch" href="https://pay.yandex.ru">' . PHP_EOL;
        }
    }
}