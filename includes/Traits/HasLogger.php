<?php

namespace KosTeams\YandexPayments\Traits;

use KosTeams\YandexPayments\Utils\Logger;

/**
 * Трейт для логирования
 * 
 * Предоставляет удобные методы для логирования
 * в классах, которые нуждаются в этой функциональности
 * 
 * @package KosTeams\YandexPayments\Traits
 */
trait HasLogger {

    /**
     * Экземпляр логгера
     * 
     * @var Logger|null
     */
    private ?Logger $logger = null;

    /**
     * Контекст для логирования
     * 
     * @var array
     */
    private array $log_context = [];

    /**
     * Получить логгер
     * 
     * @return Logger
     */
    protected function getLogger(): Logger {
        if ($this->logger === null) {
            $this->logger = new Logger();

            // Добавляем контекст класса
            $this->logger->addGlobalContext('class', static::class);
        }

        return $this->logger;
    }

    /**
     * Установить логгер
     * 
     * @param Logger $logger
     */
    public function setLogger(Logger $logger): void {
        $this->logger = $logger;
    }

    /**
     * Добавить контекст логирования
     * 
     * @param string $key
     * @param mixed $value
     */
    protected function addLogContext(string $key, $value): void {
        $this->log_context[$key] = $value;
    }

    /**
     * Установить контекст логирования
     * 
     * @param array $context
     */
    protected function setLogContext(array $context): void {
        $this->log_context = $context;
    }

    /**
     * Получить контекст логирования
     * 
     * @param array $additional Дополнительный контекст
     * @return array
     */
    protected function getLogContext(array $additional = []): array {
        return array_merge($this->log_context, $additional);
    }

    /**
     * Логировать debug сообщение
     * 
     * @param string $message
     * @param array $context
     */
    protected function logDebug(string $message, array $context = []): void {
        $this->getLogger()->debug($message, $this->getLogContext($context));
    }

    /**
     * Логировать info сообщение
     * 
     * @param string $message
     * @param array $context
     */
    protected function logInfo(string $message, array $context = []): void {
        $this->getLogger()->info($message, $this->getLogContext($context));
    }

    /**
     * Логировать notice сообщение
     * 
     * @param string $message
     * @param array $context
     */
    protected function logNotice(string $message, array $context = []): void {
        $this->getLogger()->notice($message, $this->getLogContext($context));
    }

    /**
     * Логировать warning сообщение
     * 
     * @param string $message
     * @param array $context
     */
    protected function logWarning(string $message, array $context = []): void {
        $this->getLogger()->warning($message, $this->getLogContext($context));
    }

    /**
     * Логировать error сообщение
     * 
     * @param string $message
     * @param array $context
     */
    protected function logError(string $message, array $context = []): void {
        $this->getLogger()->error($message, $this->getLogContext($context));
    }

    /**
     * Логировать critical сообщение
     * 
     * @param string $message
     * @param array $context
     */
    protected function logCritical(string $message, array $context = []): void {
        $this->getLogger()->critical($message, $this->getLogContext($context));
    }

    /**
     * Логировать alert сообщение
     * 
     * @param string $message
     * @param array $context
     */
    protected function logAlert(string $message, array $context = []): void {
        $this->getLogger()->alert($message, $this->getLogContext($context));
    }

    /**
     * Логировать emergency сообщение
     * 
     * @param string $message
     * @param array $context
     */
    protected function logEmergency(string $message, array $context = []): void {
        $this->getLogger()->emergency($message, $this->getLogContext($context));
    }

    /**
     * Логировать исключение
     * 
     * @param \Exception $exception
     * @param string $message
     * @param array $context
     */
    protected function logException(\Exception $exception, string $message = '', array $context = []): void {
        $exception_context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];

        $full_context = array_merge($exception_context, $context);

        $this->getLogger()->error(
            $message ?: 'Exception occurred',
            $this->getLogContext($full_context)
        );
    }

    /**
     * Логировать метрику производительности
     * 
     * @param string $operation Название операции
     * @param float $start_time Время начала (microtime(true))
     * @param array $context Дополнительный контекст
     */
    protected function logPerformance(string $operation, float $start_time, array $context = []): void {
        $duration = microtime(true) - $start_time;

        $performance_context = [
            'operation' => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];

        $full_context = array_merge($performance_context, $context);

        // Логируем как debug или warning в зависимости от времени выполнения
        if ($duration > 1) { // Больше 1 секунды
            $this->logWarning("Slow operation: {$operation}", $full_context);
        } else {
            $this->logDebug("Performance: {$operation}", $full_context);
        }
    }

    /**
     * Логировать API запрос
     * 
     * @param string $method HTTP метод
     * @param string $url URL
     * @param array $data Данные запроса
     * @param array $response Ответ
     * @param float $duration Время выполнения
     */
    protected function logApiRequest(
        string $method,
        string $url,
        array $data = [],
        array $response = [],
        float $duration = 0
    ): void {
        $context = [
            'method' => $method,
            'url' => $url,
            'request_data' => $this->sanitizeApiData($data),
            'response_data' => $this->sanitizeApiData($response),
            'duration_ms' => round($duration * 1000, 2)
        ];

        $this->logInfo("API Request: {$method} {$url}", $context);
    }

    /**
     * Очистить чувствительные данные из API данных
     * 
     * @param array $data
     * @return array
     */
    private function sanitizeApiData(array $data): array {
        $sensitive_keys = ['api_key', 'password', 'token', 'secret', 'cvv', 'card_number'];

        foreach ($data as $key => $value) {
            // Проверяем, является ли ключ чувствительным
            foreach ($sensitive_keys as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $data[$key] = '***HIDDEN***';
                    break;
                }
            }

            // Рекурсивно обрабатываем вложенные массивы
            if (is_array($value)) {
                $data[$key] = $this->sanitizeApiData($value);
            }
        }

        return $data;
    }

    /**
     * Проверить, включено ли логирование
     * 
     * @return bool
     */
    protected function isLoggingEnabled(): bool {
        return $this->getLogger()->getLevel() !== '';
    }

    /**
     * Проверить, включен ли debug режим
     * 
     * @return bool
     */
    protected function isDebugMode(): bool {
        return $this->getLogger()->getLevel() === Logger::DEBUG;
    }
}
