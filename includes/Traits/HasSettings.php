<?php

namespace KosTeams\YandexPayments\Traits;

/**
 * Трейт для работы с настройками
 * 
 * Предоставляет унифицированный интерфейс для работы
 * с настройками в различных классах плагина
 * 
 * @package KosTeams\YandexPayments\Traits
 */
trait HasSettings {
    
    /**
     * Кэш настроек
     * 
     * @var array
     */
    private array $settings_cache = [];
    
    /**
     * Префикс для настроек
     * 
     * @var string
     */
    private string $settings_prefix = 'kosteams_yandex_';
    
    /**
     * Получить настройку
     * 
     * @param string $key Ключ настройки
     * @param mixed $default Значение по умолчанию
     * @return mixed
     */
    public function getSetting(string $key, $default = null) {
        // Проверяем кэш
        if (isset($this->settings_cache[$key])) {
            return $this->settings_cache[$key];
        }
        
        // Получаем из опций WordPress
        $option_name = $this->settings_prefix . $key;
        $value = get_option($option_name, $default);
        
        // Кэшируем
        $this->settings_cache[$key] = $value;
        
        return $value;
    }
    
    /**
     * Установить настройку
     * 
     * @param string $key Ключ настройки
     * @param mixed $value Значение
     * @return bool
     */
    public function setSetting(string $key, $value): bool {
        $option_name = $this->settings_prefix . $key;
        
        // Обновляем в БД
        $result = update_option($option_name, $value);
        
        // Обновляем кэш
        if ($result) {
            $this->settings_cache[$key] = $value;
        }
        
        return $result;
    }
    
    /**
     * Получить несколько настроек
     * 
     * @param array $keys Массив ключей
     * @param array $defaults Значения по умолчанию
     * @return array
     */
    public function getSettings(array $keys, array $defaults = []): array {
        $settings = [];
        
        foreach ($keys as $key) {
            $default = $defaults[$key] ?? null;
            $settings[$key] = $this->getSetting($key, $default);
        }
        
        return $settings;
    }
    
    /**
     * Установить несколько настроек
     * 
     * @param array $settings Массив настроек
     * @return bool
     */
    public function setSettings(array $settings): bool {
        $success = true;
        
        foreach ($settings as $key => $value) {
            if (!$this->setSetting($key, $value)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Удалить настройку
     * 
     * @param string $key Ключ настройки
     * @return bool
     */
    public function deleteSetting(string $key): bool {
        $option_name = $this->settings_prefix . $key;
        
        // Удаляем из БД
        $result = delete_option($option_name);
        
        // Удаляем из кэша
        if ($result) {
            unset($this->settings_cache[$key]);
        }
        
        return $result;
    }
    
    /**
     * Проверить существование настройки
     * 
     * @param string $key Ключ настройки
     * @return bool
     */
    public function hasSetting(string $key): bool {
        $option_name = $this->settings_prefix . $key;
        
        // Проверяем в кэше
        if (isset($this->settings_cache[$key])) {
            return true;
        }
        
        // Проверяем в БД
        return get_option($option_name, null) !== null;
    }
    
    /**
     * Очистить кэш настроек
     */
    public function clearSettingsCache(): void {
        $this->settings_cache = [];
    }
    
    /**
     * Установить префикс для настроек
     * 
     * @param string $prefix
     */
    public function setSettingsPrefix(string $prefix): void {
        $this->settings_prefix = $prefix;
        $this->clearSettingsCache();
    }
    
    /**
     * Получить все настройки с префиксом
     * 
     * @return array
     */
    public function getAllSettings(): array {
        global $wpdb;
        
        $settings = [];
        $prefix = $this->settings_prefix;
        
        // Получаем все опции с нашим префиксом
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );
        
        foreach ($results as $row) {
            // Убираем префикс из ключа
            $key = str_replace($prefix, '', $row->option_name);
            $settings[$key] = maybe_unserialize($row->option_value);
            
            // Кэшируем
            $this->settings_cache[$key] = $settings[$key];
        }
        
        return $settings;
    }
}