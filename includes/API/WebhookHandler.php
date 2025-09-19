<?php

namespace KosTeams\YandexPayments\API;

use KosTeams\YandexPayments\Payment\OrderManager;
use KosTeams\YandexPayments\Utils\Logger;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use WC_Order;

/**
 * Обработчик вебхуков от Яндекс
 * 
 * Принимает и обрабатывает уведомления от платежной системы
 * об изменении статусов платежей
 * 
 * @package KosTeams\YandexPayments\API
 */
class WebhookHandler {

    /**
     * Менеджер заказов
     * 
     * @var OrderManager
     */
    private OrderManager $order_manager;

    /**
     * Логгер
     * 
     * @var Logger
     */
    private Logger $logger;

    /**
     * Merchant ID для проверки
     * 
     * @var string
     */
    private string $merchant_id;

    /**
     * Тестовый режим
     * 
     * @var bool
     */
    private bool $test_mode;

    /**
     * URL для получения публичных ключей
     */
    const JWKS_URL_PRODUCTION = 'https://pay.yandex.ru/api/jwks';
    const JWKS_URL_SANDBOX = 'https://sandbox.pay.yandex.ru/api/jwks';

    /**
     * Ожидаемый User-Agent от Яндекс
     */
    const EXPECTED_USER_AGENT = 'YandexPay/1.0';

    /**
     * Конструктор
     * 
     * @param OrderManager $order_manager
     * @param Logger $logger
     */
    public function __construct(OrderManager $order_manager, Logger $logger) {
        $this->order_manager = $order_manager;
        $this->logger = $logger;
    }

    /**
     * Установить конфигурацию
     * 
     * @param string $merchant_id
     * @param bool $test_mode
     */
    public function configure(string $merchant_id, bool $test_mode = false): void {
        $this->merchant_id = $merchant_id;
        $this->test_mode = $test_mode;
    }

    /**
     * Обработать входящий вебхук
     * 
     * @return void
     */
    public function handle(): void {
        try {
            // Валидация запроса
            $this->validateRequest();

            // Получение и декодирование тела запроса
            $payload = $this->getPayload();

            // Обработка payload в зависимости от типа
            $this->processPayload($payload);

            // Отправляем успешный ответ
            $this->sendSuccessResponse();
        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Валидация входящего запроса
     * 
     * @throws \Exception
     */
    private function validateRequest(): void {
        // Проверка метода запроса
        $request_method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($request_method !== 'POST') {
            $this->logger->error('Вебхук: неверный метод запроса', [
                'method' => $request_method,
                'expected' => 'POST'
            ]);
            throw new \Exception('Invalid request method', 405);
        }

        // Проверка User-Agent
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($user_agent !== self::EXPECTED_USER_AGENT) {
            $this->logger->error('Вебхук: неверный User-Agent', [
                'user_agent' => $user_agent,
                'expected' => self::EXPECTED_USER_AGENT
            ]);
            throw new \Exception('Invalid User-Agent', 403);
        }

        // Проверка наличия Merchant ID
        if (empty($this->merchant_id)) {
            $this->logger->error('Вебхук: не установлен Merchant ID');
            throw new \Exception('Merchant ID not configured', 500);
        }
    }

    /**
     * Получить и декодировать payload
     * 
     * @return object|array
     * @throws \Exception
     */
    private function getPayload() {
        // Получаем сырые данные
        $raw_input = file_get_contents('php://input');

        if (empty($raw_input)) {
            $this->logger->error('Вебхук: пустое тело запроса');
            throw new \Exception('Empty request body', 400);
        }

        $this->logger->debug('Вебхук: получены данные', [
            'size' => strlen($raw_input),
            'first_100_chars' => substr($raw_input, 0, 100)
        ]);

        // Пытаемся декодировать как JSON
        $decoded = json_decode($raw_input, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Это обычный JSON
            return $this->processJsonPayload($decoded);
        }

        // Если не JSON, пытаемся декодировать как JWT
        return $this->processJwtPayload($raw_input);
    }

    /**
     * Обработать JSON payload
     * 
     * @param array $data
     * @return array
     */
    private function processJsonPayload(array $data): array {
        $this->logger->info('Вебхук: обработка JSON payload', [
            'event_type' => $data['event'] ?? 'unknown'
        ]);

        // Валидация структуры
        if (!isset($data['event']) || !isset($data['eventTime'])) {
            throw new \Exception('Invalid JSON payload structure', 400);
        }

        return $data;
    }

    /**
     * Обработать JWT payload
     * 
     * @param string $jwt
     * @return object
     * @throws \Exception
     */
    private function processJwtPayload(string $jwt): object {
        $this->logger->info('Вебхук: обработка JWT токена');

        // Получаем публичные ключи
        $jwks = $this->fetchJwks();

        // Декодируем JWT
        $payload = null;
        $decoded = false;

        foreach ($jwks['keys'] as $key_data) {
            try {
                $key = JWK::parseKey($key_data);
                $payload = JWT::decode($jwt, $key);
                $decoded = true;
                break;
            } catch (\Exception $e) {
                // Пробуем следующий ключ
                continue;
            }
        }

        if (!$decoded || !$payload) {
            $this->logger->error('Вебхук: не удалось декодировать JWT');
            throw new \Exception('Invalid JWT token', 403);
        }

        $this->logger->debug('Вебхук: JWT успешно декодирован', [
            'merchant_id' => $payload->merchantId ?? null,
            'order_id' => $payload->order->orderId ?? null
        ]);

        // Проверка Merchant ID
        if (!isset($payload->merchantId) || $payload->merchantId !== $this->merchant_id) {
            $this->logger->error('Вебхук: неверный Merchant ID в JWT', [
                'expected' => $this->merchant_id,
                'received' => $payload->merchantId ?? 'none'
            ]);
            throw new \Exception('Invalid Merchant ID', 403);
        }

        return $payload;
    }

    /**
     * Получить публичные ключи для проверки JWT
     * 
     * @return array
     * @throws \Exception
     */
    private function fetchJwks(): array {
        $url = $this->test_mode ? self::JWKS_URL_SANDBOX : self::JWKS_URL_PRODUCTION;

        $this->logger->debug('Вебхук: запрос JWKS', ['url' => $url]);

        $response = wp_remote_get($url, [
            'timeout' => 5,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Вебхук: ошибка получения JWKS', [
                'error' => $response->get_error_message()
            ]);
            throw new \Exception('Failed to fetch JWKS', 500);
        }

        $body = wp_remote_retrieve_body($response);
        $jwks = json_decode($body, true);

        if (empty($jwks['keys'])) {
            $this->logger->error('Вебхук: пустой JWKS');
            throw new \Exception('Invalid JWKS response', 500);
        }

        return $jwks;
    }

    /**
     * Обработать payload
     * 
     * @param object|array $payload
     */
    private function processPayload($payload): void {
        // Определяем тип события
        if (is_array($payload)) {
            $this->processJsonEvent($payload);
        } else {
            $this->processJwtEvent($payload);
        }
    }

    /**
     * Обработать JSON событие
     * 
     * @param array $event
     */
    private function processJsonEvent(array $event): void {
        $event_type = $event['event'] ?? '';

        switch ($event_type) {
            case 'payment.succeeded':
                $this->handlePaymentSucceeded($event);
                break;

            case 'payment.canceled':
                $this->handlePaymentCanceled($event);
                break;

            case 'refund.succeeded':
                $this->handleRefundSucceeded($event);
                break;

            default:
                $this->logger->warning('Вебхук: неизвестный тип события', [
                    'event_type' => $event_type
                ]);
        }
    }

    /**
     * Обработать JWT событие
     * 
     * @param object $payload
     */
    private function processJwtEvent(object $payload): void {
        // Проверяем наличие информации о заказе
        if (!isset($payload->order) || !isset($payload->order->orderId)) {
            $this->logger->warning('Вебхук: отсутствует информация о заказе в JWT');
            return;
        }

        $order_data = $payload->order;
        $payment_status = $order_data->paymentStatus ?? '';

        // Извлекаем ID заказа (убираем суффикс с методами оплаты)
        $full_order_id = $order_data->orderId;
        $order_id = $this->extractOrderId($full_order_id);

        // Получаем заказ
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->logger->error('Вебхук: заказ не найден', [
                'order_id' => $order_id,
                'full_order_id' => $full_order_id
            ]);
            return;
        }

        // Обрабатываем изменение статуса
        $this->order_manager->updateOrderStatus($order, $payment_status, 'yandex_webhook');

        $this->logger->info('Вебхук: статус заказа обработан', [
            'order_id' => $order_id,
            'payment_status' => $payment_status
        ]);
    }

    /**
     * Извлечь ID заказа из полного идентификатора
     * 
     * @param string $full_order_id
     * @return int
     */
    private function extractOrderId(string $full_order_id): int {
        // Формат: {order_id}_{payment_methods}
        // Например: 123_CARD или 123_CARD_SPLIT
        $parts = explode('_', $full_order_id);
        return (int)$parts[0];
    }

    /**
     * Обработать успешный платеж
     * 
     * @param array $event
     */
    private function handlePaymentSucceeded(array $event): void {
        $order_id = $event['metadata']['order_id'] ?? null;

        if (!$order_id) {
            $this->logger->warning('Вебхук: отсутствует order_id в событии payment.succeeded');
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            $this->logger->error('Вебхук: заказ не найден для payment.succeeded', [
                'order_id' => $order_id
            ]);
            return;
        }

        // Обновляем статус заказа
        $this->order_manager->markOrderAsPaid($order, $event);

        $this->logger->info('Вебхук: платеж успешно обработан', [
            'order_id' => $order_id,
            'payment_id' => $event['id'] ?? null
        ]);
    }

    /**
     * Обработать отмененный платеж
     * 
     * @param array $event
     */
    private function handlePaymentCanceled(array $event): void {
        $order_id = $event['metadata']['order_id'] ?? null;

        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $this->order_manager->markOrderAsCanceled($order, $event);

        $this->logger->info('Вебхук: платеж отменен', [
            'order_id' => $order_id
        ]);
    }

    /**
     * Обработать успешный возврат
     * 
     * @param array $event
     */
    private function handleRefundSucceeded(array $event): void {
        $order_id = $event['metadata']['order_id'] ?? null;
        $amount = $event['amount']['value'] ?? 0;

        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Создаем возврат в WooCommerce
        $this->order_manager->createRefund($order, $amount, $event);

        $this->logger->info('Вебхук: возврат обработан', [
            'order_id' => $order_id,
            'amount' => $amount
        ]);
    }

    /**
     * Отправить успешный ответ
     */
    private function sendSuccessResponse(): void {
        wp_send_json_success(['status' => 'ok'], 200);
    }

    /**
     * Обработать ошибку
     * 
     * @param \Exception $e
     */
    private function handleError(\Exception $e): void {
        $code = $e->getCode() ?: 500;
        $message = $e->getMessage();

        $this->logger->error('Вебхук: ошибка обработки', [
            'code' => $code,
            'message' => $message,
            'trace' => $e->getTraceAsString()
        ]);

        wp_send_json_error(
            ['message' => $message],
            $code
        );
    }

    /**
     * Проверить подпись вебхука (если применимо)
     * 
     * @param string $body Тело запроса
     * @param string $signature Подпись из заголовка
     * @return bool
     */
    private function verifySignature(string $body, string $signature): bool {
        // Яндекс использует JWT для подписи, поэтому этот метод
        // может быть использован для дополнительной проверки HMAC,
        // если она будет добавлена в будущем

        return true;
    }
}
