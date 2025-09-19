<?php

namespace KosTeams\YandexPayments\Admin;

/**
 * Менеджер настроек плагина
 * 
 * Управляет настройками плагина, их сохранением, валидацией
 * и синхронизацией между различными компонентами
 * 
 * @package KosTeams\YandexPayments\Admin
 */
class Settings {

    /**
     * Префикс для опций в БД
     * 
     * @var string
     */
    const OPTION_PREFIX = 'kosteams_yandex_';

    /**
     * Группа настроек
     * 
     * @var string
     */
    const SETTINGS_GROUP = 'kosteams_yandex_settings';

    /**
     * Кэш настроек
     * 
     * @var array
     */
    private array $settings_cache = [];

    /**
     * Настройки по умолчанию
     * 
     * @var array
     */
    private array $defaults = [
        'merchant_id' => '',
        'api_key' => '',
        'test_mode' => false,
        'debug_mode' => false,
        'log_level' => 'info',

        // Настройки отображения
        'show_badges' => true,
        'show_calculator' => true,
        'button_style' => 'black',

        // Настройки рассрочки
        'split_enabled' => true,
        'min_split_amount' => 3000,
        'max_split_amount' => 150000,
        'split_periods' => [2, 4],

        // Настройки скидок
        'discount_distribution' => 'proportional',

        // Бонусная программа
        'bonus_program_enabled' => false,
        'bonus_levels' => [],

        // Уведомления
        'email_notifications' => true,
        'admin_email' => '',

        // Расширенные настройки
        'api_timeout' => 10,
        'retry_attempts' => 3,
        'cache_ttl' => 3600
    ];

    /**
     * Правила валидации
     * 
     * @var array
     */
    private array $validation_rules = [
        'merchant_id' => ['required', 'string', 'min:10'],
        'api_key' => ['required', 'string', 'min:20'],
        'test_mode' => ['boolean'],
        'debug_mode' => ['boolean'],
        'log_level' => ['in:emergency,alert,critical,error,warning,notice,info,debug'],
        'min_split_amount' => ['numeric', 'min:0'],
        'max_split_amount' => ['numeric', 'min:0'],
        'split_periods' => ['array', 'in:2,3,4,6'],
        'api_timeout' => ['numeric', 'min:1', 'max:30'],
        'retry_attempts' => ['numeric', 'min:0', 'max:5'],
        'cache_ttl' => ['numeric', 'min:0', 'max:86400']
    ];

    /**
     * Конструктор
     */
    public function __construct() {
        $this->loadSettings();
    }

    /**
     * Загрузить все настройки
     */
    private function loadSettings(): void {
        // Загружаем настройки из основных шлюзов
        $pay_settings = get_option('woocommerce_kosteams-yandex-pay_settings', []);
        $split_settings = get_option('woocommerce_kosteams-yandex-split_settings', []);

        // Загружаем общие настройки плагина
        $plugin_settings = get_option(self::OPTION_PREFIX . 'settings', []);

        // Объединяем настройки с приоритетом
        $this->settings_cache = array_merge(
            $this->defaults,
            $plugin_settings,
            $this->normalizeWooCommerceSettings($pay_settings),
            $this->normalizeWooCommerceSettings($split_settings)
        );

        // Применяем фильтр для модификации настроек
        $this->settings_cache = apply_filters(
            'kosteams_yandex_settings',
            $this->settings_cache
        );
    }

    /**
     * Нормализовать настройки WooCommerce
     * 
     * @param array $wc_settings
     * @return array
     */
    private function normalizeWooCommerceSettings(array $wc_settings): array {
        $normalized = [];

        // Преобразуем yes/no в boolean
        foreach ($wc_settings as $key => $value) {
            if ($value === 'yes') {
                $normalized[$key] = true;
            } elseif ($value === 'no') {
                $normalized[$key] = false;
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Получить настройку
     * 
     * @param string $key Ключ настройки
     * @param mixed $default Значение по умолчанию
     * @return mixed
     */
    public function get(string $key, $default = null) {
        // Поддержка вложенных ключей через точку
        if (strpos($key, '.') !== false) {
            return $this->getNestedValue($this->settings_cache, $key, $default);
        }

        return $this->settings_cache[$key] ?? $default ?? $this->defaults[$key] ?? null;
    }

    /**
     * Получить вложенное значение
     * 
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getNestedValue(array $array, string $key, $default = null) {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Установить настройку
     * 
     * @param string $key Ключ настройки
     * @param mixed $value Значение
     * @return bool
     */
    public function set(string $key, $value): bool {
        // Валидируем значение
        if (!$this->validate($key, $value)) {
            return false;
        }

        // Устанавливаем в кэш
        $this->settings_cache[$key] = $value;

        // Сохраняем в БД
        return $this->save();
    }

    /**
     * Установить несколько настроек
     * 
     * @param array $settings
     * @return bool
     */
    public function setMultiple(array $settings): bool {
        $valid = true;

        // Валидируем все настройки
        foreach ($settings as $key => $value) {
            if (!$this->validate($key, $value)) {
                $valid = false;
            }
        }

        if (!$valid) {
            return false;
        }

        // Устанавливаем в кэш
        $this->settings_cache = array_merge($this->settings_cache, $settings);

        // Сохраняем
        return $this->save();
    }

    /**
     * Валидировать значение настройки
     * 
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function validate(string $key, $value): bool {
        if (!isset($this->validation_rules[$key])) {
            return true; // Нет правил - считаем валидным
        }

        $rules = $this->validation_rules[$key];

        foreach ($rules as $rule) {
            if (!$this->applyValidationRule($rule, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Применить правило валидации
     * 
     * @param string $rule
     * @param mixed $value
     * @return bool
     */
    private function applyValidationRule(string $rule, $value): bool {
        // Разбираем правило
        if (strpos($rule, ':') !== false) {
            list($rule_name, $rule_value) = explode(':', $rule, 2);
        } else {
            $rule_name = $rule;
            $rule_value = null;
        }

        switch ($rule_name) {
            case 'required':
                return !empty($value);

            case 'string':
                return is_string($value);

            case 'numeric':
                return is_numeric($value);

            case 'boolean':
                return is_bool($value) || $value === 'yes' || $value === 'no';

            case 'array':
                return is_array($value);

            case 'min':
                if (is_string($value)) {
                    return strlen($value) >= intval($rule_value);
                }
                return $value >= floatval($rule_value);

            case 'max':
                if (is_string($value)) {
                    return strlen($value) <= intval($rule_value);
                }
                return $value <= floatval($rule_value);

            case 'in':
                $allowed = explode(',', $rule_value);
                if (is_array($value)) {
                    return count(array_diff($value, $allowed)) === 0;
                }
                return in_array($value, $allowed);

            default:
                // Применяем фильтр для кастомных правил
                return apply_filters(
                    'kosteams_yandex_validate_rule',
                    true,
                    $rule_name,
                    $value,
                    $rule_value
                );
        }
    }

    /**
     * Сохранить настройки в БД
     * 
     * @return bool
     */
    private function save(): bool {
        // Разделяем настройки по группам
        $plugin_settings = [];
        $pay_settings = [];
        $split_settings = [];

        // Список полей для каждого шлюза
        $pay_fields = ['merchant_id', 'api_key', 'test_mode', 'debug_mode'];
        $split_fields = ['payment_method', 'min_split_amount', 'max_split_amount', 'split_periods'];

        foreach ($this->settings_cache as $key => $value) {
            if (in_array($key, $pay_fields)) {
                $pay_settings[$key] = $this->prepareForWooCommerce($value);
            } elseif (in_array($key, $split_fields)) {
                $split_settings[$key] = $this->prepareForWooCommerce($value);
            } else {
                $plugin_settings[$key] = $value;
            }
        }

        // Сохраняем в соответствующие опции
        $success = true;

        if (!empty($plugin_settings)) {
            $success = $success && update_option(self::OPTION_PREFIX . 'settings', $plugin_settings);
        }

        if (!empty($pay_settings)) {
            $current = get_option('woocommerce_kosteams-yandex-pay_settings', []);
            $success = $success && update_option(
                'woocommerce_kosteams-yandex-pay_settings',
                array_merge($current, $pay_settings)
            );
        }

        if (!empty($split_settings)) {
            $current = get_option('woocommerce_kosteams-yandex-split_settings', []);
            $success = $success && update_option(
                'woocommerce_kosteams-yandex-split_settings',
                array_merge($current, $split_settings)
            );
        }

        // Очищаем кэш
        $this->clearCache();

        // Вызываем хук
        do_action('kosteams_yandex_settings_saved', $this->settings_cache);

        return $success;
    }

    /**
     * Подготовить значение для сохранения в WooCommerce
     * 
     * @param mixed $value
     * @return mixed
     */
    private function prepareForWooCommerce($value) {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return $value;
        }

        return (string)$value;
    }

    /**
     * Сбросить настройки к значениям по умолчанию
     * 
     * @param string|null $key Конкретная настройка или null для всех
     * @return bool
     */
    public function reset(?string $key = null): bool {
        if ($key !== null) {
            if (isset($this->defaults[$key])) {
                $this->settings_cache[$key] = $this->defaults[$key];
            }
        } else {
            $this->settings_cache = $this->defaults;
        }

        return $this->save();
    }

    /**
     * Очистить кэш настроек
     */
    public function clearCache(): void {
        $this->settings_cache = [];
        $this->loadSettings();

        // Очищаем transients если используются
        delete_transient(self::OPTION_PREFIX . 'cache');
    }

    /**
     * Экспортировать настройки
     * 
     * @return array
     */
    public function export(): array {
        $export = $this->settings_cache;

        // Удаляем чувствительные данные
        unset($export['api_key']);

        return $export;
    }

    /**
     * Импортировать настройки
     * 
     * @param array $settings
     * @return bool
     */
    public function import(array $settings): bool {
        // Валидируем импортируемые настройки
        foreach ($settings as $key => $value) {
            if (!$this->validate($key, $value)) {
                return false;
            }
        }

        // Сохраняем текущие чувствительные данные
        $sensitive = [
            'api_key' => $this->settings_cache['api_key'] ?? '',
            'merchant_id' => $this->settings_cache['merchant_id'] ?? ''
        ];

        // Импортируем
        $this->settings_cache = array_merge(
            $this->defaults,
            $settings,
            $sensitive // Восстанавливаем чувствительные данные
        );

        return $this->save();
    }

    /**
     * Получить все настройки
     * 
     * @return array
     */
    public function getAll(): array {
        return $this->settings_cache;
    }

    /**
     * Проверить, настроен ли плагин
     * 
     * @return bool
     */
    public function isConfigured(): bool {
        return !empty($this->settings_cache['merchant_id'])
            && !empty($this->settings_cache['api_key']);
    }

    /**
     * Получить URL страницы настроек
     * 
     * @param string $tab
     * @return string
     */
    public function getSettingsUrl(string $tab = ''): string {
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-pay');

        if ($tab) {
            $url .= '&kosteams_tab=' . $tab;
        }

        return $url;
    }
}
