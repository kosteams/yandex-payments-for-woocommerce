<?php

namespace KosTeams\YandexPayments\Utils;

/**
 * Система логирования
 * 
 * Централизованная система для записи логов плагина
 * с поддержкой уровней важности и контекстной информации
 * 
 * @package KosTeams\YandexPayments\Utils
 */
class Logger {

    /**
     * Уровни логирования
     */
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';

    /**
     * Числовые значения уровней для сравнения
     * 
     * @var array
     */
    private const LEVELS = [
        self::EMERGENCY => 0,
        self::ALERT => 1,
        self::CRITICAL => 2,
        self::ERROR => 3,
        self::WARNING => 4,
        self::NOTICE => 5,
        self::INFO => 6,
        self::DEBUG => 7
    ];

    /**
     * Источник логов
     * 
     * @var string
     */
    private string $source = 'kosteams-yandex-payments';

    /**
     * Логгер WooCommerce
     * 
     * @var \WC_Logger
     */
    private \WC_Logger $wc_logger;

    /**
     * Текущий уровень логирования
     * 
     * @var string
     */
    private string $log_level = self::INFO;

    /**
     * Включено ли логирование
     * 
     * @var bool
     */
    private bool $enabled = true;

    /**
     * Дополнительный контекст для всех записей
     * 
     * @var array
     */
    private array $global_context = [];

    /**
     * Конструктор
     */
    public function __construct() {
        if (function_exists('wc_get_logger')) {
            $this->wc_logger = wc_get_logger();
        }

        // Загружаем настройки логирования
        $this->loadSettings();
    }

    /**
     * Загрузить настройки из опций WordPress
     */
    private function loadSettings(): void {
        // Получаем настройки из основного шлюза
        $settings = get_option('woocommerce_kosteams-yandex-pay_settings', []);

        // Включено ли логирование
        $this->enabled = isset($settings['debug_mode']) && $settings['debug_mode'] === 'yes';

        // Уровень логирования
        if ($this->enabled) {
            $this->log_level = $settings['log_level'] ?? self::INFO;
        } else {
            // Если отладка выключена, логируем только ошибки
            $this->log_level = self::ERROR;
        }

        // Глобальный контекст
        $this->global_context = [
            'plugin_version' => KOSTEAMS_YANDEX_VERSION ?? 'unknown',
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'unknown',
            'php_version' => PHP_VERSION
        ];
    }

    /**
     * Установить уровень логирования
     * 
     * @param string $level
     */
    public function setLevel(string $level): void {
        if (isset(self::LEVELS[$level])) {
            $this->log_level = $level;
        }
    }

    /**
     * Получить текущий уровень логирования
     * 
     * @return string
     */
    public function getLevel(): string {
        return $this->log_level;
    }

    /**
     * Включить/выключить логирование
     * 
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void {
        $this->enabled = $enabled;
    }

    /**
     * Добавить глобальный контекст
     * 
     * @param string $key
     * @param mixed $value
     */
    public function addGlobalContext(string $key, $value): void {
        $this->global_context[$key] = $value;
    }

    /**
     * Записать сообщение в лог
     * 
     * @param string $level Уровень важности
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    public function log(string $level, string $message, array $context = []): void {
        // Проверяем, нужно ли логировать этот уровень
        if (!$this->shouldLog($level)) {
            return;
        }

        // Объединяем контексты
        $full_context = array_merge($this->global_context, $context, [
            'source' => $this->source,
            'timestamp' => current_time('mysql'),
            'level' => $level
        ]);

        // Форматируем сообщение
        $formatted_message = $this->formatMessage($message, $full_context);

        // Записываем в WooCommerce логгер
        if ($this->wc_logger) {
            $this->wc_logger->log($level, $formatted_message, ['source' => $this->source]);
        }

        // Для критических ошибок также пишем в error_log PHP
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL])) {
            error_log(sprintf('[%s] %s: %s', $this->source, strtoupper($level), $formatted_message));
        }

        // Вызываем хук для внешних обработчиков
        do_action('kosteams_yandex_log', $level, $message, $full_context);
    }

    /**
     * Проверить, нужно ли логировать данный уровень
     * 
     * @param string $level
     * @return bool
     */
    private function shouldLog(string $level): bool {
        if (!$this->enabled) {
            // Если логирование выключено, логируем только критические ошибки
            return in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL, self::ERROR]);
        }

        $level_value = self::LEVELS[$level] ?? 999;
        $current_level_value = self::LEVELS[$this->log_level] ?? 0;

        return $level_value <= $current_level_value;
    }

    /**
     * Форматировать сообщение с контекстом
     * 
     * @param string $message
     * @param array $context
     * @return string
     */
    private function formatMessage(string $message, array $context): string {
        // Удаляем системные поля из контекста для вывода
        $display_context = $context;
        unset(
            $display_context['source'],
            $display_context['timestamp'],
            $display_context['level'],
            $display_context['plugin_version'],
            $display_context['wp_version'],
            $display_context['wc_version'],
            $display_context['php_version']
        );

        // Если контекст пустой, возвращаем только сообщение
        if (empty($display_context)) {
            return $message;
        }

        // Форматируем контекст
        $context_string = $this->formatContext($display_context);

        return sprintf('%s | %s', $message, $context_string);
    }

    /**
     * Форматировать контекст для вывода
     * 
     * @param array $context
     * @return string
     */
    private function formatContext(array $context): string {
        $parts = [];

        foreach ($context as $key => $value) {
            $formatted_value = $this->formatValue($value);
            $parts[] = sprintf('%s: %s', $key, $formatted_value);
        }

        return implode(', ', $parts);
    }

    /**
     * Форматировать значение для вывода
     * 
     * @param mixed $value
     * @return string
     */
    private function formatValue($value): string {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            // Для массивов используем краткий формат
            if ($this->isAssociativeArray($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                return '[' . implode(', ', array_map([$this, 'formatValue'], $value)) . ']';
            }
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return get_class($value);
        }

        return gettype($value);
    }

    /**
     * Проверить, является ли массив ассоциативным
     * 
     * @param array $array
     * @return bool
     */
    private function isAssociativeArray(array $array): bool {
        if (empty($array)) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    // Удобные методы для разных уровней логирования

    /**
     * Логировать критическую ошибку
     * 
     * @param string $message
     * @param array $context
     */
    public function emergency(string $message, array $context = []): void {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Логировать alert
     * 
     * @param string $message
     * @param array $context
     */
    public function alert(string $message, array $context = []): void {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * Логировать критическое состояние
     * 
     * @param string $message
     * @param array $context
     */
    public function critical(string $message, array $context = []): void {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Логировать ошибку
     * 
     * @param string $message
     * @param array $context
     */
    public function error(string $message, array $context = []): void {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Логировать предупреждение
     * 
     * @param string $message
     * @param array $context
     */
    public function warning(string $message, array $context = []): void {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Логировать уведомление
     * 
     * @param string $message
     * @param array $context
     */
    public function notice(string $message, array $context = []): void {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * Логировать информационное сообщение
     * 
     * @param string $message
     * @param array $context
     */
    public function info(string $message, array $context = []): void {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Логировать отладочную информацию
     * 
     * @param string $message
     * @param array $context
     */
    public function debug(string $message, array $context = []): void {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Очистить логи
     * 
     * @param int $days_to_keep Сколько дней логов сохранить (0 = удалить все)
     */
    public function clearLogs(int $days_to_keep = 7): void {
        if (!$this->wc_logger) {
            return;
        }

        // WooCommerce автоматически очищает старые логи
        // Но мы можем форсировать очистку для нашего источника

        $this->info('Запущена очистка логов', [
            'days_to_keep' => $days_to_keep
        ]);

        // Вызываем хук для внешних обработчиков
        do_action('kosteams_yandex_clear_logs', $days_to_keep);
    }

    /**
     * Получить путь к файлу логов
     * 
     * @return string
     */
    public function getLogFilePath(): string {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wc-logs/';

        // Имя файла логов для нашего источника
        $log_file = $this->source . '-' . wp_hash($this->source) . '.log';

        return $log_dir . $log_file;
    }
}
