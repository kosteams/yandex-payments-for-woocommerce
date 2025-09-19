<?php

namespace KosTeams\YandexPayments\API;

use KosTeams\YandexPayments\Utils\Logger;

/**
 * Клиент для взаимодействия с API Яндекс
 * 
 * Отвечает только за отправку HTTP запросов к API Яндекса
 * и обработку HTTP ответов. Не содержит бизнес-логики.
 * 
 * @package KosTeams\YandexPayments\API
 */
class YandexApiClient {

    /**
     * URL API для продакшена
     * 
     * @var string
     */
    const API_URL_PRODUCTION = 'https://pay.yandex.ru/api/merchant/v1/';

    /**
     * URL API для песочницы
     * 
     * @var string
     */
    const API_URL_SANDBOX = 'https://sandbox.pay.yandex.ru/api/merchant/v1/';

    /**
     * Таймаут запроса в секундах
     * 
     * @var int
     */
    const REQUEST_TIMEOUT = 10;

    /**
     * Логгер для записи запросов и ответов
     * 
     * @var Logger
     */
    private Logger $logger;

    /**
     * Merchant ID магазина
     * 
     * @var string
     */
    private string $merchant_id;

    /**
     * API ключ для авторизации
     * 
     * @var string
     */
    private string $api_key;

    /**
     * Режим песочницы (тестовый режим)
     * 
     * @var bool
     */
    private bool $sandbox_mode;

    /**
     * Конструктор
     * 
     * @param Logger $logger Логгер
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Установить учетные данные для API
     * 
     * @param string $merchant_id Merchant ID
     * @param string $api_key API ключ
     * @param bool $sandbox_mode Использовать песочницу
     */
    public function setCredentials(string $merchant_id, string $api_key, bool $sandbox_mode = false): void {
        $this->merchant_id = $merchant_id;
        $this->api_key = $api_key;
        $this->sandbox_mode = $sandbox_mode;
    }

    /**
     * Выполнить POST запрос к API
     * 
     * @param string $endpoint Конечная точка API (например, 'orders')
     * @param array $data Данные для отправки
     * @return array Ответ от API
     * @throws \Exception При ошибке запроса
     */
    public function post(string $endpoint, array $data): array {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Выполнить GET запрос к API
     * 
     * @param string $endpoint Конечная точка API
     * @param array $params Параметры запроса
     * @return array Ответ от API
     * @throws \Exception При ошибке запроса
     */
    public function get(string $endpoint, array $params = []): array {
        $url = $this->buildUrl($endpoint);

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $endpoint, null, $url);
    }

    /**
     * Выполнить PUT запрос к API
     * 
     * @param string $endpoint Конечная точка API
     * @param array $data Данные для отправки
     * @return array Ответ от API
     * @throws \Exception При ошибке запроса
     */
    public function put(string $endpoint, array $data): array {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * Выполнить DELETE запрос к API
     * 
     * @param string $endpoint Конечная точка API
     * @return array Ответ от API
     * @throws \Exception При ошибке запроса
     */
    public function delete(string $endpoint): array {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Выполнить HTTP запрос
     * 
     * @param string $method HTTP метод (GET, POST, PUT, DELETE)
     * @param string $endpoint Конечная точка API
     * @param array|null $data Данные для отправки
     * @param string|null $custom_url Кастомный URL (для GET запросов с параметрами)
     * @return array
     * @throws \Exception При ошибке запроса
     */
    private function request(string $method, string $endpoint, ?array $data = null, ?string $custom_url = null): array {
        // Проверяем наличие учетных данных
        $this->validateCredentials();

        // Строим URL
        $url = $custom_url ?: $this->buildUrl($endpoint);

        // Генерируем уникальный ID запроса
        $request_id = $this->generateRequestId();

        // Подготавливаем заголовки
        $headers = $this->prepareHeaders($request_id);

        // Подготавливаем аргументы для запроса
        $args = [
            'method' => $method,
            'timeout' => self::REQUEST_TIMEOUT,
            'httpversion' => '1.1',
            'headers' => $headers,
            'sslverify' => true
        ];

        // Добавляем тело запроса для POST/PUT
        if ($data !== null && in_array($method, ['POST', 'PUT'])) {
            $args['body'] = json_encode($data);
        }

        // Логируем запрос
        $this->logger->debug('API запрос', [
            'method' => $method,
            'url' => $url,
            'endpoint' => $endpoint,
            'request_id' => $request_id,
            'data' => $data
        ]);

        // Выполняем запрос
        $response = wp_remote_request($url, $args);

        // Обрабатываем ответ
        return $this->handleResponse($response, $endpoint, $request_id);
    }

    /**
     * Обработать ответ от API
     * 
     * @param array|\WP_Error $response Ответ от wp_remote_request
     * @param string $endpoint Конечная точка API
     * @param string $request_id ID запроса
     * @return array
     * @throws \Exception При ошибке
     */
    private function handleResponse($response, string $endpoint, string $request_id): array {
        // Проверяем на WP_Error
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error('Ошибка HTTP запроса', [
                'endpoint' => $endpoint,
                'request_id' => $request_id,
                'error' => $error_message
            ]);

            throw new \Exception(
                sprintf('Ошибка соединения с API Яндекс: %s', $error_message)
            );
        }

        // Получаем код ответа и тело
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Логируем ответ
        $this->logger->debug('API ответ', [
            'endpoint' => $endpoint,
            'request_id' => $request_id,
            'status_code' => $status_code,
            'body' => $body
        ]);

        // Декодируем JSON
        $decoded = json_decode($body, true);

        // Проверяем на ошибки декодирования JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Ошибка декодирования JSON', [
                'endpoint' => $endpoint,
                'request_id' => $request_id,
                'body' => $body,
                'json_error' => json_last_error_msg()
            ]);

            throw new \Exception('Некорректный ответ от API Яндекс');
        }

        // Проверяем статус код
        if ($status_code >= 400) {
            $this->handleApiError($decoded, $status_code, $endpoint, $request_id);
        }

        // Проверяем статус в теле ответа
        if (isset($decoded['status']) && $decoded['status'] === 'fail') {
            $this->handleApiError($decoded, $status_code, $endpoint, $request_id);
        }

        return $decoded;
    }

    /**
     * Обработать ошибку API
     * 
     * @param array $response Декодированный ответ
     * @param int $status_code HTTP код ответа
     * @param string $endpoint Конечная точка API
     * @param string $request_id ID запроса
     * @throws \Exception
     */
    private function handleApiError(array $response, int $status_code, string $endpoint, string $request_id): void {
        $error_code = $response['error']['code'] ?? $response['reasonCode'] ?? 'unknown';
        $error_message = $response['error']['description'] ?? $response['reason'] ?? 'Неизвестная ошибка';

        $this->logger->error('Ошибка API', [
            'endpoint' => $endpoint,
            'request_id' => $request_id,
            'status_code' => $status_code,
            'error_code' => $error_code,
            'error_message' => $error_message
        ]);

        // Специальная обработка известных ошибок
        switch ($error_code) {
            case 'AUTHENTICATION_ERROR':
                throw new \Exception('Ошибка аутентификации. Проверьте API ключ и Merchant ID');

            case 'order_exists':
                throw new \Exception('Заказ с таким ID уже существует в системе Яндекс');

            case 'INVALID_REQUEST':
                throw new \Exception(sprintf('Некорректный запрос: %s', $error_message));

            default:
                throw new \Exception(
                    sprintf('Ошибка API Яндекс [%s]: %s', $error_code, $error_message)
                );
        }
    }

    /**
     * Построить полный URL для запроса
     * 
     * @param string $endpoint Конечная точка API
     * @return string
     */
    private function buildUrl(string $endpoint): string {
        $base_url = $this->sandbox_mode ? self::API_URL_SANDBOX : self::API_URL_PRODUCTION;
        return $base_url . ltrim($endpoint, '/');
    }

    /**
     * Подготовить заголовки для запроса
     * 
     * @param string $request_id ID запроса
     * @return array
     */
    private function prepareHeaders(string $request_id): array {
        // В тестовом режиме используем merchant_id вместо api_key
        $auth_key = $this->sandbox_mode ? $this->merchant_id : $this->api_key;

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Api-Key ' . $auth_key,
            'X-Request-Id' => $request_id,
            'X-Request-Timeout' => self::REQUEST_TIMEOUT * 1000, // В миллисекундах
            'X-Request-Attempt' => '0'
        ];
    }

    /**
     * Генерировать уникальный ID запроса
     * 
     * @return string
     */
    private function generateRequestId(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Проверить наличие учетных данных
     * 
     * @throws \Exception Если учетные данные не установлены
     */
    private function validateCredentials(): void {
        if (empty($this->merchant_id) || empty($this->api_key)) {
            throw new \Exception(
                'Не установлены учетные данные API. Проверьте настройки Merchant ID и API ключа'
            );
        }
    }

    /**
     * Проверить доступность API
     * 
     * @return bool
     */
    public function ping(): bool {
        try {
            // Попытка получить информацию о магазине
            $this->get('merchant');
            return true;
        } catch (\Exception $e) {
            $this->logger->error('API недоступен', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Получить базовый URL API
     * 
     * @return string
     */
    public function getApiUrl(): string {
        return $this->sandbox_mode ? self::API_URL_SANDBOX : self::API_URL_PRODUCTION;
    }

    /**
     * Проверить, включен ли тестовый режим
     * 
     * @return bool
     */
    public function isSandboxMode(): bool {
        return $this->sandbox_mode;
    }
}
