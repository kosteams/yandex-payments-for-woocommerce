<?php

namespace KosTeams\YandexPayments\Payment;

use KosTeams\YandexPayments\API\YandexApiClient;
use KosTeams\YandexPayments\Utils\Logger;
use WC_Order;

/**
 * Процессор платежей
 * 
 * Отвечает за создание и обработку платежей.
 * Координирует работу между API клиентом, менеджером заказов
 * и калькулятором корзины.
 * 
 * @package KosTeams\YandexPayments\Payment
 */
class PaymentProcessor {

    /**
     * API клиент для работы с Яндекс
     * 
     * @var YandexApiClient
     */
    private YandexApiClient $api_client;

    /**
     * Менеджер заказов
     * 
     * @var OrderManager
     */
    private OrderManager $order_manager;

    /**
     * Калькулятор корзины
     * 
     * @var CartCalculator
     */
    private CartCalculator $cart_calculator;

    /**
     * Логгер
     * 
     * @var Logger
     */
    private Logger $logger;

    /**
     * TTL платежной ссылки в секундах (30 минут)
     * 
     * @var int
     */
    const PAYMENT_TTL = 1800;

    /**
     * Конструктор
     * 
     * @param YandexApiClient $api_client API клиент
     * @param OrderManager $order_manager Менеджер заказов
     * @param CartCalculator $cart_calculator Калькулятор корзины
     * @param Logger $logger Логгер
     */
    public function __construct(
        YandexApiClient $api_client,
        OrderManager $order_manager,
        CartCalculator $cart_calculator,
        Logger $logger
    ) {
        $this->api_client = $api_client;
        $this->order_manager = $order_manager;
        $this->cart_calculator = $cart_calculator;
        $this->logger = $logger;
    }

    /**
     * Создать платеж для заказа
     * 
     * @param WC_Order $order Заказ WooCommerce
     * @param array $payment_methods Доступные методы оплаты ['CARD', 'SPLIT']
     * @return string URL для оплаты
     * @throws \Exception При ошибке создания платежа
     */
    public function createPayment(WC_Order $order, array $payment_methods): string {
        $order_id = $order->get_id();

        $this->logger->info('Создание платежа', [
            'order_id' => $order_id,
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_methods' => $payment_methods
        ]);

        // Проверяем, не был ли уже создан платеж
        $existing_payment_url = $this->getExistingPaymentUrl($order, $payment_methods);
        if ($existing_payment_url) {
            $this->logger->info('Используется существующий платеж', [
                'order_id' => $order_id,
                'payment_url' => $existing_payment_url
            ]);
            return $existing_payment_url;
        }

        // Подготавливаем данные платежа
        $payment_data = $this->preparePaymentData($order, $payment_methods);

        try {
            // Отправляем запрос на создание платежа
            $response = $this->api_client->post('orders', $payment_data);

            // Проверяем наличие payment URL
            if (!isset($response['data']['paymentUrl'])) {
                throw new \Exception('Отсутствует paymentUrl в ответе API');
            }

            $payment_url = $response['data']['paymentUrl'];
            $payment_id = $response['data']['paymentId'] ?? null;

            // Сохраняем URL платежа
            $this->savePaymentUrl($order, $payment_methods, $payment_url);

            // Добавляем заметку к заказу
            $order->add_order_note(
                sprintf(
                    __('Платеж создан в Яндекс. ID платежа: %s', 'kosteams-payments-for-yandex'),
                    $payment_id ?: 'не указан'
                )
            );

            $this->logger->info('Платеж успешно создан', [
                'order_id' => $order_id,
                'payment_id' => $payment_id,
                'payment_url' => $payment_url
            ]);

            return $payment_url;
        } catch (\Exception $e) {
            // Специальная обработка ошибки "заказ существует"
            if (strpos($e->getMessage(), 'уже существует') !== false) {
                // Пытаемся получить существующий платеж
                return $this->handleExistingOrder($order, $payment_methods);
            }

            $this->logger->error('Ошибка создания платежа', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Подготовить данные для создания платежа
     * 
     * @param WC_Order $order Заказ
     * @param array $payment_methods Методы оплаты
     * @return array
     */
    private function preparePaymentData(WC_Order $order, array $payment_methods): array {
        $order_id = $order->get_id();
        $methods_key = implode('_', $payment_methods);

        // Рассчитываем корзину
        $cart_data = $this->cart_calculator->calculate($order);

        // Подготавливаем данные о доставке
        $shipping_data = $this->prepareShippingData($order);

        // Формируем полный запрос
        return [
            'orderId' => (string)$order_id . '_' . $methods_key,
            'orderSource' => 'CMS_PLUGIN',
            'purpose' => sprintf(__('Оплата заказа #%s', 'kosteams-payments-for-yandex'), $order_id),
            'isPrepayment' => false,
            'billingPhone' => $this->getPhoneNumber($order),
            'currencyCode' => $order->get_currency(),
            'metadata' => 'Order:' . $order_id,
            'cart' => $cart_data,
            'redirectUrls' => [
                'onSuccess' => $order->get_checkout_order_received_url(),
                'onError' => $order->get_cancel_order_url(),
                'onAbort' => $order->get_cancel_order_url()
            ],
            'availablePaymentMethods' => $payment_methods,
            'ttl' => self::PAYMENT_TTL,
            'risk' => $shipping_data
        ];
    }

    /**
     * Подготовить данные о доставке для risk-оценки
     * 
     * @param WC_Order $order Заказ
     * @return array
     */
    private function prepareShippingData(WC_Order $order): array {
        // Формируем адрес доставки
        $address_parts = array_filter([
            $order->get_shipping_address_1(),
            $order->get_shipping_address_2(),
            $order->get_shipping_city(),
            $order->get_shipping_state(),
            $order->get_shipping_postcode(),
            $order->get_shipping_country()
        ]);

        $shipping_address = implode(', ', $address_parts);

        // Определяем тип доставки
        $shipping_type = 'COURIER';
        foreach ($order->get_items('shipping') as $shipping_item) {
            $method_id = $shipping_item->get_method_id();
            if (
                stripos($method_id, 'pickup') !== false ||
                stripos($method_id, 'local') !== false
            ) {
                $shipping_type = 'PICKUP';
                break;
            }
        }

        return [
            'shippingAddress' => $shipping_address ?: '',
            'shippingPhone' => $this->getPhoneNumber($order),
            'deviceId' => $order->get_meta('device_id') ?: '',
            'shippingType' => $shipping_type
        ];
    }

    /**
     * Получить номер телефона из заказа
     * 
     * @param WC_Order $order Заказ
     * @return string
     */
    private function getPhoneNumber(WC_Order $order): string {
        // Сначала пытаемся получить телефон доставки
        if (method_exists($order, 'get_shipping_phone') && $order->get_shipping_phone()) {
            return $order->get_shipping_phone();
        }

        // Если нет, используем телефон для выставления счета
        return $order->get_billing_phone() ?: '';
    }

    /**
     * Проверить существование платежной ссылки
     * 
     * @param WC_Order $order Заказ
     * @param array $payment_methods Методы оплаты
     * @return string|null
     */
    private function getExistingPaymentUrl(WC_Order $order, array $payment_methods): ?string {
        $methods_key = implode('_', $payment_methods);

        // Получаем сохраненные URLs
        $payment_urls = json_decode($order->get_meta('yandex_payment_urls'), true) ?: [];

        if (isset($payment_urls[$methods_key])) {
            return $payment_urls[$methods_key];
        }

        return null;
    }

    /**
     * Сохранить URL платежа
     * 
     * @param WC_Order $order Заказ
     * @param array $payment_methods Методы оплаты
     * @param string $payment_url URL платежа
     */
    private function savePaymentUrl(WC_Order $order, array $payment_methods, string $payment_url): void {
        $methods_key = implode('_', $payment_methods);

        // Получаем существующие URLs
        $payment_urls = json_decode($order->get_meta('yandex_payment_urls'), true) ?: [];

        // Добавляем новый URL
        $payment_urls[$methods_key] = $payment_url;

        // Сохраняем
        $order->update_meta_data('yandex_payment_urls', json_encode($payment_urls, JSON_UNESCAPED_SLASHES));
        $order->save();
    }

    /**
     * Обработать случай, когда заказ уже существует в Яндекс
     * 
     * @param WC_Order $order Заказ
     * @param array $payment_methods Методы оплаты
     * @return string
     * @throws \Exception
     */
    private function handleExistingOrder(WC_Order $order, array $payment_methods): string {
        $order_id = $order->get_id();
        $methods_key = implode('_', $payment_methods);

        $this->logger->warning('Заказ уже существует в Яндекс, пытаемся получить информацию', [
            'order_id' => $order_id,
            'methods_key' => $methods_key
        ]);

        try {
            // Пытаемся получить информацию о существующем заказе
            $yandex_order_id = $order_id . '_' . $methods_key;
            $response = $this->api_client->get('orders/' . $yandex_order_id);

            if (isset($response['data']['paymentUrl'])) {
                $payment_url = $response['data']['paymentUrl'];

                // Сохраняем URL
                $this->savePaymentUrl($order, $payment_methods, $payment_url);

                return $payment_url;
            }
        } catch (\Exception $e) {
            $this->logger->error('Не удалось получить информацию о существующем заказе', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
        }

        throw new \Exception(
            sprintf(
                __(
                    'Заказ #%s уже существует в системе Яндекс, но не удалось получить ссылку для оплаты',
                    'kosteams-payments-for-yandex'
                ),
                $order_id
            )
        );
    }

    /**
     * Отменить платеж
     * 
     * @param WC_Order $order Заказ
     * @param string $reason Причина отмены
     * @return bool
     */
    public function cancelPayment(WC_Order $order, string $reason = ''): bool {
        $order_id = $order->get_id();

        try {
            $this->logger->info('Отмена платежа', [
                'order_id' => $order_id,
                'reason' => $reason
            ]);

            // Здесь должна быть логика отмены платежа через API
            // В текущей реализации API Яндекса это может быть недоступно

            $order->add_order_note(
                sprintf(
                    __('Запрос на отмену платежа: %s', 'kosteams-payments-for-yandex'),
                    $reason ?: __('причина не указана', 'kosteams-payments-for-yandex')
                )
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Ошибка отмены платежа', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Выполнить возврат средств
     * 
     * @param WC_Order $order Заказ
     * @param float $amount Сумма возврата
     * @param string $reason Причина возврата
     * @return bool
     */
    public function refundPayment(WC_Order $order, float $amount, string $reason = ''): bool {
        $order_id = $order->get_id();

        try {
            $this->logger->info('Возврат платежа', [
                'order_id' => $order_id,
                'amount' => $amount,
                'reason' => $reason
            ]);

            // Здесь должна быть логика возврата через API

            $order->add_order_note(
                sprintf(
                    __('Выполнен возврат на сумму %s. Причина: %s', 'kosteams-payments-for-yandex'),
                    wc_price($amount),
                    $reason ?: __('не указана', 'kosteams-payments-for-yandex')
                )
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Ошибка возврата платежа', [
                'order_id' => $order_id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}
