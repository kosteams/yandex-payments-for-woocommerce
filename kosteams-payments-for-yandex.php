<?php

/**
 * Plugin Name: KosTeams Payments for Яндекс
 * Plugin URI: https://kosteams.com
 * Description: Принимайте оплату через Yandex Pay, Яндекс Сплит или их комбинацию. Модульная архитектура для легкой поддержки и расширения.
 * Version: 2.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0.0
 * Author: KosTeams
 * Author URI: https://t.me/koSteams
 * Text Domain: kosteams-payments-for-yandex
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 *
 * @package KosTeams\YandexPayments
 */

namespace KosTeams\YandexPayments;

// Защита от прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

// Определение констант плагина
define('KOSTEAMS_YANDEX_VERSION', '2.0.0');
define('KOSTEAMS_YANDEX_FILE', __FILE__);
define('KOSTEAMS_YANDEX_PATH', plugin_dir_path(__FILE__));
define('KOSTEAMS_YANDEX_URL', plugin_dir_url(__FILE__));
define('KOSTEAMS_YANDEX_BASENAME', plugin_basename(__FILE__));

/**
 * Загрузка зависимостей
 * 
 * Приоритет:
 * 1. Composer автозагрузчик (если установлен)
 * 2. Fallback на встроенный автозагрузчик PSR-4
 */
$composer_autoload = KOSTEAMS_YANDEX_PATH . 'vendor/autoload.php';

if (file_exists($composer_autoload)) {
    // Используем Composer автозагрузчик
    require_once $composer_autoload;
} else {
    // Fallback: встроенный автозагрузчик PSR-4
    spl_autoload_register(function ($class) {
        // Проверяем, что класс относится к нашему namespace
        $namespace = 'KosTeams\\YandexPayments\\';
        $base_dir = KOSTEAMS_YANDEX_PATH . 'includes/';

        // Проверка namespace
        $len = strlen($namespace);
        if (strncmp($namespace, $class, $len) !== 0) {
            return;
        }

        // Получаем относительное имя класса
        $relative_class = substr($class, $len);

        // Заменяем namespace разделители на разделители директорий
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        // Если файл существует, подключаем его
        if (file_exists($file)) {
            require_once $file;
        }
    });

    // Если нет Composer, но нужен Firebase JWT, выводим предупреждение
    add_action('admin_notices', function () {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>' . __('KosTeams Яндекс Платежи:', 'kosteams-payments-for-yandex') . '</strong> ';
            echo __('Для полной функциональности требуется установка зависимостей через Composer. Выполните команду: composer install', 'kosteams-payments-for-yandex');
            echo '</p></div>';
        }
    });
}

/**
 * Главная функция инициализации плагина
 * 
 * Создает и запускает единственный экземпляр плагина
 * 
 * @return Core\Plugin
 */
function kosteams_yandex_payments(): Core\Plugin {
    return Core\Plugin::getInstance(KOSTEAMS_YANDEX_FILE);
}

/**
 * Проверка зависимостей перед инициализацией
 * 
 * Проверяет наличие необходимых плагинов и версий PHP
 */
function kosteams_yandex_check_requirements(): bool {
    $errors = [];

    // Проверка версии PHP
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        $errors[] = sprintf(
            __('KosTeams Payments требует PHP версии 8.0.0 или выше. Текущая версия: %s', 'kosteams-payments-for-yandex'),
            PHP_VERSION
        );
    }

    // Проверка наличия WooCommerce
    if (!class_exists('WooCommerce')) {
        $errors[] = __('KosTeams Payments требует установки и активации WooCommerce', 'kosteams-payments-for-yandex');
    } else {
        // Проверка версии WooCommerce
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '6.0', '<')) {
            $errors[] = sprintf(
                __('KosTeams Payments требует WooCommerce версии 6.0 или выше. Текущая версия: %s', 'kosteams-payments-for-yandex'),
                WC_VERSION
            );
        }
    }

    // Если есть ошибки, выводим их
    if (!empty($errors)) {
        add_action('admin_notices', function () use ($errors) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . __('KosTeams Payments for Яндекс', 'kosteams-payments-for-yandex') . '</strong></p>';
            foreach ($errors as $error) {
                echo '<p>' . esc_html($error) . '</p>';
            }
            echo '</div>';
        });

        return false;
    }

    return true;
}

/**
 * Функция для удаления плагина
 */
function kosteams_yandex_uninstall() {
    // Проверяем, загружен ли класс Plugin
    if (!class_exists(__NAMESPACE__ . '\\Core\\Plugin')) {
        // Пытаемся загрузить класс вручную
        $plugin_file = KOSTEAMS_YANDEX_PATH . 'includes/Core/Plugin.php';
        if (file_exists($plugin_file)) {
            require_once $plugin_file;
        } else {
            // Если файла нет, то не можем выполнить удаление
            return;
        }
    }
    // Вызываем статический метод uninstall
    Core\Plugin::uninstall();
}

/**
 * Инициализация плагина
 * 
 * Запускается после загрузки всех плагинов
 */
add_action('plugins_loaded', function () {
    // Проверяем зависимости
    if (!kosteams_yandex_check_requirements()) {
        return;
    }

    // Инициализируем плагин
    kosteams_yandex_payments()->init();
}, 10);

/**
 * Хук активации плагина
 * 
 * Выполняется при активации плагина
 */
register_activation_hook(KOSTEAMS_YANDEX_FILE, function () {
    // Проверяем зависимости при активации
    if (!kosteams_yandex_check_requirements()) {
        deactivate_plugins(KOSTEAMS_YANDEX_BASENAME);
        wp_die(
            __('Плагин не может быть активирован. Пожалуйста, проверьте требования.', 'kosteams-payments-for-yandex'),
            __('Ошибка активации', 'kosteams-payments-for-yandex'),
            ['back_link' => true]
        );
    }

    // Запускаем активацию
    kosteams_yandex_payments()->activate();
});

/**
 * Хук деактивации плагина
 * 
 * Выполняется при деактивации плагина
 */
register_deactivation_hook(KOSTEAMS_YANDEX_FILE, function () {
    kosteams_yandex_payments()->deactivate();
});

/**
 * Хук удаления плагина
 * 
 * Выполняется при удалении плагина
 */
register_uninstall_hook(KOSTEAMS_YANDEX_FILE, __NAMESPACE__ . '\\kosteams_yandex_uninstall');

/**
 * Глобальная функция для доступа к контейнеру
 * 
 * Может использоваться для расширения функциональности плагина
 * 
 * @param string $service_id ID сервиса
 * @return mixed
 */
function kosteams_yandex_get_service(string $service_id) {
    return kosteams_yandex_payments()->getContainer()->get($service_id);
}

/**
 * Добавление ссылок на странице плагинов
 */
add_filter('plugin_action_links_' . KOSTEAMS_YANDEX_BASENAME, function ($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-pay'),
        __('Настройки', 'kosteams-payments-for-yandex')
    );

    $pro_link = sprintf(
        '<a href="%s" target="_blank" style="color:#ff7b00;font-weight:bold;">%s</a>',
        'https://kosteams.com',
        __('Pro версия', 'kosteams-payments-for-yandex')
    );

    array_unshift($links, $settings_link);

    // Добавляем ссылку на Pro версию, если она не установлена
    if (!is_plugin_active('kosteams-payments-for-yandex-pro/kosteams-payments-for-yandex-pro.php')) {
        array_unshift($links, $pro_link);
    }

    return $links;
});

/**
 * Добавление мета-ссылки на странице плагинов
 */
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file !== KOSTEAMS_YANDEX_BASENAME) {
        return $links;
    }

    $row_meta = [
        'docs' => sprintf(
            '<a href="%s" target="_blank">%s</a>',
            'https://kosteams.com/docs',
            __('Документация', 'kosteams-payments-for-yandex')
        ),
        'support' => sprintf(
            '<a href="%s" target="_blank">%s</a>',
            'https://t.me/koSteams',
            __('Поддержка', 'kosteams-payments-for-yandex')
        ),
        'rate' => sprintf(
            '<a href="%s" target="_blank">%s</a>',
            'https://wordpress.org/support/plugin/kosteams-payments-for-yandex/reviews/?filter=5',
            __('Оценить плагин', 'kosteams-payments-for-yandex')
        )
    ];

    return array_merge($links, $row_meta);
}, 10, 2);
