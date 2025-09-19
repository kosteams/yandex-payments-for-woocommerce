<?php

/**
 * Глобальные функции плагина
 * 
 * Вспомогательные функции, доступные во всем плагине
 * 
 * @package KosTeams\YandexPayments
 */

namespace KosTeams\YandexPayments;

// Защита от прямого доступа
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Получить экземпляр плагина
 * 
 * @return Core\Plugin
 */
function plugin(): Core\Plugin {
  return Core\Plugin::getInstance(KOSTEAMS_YANDEX_FILE);
}

/**
 * Получить сервис из контейнера
 * 
 * @param string $service_id ID сервиса
 * @return mixed
 */
function get_service(string $service_id) {
  return plugin()->getContainer()->get($service_id);
}

/**
 * Получить настройку плагина
 * 
 * @param string $key Ключ настройки
 * @param mixed $default Значение по умолчанию
 * @return mixed
 */
function get_setting(string $key, $default = null) {
  $settings = get_service('settings');
  return $settings->get($key, $default);
}

/**
 * Установить настройку плагина
 * 
 * @param string $key Ключ настройки
 * @param mixed $value Значение
 * @return bool
 */
function set_setting(string $key, $value): bool {
  $settings = get_service('settings');
  return $settings->set($key, $value);
}

/**
 * Получить логгер
 * 
 * @return Utils\Logger
 */
function logger(): Utils\Logger {
  return get_service('logger');
}

/**
 * Логировать сообщение
 * 
 * @param string $level Уровень важности
 * @param string $message Сообщение
 * @param array $context Контекст
 */
function log_message(string $level, string $message, array $context = []): void {
  logger()->log($level, $message, $context);
}

/**
 * Проверить, включен ли тестовый режим
 * 
 * @return bool
 */
function is_test_mode(): bool {
  return get_setting('test_mode', false) === true;
}

/**
 * Проверить, включен ли режим отладки
 * 
 * @return bool
 */
function is_debug_mode(): bool {
  return get_setting('debug_mode', false) === true;
}

/**
 * Получить URL к ресурсу плагина
 * 
 * @param string $path Путь относительно корня плагина
 * @return string
 */
function get_asset_url(string $path = ''): string {
  return KOSTEAMS_YANDEX_URL . ltrim($path, '/');
}

/**
 * Получить путь к файлу плагина
 * 
 * @param string $path Путь относительно корня плагина
 * @return string
 */
function get_plugin_path(string $path = ''): string {
  return KOSTEAMS_YANDEX_PATH . ltrim($path, '/');
}

/**
 * Получить версию плагина
 * 
 * @return string
 */
function get_plugin_version(): string {
  return KOSTEAMS_YANDEX_VERSION;
}

/**
 * Проверить, активен ли Яндекс Pay
 * 
 * @return bool
 */
function is_yandex_pay_enabled(): bool {
  $settings = get_option('woocommerce_kosteams-yandex-pay_settings', []);
  return isset($settings['enabled']) && $settings['enabled'] === 'yes';
}

/**
 * Проверить, активен ли Яндекс Сплит
 * 
 * @return bool
 */
function is_yandex_split_enabled(): bool {
  $settings = get_option('woocommerce_kosteams-yandex-split_settings', []);
  return isset($settings['enabled']) && $settings['enabled'] === 'yes';
}

/**
 * Проверить, настроен ли плагин
 * 
 * @return bool
 */
function is_plugin_configured(): bool {
  $settings = get_service('settings');
  return $settings->isConfigured();
}

/**
 * Форматировать сумму для API
 * 
 * @param float $amount Сумма
 * @param int $decimals Количество знаков после запятой
 * @return string
 */
function format_amount(float $amount, int $decimals = 2): string {
  return number_format($amount, $decimals, '.', '');
}

/**
 * Получить поддерживаемые валюты
 * 
 * @return array
 */
function get_supported_currencies(): array {
  return apply_filters('kosteams_yandex_supported_currencies', [
    'RUB' => __('Российский рубль', 'kosteams-payments-for-yandex'),
    'BYN' => __('Белорусский рубль', 'kosteams-payments-for-yandex'),
    'KZT' => __('Казахстанский тенге', 'kosteams-payments-for-yandex')
  ]);
}

/**
 * Проверить, поддерживается ли валюта
 * 
 * @param string $currency Код валюты
 * @return bool
 */
function is_currency_supported(string $currency): bool {
  $supported = array_keys(get_supported_currencies());
  return in_array($currency, $supported, true);
}

/**
 * Получить минимальную сумму для рассрочки
 * 
 * @return float
 */
function get_split_min_amount(): float {
  return (float) get_setting('min_split_amount', 3000);
}

/**
 * Получить максимальную сумму для рассрочки
 * 
 * @return float
 */
function get_split_max_amount(): float {
  return (float) get_setting('max_split_amount', 150000);
}

/**
 * Проверить, доступна ли рассрочка для суммы
 * 
 * @param float $amount Сумма
 * @return bool
 */
function is_split_available_for_amount(float $amount): bool {
  if (!is_yandex_split_enabled()) {
    return false;
  }

  $min = get_split_min_amount();
  $max = get_split_max_amount();

  return $amount >= $min && $amount <= $max;
}

/**
 * Получить доступные периоды рассрочки
 * 
 * @return array
 */
function get_split_periods(): array {
  $periods = get_setting('split_periods', [2, 4]);

  if (!is_array($periods)) {
    $periods = [2, 4];
  }

  return apply_filters('kosteams_yandex_split_periods', $periods);
}

/**
 * Рассчитать платеж по рассрочке
 * 
 * @param float $amount Общая сумма
 * @param int $periods Количество платежей
 * @return float
 */
function calculate_split_payment(float $amount, int $periods): float {
  if ($periods <= 0) {
    return $amount;
  }

  return round($amount / $periods, 2);
}

/**
 * Получить статус заказа по коду платежа Яндекс
 * 
 * @param string $payment_status Статус от Яндекс
 * @return string Статус WooCommerce
 */
function map_payment_status(string $payment_status): string {
  $map = [
    'PENDING' => 'pending',
    'AUTHORIZED' => 'on-hold',
    'CAPTURED' => 'processing',
    'CONFIRMED' => 'processing',
    'VOIDED' => 'cancelled',
    'REFUNDED' => 'refunded',
    'PARTIALLY_REFUNDED' => 'processing',
    'FAILED' => 'failed',
    'CANCELLED' => 'cancelled'
  ];

  $status = $map[$payment_status] ?? 'pending';

  return apply_filters('kosteams_yandex_map_payment_status', $status, $payment_status);
}

/**
 * Проверить, является ли заказ оплаченным через Яндекс
 * 
 * @param \WC_Order $order
 * @return bool
 */
function is_yandex_payment(\WC_Order $order): bool {
  $payment_method = $order->get_payment_method();
  return strpos($payment_method, 'kosteams-yandex') === 0;
}

/**
 * Получить ID платежа Яндекс для заказа
 * 
 * @param \WC_Order $order
 * @return string|null
 */
function get_yandex_payment_id(\WC_Order $order): ?string {
  $payment_id = $order->get_meta('_yandex_payment_id');
  return $payment_id ?: null;
}

/**
 * Безопасно получить данные из $_POST
 * 
 * @param string $key Ключ
 * @param mixed $default Значение по умолчанию
 * @return mixed
 */
function get_post_data(string $key, $default = null) {
  if (!isset($_POST[$key])) {
    return $default;
  }

  // Sanitize based on expected type
  if (is_array($_POST[$key])) {
    return array_map('sanitize_text_field', wp_unslash($_POST[$key]));
  }

  return sanitize_text_field(wp_unslash($_POST[$key]));
}

/**
 * Безопасно получить данные из $_GET
 * 
 * @param string $key Ключ
 * @param mixed $default Значение по умолчанию
 * @return mixed
 */
function get_query_param(string $key, $default = null) {
  if (!isset($_GET[$key])) {
    return $default;
  }

  // Sanitize based on expected type
  if (is_array($_GET[$key])) {
    return array_map('sanitize_text_field', wp_unslash($_GET[$key]));
  }

  return sanitize_text_field(wp_unslash($_GET[$key]));
}

/**
 * Создать nonce для AJAX запросов
 * 
 * @param string $action Действие
 * @return string
 */
function create_nonce(string $action = 'kosteams_yandex'): string {
  return wp_create_nonce($action);
}

/**
 * Проверить nonce
 * 
 * @param string $nonce Nonce
 * @param string $action Действие
 * @return bool
 */
function verify_nonce(string $nonce, string $action = 'kosteams_yandex'): bool {
  return wp_verify_nonce($nonce, $action) !== false;
}

/**
 * Отправить JSON ответ успеха
 * 
 * @param mixed $data Данные
 * @param int $status_code HTTP код
 */
function send_json_success($data = null, int $status_code = 200): void {
  wp_send_json_success($data, $status_code);
}

/**
 * Отправить JSON ответ ошибки
 * 
 * @param mixed $data Данные
 * @param int $status_code HTTP код
 */
function send_json_error($data = null, int $status_code = 400): void {
  wp_send_json_error($data, $status_code);
}

/**
 * Проверить возможности пользователя
 * 
 * @param string $capability Capability
 * @return bool
 */
function user_can(string $capability = 'manage_woocommerce'): bool {
  return current_user_can($capability);
}

/**
 * Получить URL страницы настроек
 * 
 * @param string $section Секция (pay или split)
 * @return string
 */
function get_settings_url(string $section = 'pay'): string {
  $section_map = [
    'pay' => 'kosteams-yandex-pay',
    'split' => 'kosteams-yandex-split'
  ];

  $section_slug = $section_map[$section] ?? 'kosteams-yandex-pay';

  return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $section_slug);
}

/**
 * Экранировать и вывести HTML
 * 
 * @param string $html HTML код
 * @param array $allowed_html Разрешенные теги
 */
function echo_html(string $html, array $allowed_html = []): void {
  if (empty($allowed_html)) {
    $allowed_html = [
      'a' => ['href' => [], 'title' => [], 'target' => [], 'class' => []],
      'br' => [],
      'em' => [],
      'strong' => [],
      'span' => ['class' => []],
      'div' => ['class' => [], 'id' => []],
      'p' => ['class' => []],
      'ul' => ['class' => []],
      'ol' => ['class' => []],
      'li' => ['class' => []],
      'img' => ['src' => [], 'alt' => [], 'title' => [], 'class' => [], 'width' => [], 'height' => []]
    ];
  }

  echo wp_kses($html, $allowed_html);
}
