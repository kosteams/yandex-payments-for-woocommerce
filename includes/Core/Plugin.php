<?php

namespace KosTeams\YandexPayments\Core;

/**
 * Главный класс плагина
 * 
 * Отвечает за инициализацию всех компонентов плагина
 * и координацию их работы
 * 
 * @package KosTeams\YandexPayments\Core
 */
class Plugin {

    /**
     * Версия плагина
     * 
     * @var string
     */
    const VERSION = '2.0.0';

    /**
     * Уникальный идентификатор плагина
     * 
     * @var string
     */
    const PLUGIN_ID = 'kosteams-payments-for-yandex';

    /**
     * DI контейнер для управления зависимостями
     * 
     * @var Container
     */
    private Container $container;

    /**
     * Загрузчик хуков и фильтров
     * 
     * @var Loader
     */
    private Loader $loader;

    /**
     * Единственный экземпляр класса (Singleton)
     * 
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Путь к главному файлу плагина
     * 
     * @var string
     */
    private string $plugin_file;

    /**
     * Конструктор класса (приватный для Singleton)
     * 
     * @param string $plugin_file Путь к главному файлу плагина
     */
    private function __construct(string $plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->container = new Container();
        $this->loader = new Loader();
    }

    /**
     * Получить единственный экземпляр плагина
     * 
     * @param string $plugin_file Путь к главному файлу плагина
     * @return Plugin
     */
    public static function getInstance(string $plugin_file = ''): Plugin {
        if (self::$instance === null) {
            self::$instance = new self($plugin_file);
        }
        return self::$instance;
    }

    /**
     * Инициализация плагина
     * 
     * Запускает все необходимые компоненты в правильном порядке
     */
    public function init(): void {
        // Регистрация сервисов в контейнере
        $this->registerServices();

        // Инициализация компонентов
        $this->initCore();
        $this->initAPI();
        $this->initGateways();
        $this->initAdmin();

        // Регистрация хуков
        $this->registerHooks();

        // Запуск загрузчика
        $this->loader->run();
    }

    /**
     * Регистрация всех сервисов в DI контейнере
     * 
     * Здесь определяются все зависимости и способы их создания
     */
    private function registerServices(): void {
        // Регистрация логгера
        $this->container->singleton('logger', function () {
            return new \KosTeams\YandexPayments\Utils\Logger();
        });

        // Регистрация API клиента
        $this->container->singleton('api_client', function ($container) {
            return new \KosTeams\YandexPayments\API\YandexApiClient(
                $container->get('logger')
            );
        });

        // Регистрация обработчика платежей
        $this->container->singleton('payment_processor', function ($container) {
            return new \KosTeams\YandexPayments\Payment\PaymentProcessor(
                $container->get('api_client'),
                $container->get('order_manager'),
                $container->get('cart_calculator'),
                $container->get('logger')
            );
        });

        // Регистрация менеджера заказов
        $this->container->singleton('order_manager', function ($container) {
            return new \KosTeams\YandexPayments\Payment\OrderManager(
                $container->get('logger')
            );
        });

        // Регистрация калькулятора корзины
        $this->container->singleton('cart_calculator', function ($container) {
            return new \KosTeams\YandexPayments\Payment\CartCalculator(
                $container->get('discount_calculator')
            );
        });

        // Регистрация калькулятора скидок
        $this->container->singleton('discount_calculator', function () {
            return new \KosTeams\YandexPayments\Payment\DiscountCalculator();
        });

        // Регистрация обработчика вебхуков
        $this->container->singleton('webhook_handler', function ($container) {
            return new \KosTeams\YandexPayments\API\WebhookHandler(
                $container->get('order_manager'),
                $container->get('logger')
            );
        });

        // Регистрация менеджера настроек
        $this->container->singleton('settings', function () {
            return new \KosTeams\YandexPayments\Admin\Settings();
        });

        // Регистрация менеджера ресурсов
        $this->container->singleton('assets', function () {
            return new \KosTeams\YandexPayments\Admin\Assets($this->getPluginUrl());
        });
    }

    /**
     * Инициализация основных компонентов
     */
    private function initCore(): void {
        // Загрузка текстового домена для локализации
        $this->loader->addAction('plugins_loaded', $this, 'loadTextDomain');

        // Проверка зависимостей
        $this->loader->addAction('admin_init', $this, 'checkDependencies');
    }

    /**
     * Инициализация API компонентов
     */
    private function initAPI(): void {
        $webhook_handler = $this->container->get('webhook_handler');

        // Регистрация маршрута для вебхуков
        $this->loader->addAction(
            'woocommerce_api_kosteams-payments-for-yandex',
            $webhook_handler,
            'handle'
        );
    }

    /**
     * Инициализация платежных шлюзов
     */
    private function initGateways(): void {
        // Проверяем, что WooCommerce активен
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Регистрация платежных шлюзов
        $this->loader->addFilter('woocommerce_payment_gateways', $this, 'registerGateways');
    }

    /**
     * Инициализация административной части
     */
    private function initAdmin(): void {
        if (!is_admin()) {
            return;
        }

        $settings = $this->container->get('settings');
        $assets = $this->container->get('assets');

        // Регистрация меню
        $menu = new \KosTeams\YandexPayments\Admin\Menu($settings);
        $this->loader->addAction('admin_menu', $menu, 'register');

        // Подключение стилей и скриптов админки
        $this->loader->addAction('admin_enqueue_scripts', $assets, 'enqueueAdminAssets');

        // Ссылки на странице плагинов
        $plugin_basename = plugin_basename($this->plugin_file);
        $this->loader->addFilter(
            "plugin_action_links_{$plugin_basename}",
            $this,
            'addActionLinks'
        );
    }

    /**
     * Регистрация хуков активации/деактивации
     */
    private function registerHooks(): void {
        register_activation_hook($this->plugin_file, [$this, 'activate']);
        register_deactivation_hook($this->plugin_file, [$this, 'deactivate']);
        register_uninstall_hook($this->plugin_file, [__CLASS__, 'uninstall']);
    }

    /**
     * Регистрация платежных шлюзов WooCommerce
     * 
     * @param array $gateways Существующие шлюзы
     * @return array
     */
    public function registerGateways(array $gateways): array {
        // Передаем контейнер в шлюзы для доступа к сервисам
        $gateways[] = function () {
            return new \KosTeams\YandexPayments\Gateways\YandexPayGateway($this->container);
        };

        $gateways[] = function () {
            return new \KosTeams\YandexPayments\Gateways\YandexSplitGateway($this->container);
        };

        return $gateways;
    }

    /**
     * Загрузка текстового домена для локализации
     */
    public function loadTextDomain(): void {
        load_plugin_textdomain(
            self::PLUGIN_ID,
            false,
            dirname(plugin_basename($this->plugin_file)) . '/languages'
        );
    }

    /**
     * Проверка зависимостей плагина
     */
    public function checkDependencies(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__(
                    'Плагин KosTeams Payments требует установки WooCommerce',
                    'kosteams-payments-for-yandex'
                );
                echo '</p></div>';
            });
        }
    }

    /**
     * Добавление ссылок на странице плагинов
     * 
     * @param array $links Существующие ссылки
     * @return array
     */
    public function addActionLinks(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-pay'),
            __('Настройки', 'kosteams-payments-for-yandex')
        );

        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Действия при активации плагина
     */
    public function activate(): void {
        // Создание необходимых таблиц
        $this->createTables();

        // Установка опций по умолчанию
        $this->setDefaultOptions();

        // Очистка правил перезаписи
        flush_rewrite_rules();

        // Запись версии плагина
        update_option(self::PLUGIN_ID . '_version', self::VERSION);
    }

    /**
     * Действия при деактивации плагина
     */
    public function deactivate(): void {
        // Очистка временных данных
        $this->clearTransients();

        // Очистка правил перезаписи
        flush_rewrite_rules();
    }

    /**
     * Действия при удалении плагина
     */
    public static function uninstall(): void {
        // Удаление настроек
        delete_option('woocommerce_kosteams-yandex-pay_settings');
        delete_option('woocommerce_kosteams-yandex-split_settings');
        delete_option(self::PLUGIN_ID . '_version');

        // Удаление мета-данных заказов
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'yandex_payment_urls'");
    }

    /**
     * Создание необходимых таблиц в БД
     */
    private function createTables(): void {
        // Здесь можно создать кастомные таблицы при необходимости
    }

    /**
     * Установка опций по умолчанию
     */
    private function setDefaultOptions(): void {
        // Установка опций по умолчанию при первой активации
        if (!get_option(self::PLUGIN_ID . '_version')) {
            // Настройки по умолчанию
        }
    }

    /**
     * Очистка временных данных
     */
    private function clearTransients(): void {
        // Удаление transients плагина
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_kosteams_yandex_%' 
             OR option_name LIKE '_transient_timeout_kosteams_yandex_%'"
        );
    }

    /**
     * Получить URL плагина
     * 
     * @return string
     */
    public function getPluginUrl(): string {
        return plugin_dir_url($this->plugin_file);
    }

    /**
     * Получить путь к плагину
     * 
     * @return string
     */
    public function getPluginPath(): string {
        return plugin_dir_path($this->plugin_file);
    }

    /**
     * Получить контейнер зависимостей
     * 
     * @return Container
     */
    public function getContainer(): Container {
        return $this->container;
    }
}
