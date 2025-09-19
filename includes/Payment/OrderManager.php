<?php

namespace KosTeams\YandexPayments\Payment;

use KosTeams\YandexPayments\Utils\Logger;
use WC_Order;

/**
 * Менеджер заказов
 * 
 * Управляет изменением статусов заказов, обработкой платежей
 * и взаимодействием с системой заказов WooCommerce
 * 
 * @package KosTeams\YandexPayments\Payment
 */
class OrderManager {
    
    /**
     * Логгер
     * 
     * @var Logger
     */
    private Logger $logger;
    
    /**
     * Маппинг статусов Яндекс -> WooCommerce
     * 
     * @var array
     */
    private const STATUS_MAP = [
        'PENDING' => 'pending',           // Ожидает оплаты
        'AUTHORIZED' => 'on-hold',        // Средства заблокированы
        'CAPTURED' => 'processing',       // Платеж получен
        'CONFIRMED' => 'processing',      // Платеж подтвержден
        'VOIDED' => 'cancelled',          // Отменено
        'REFUNDED' => 'refunded',         // Возвращено
        'PARTIALLY_REFUNDED' => 'processing', // Частичный возврат
        'FAILED' => 'failed',             // Ошибка платежа
        'CANCELLED' => 'cancelled'        // Отменено пользователем
    ];
    
    /**
     * Статусы, которые считаются оплаченными
     * 
     * @var array
     */
    private const PAID_STATUSES = ['processing', 'completed'];
    
    /**
     * Статусы, которые считаются финальными
     * 
     * @var array
     */
    private const FINAL_STATUSES = ['completed', 'refunded', 'cancelled', 'failed'];
    
    /**
     * Конструктор
     * 
     * @param Logger $logger
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }
    
    /**
     * Обновить статус заказа на основе статуса платежа
     * 
     * @param WC_Order $order Заказ
     * @param string $payment_status Статус платежа от Яндекс
     * @param string $source Источник изменения (webhook, api, manual)
     * @return bool
     */
    public function updateOrderStatus(WC_Order $order, string $payment_status, string $source = 'api'): bool {
        $order_id = $order->get_id();
        $current_status = $order->get_status();
        
        $this->logger->info('Обновление статуса заказа', [
            'order_id' => $order_id,
            'current_status' => $current_status,
            'payment_status' => $payment_status,
            'source' => $source
        ]);
        
        // Проверяем возможность изменения статуса
        if (!$this->canChangeStatus($order, $payment_status)) {
            $this->logger->warning('Изменение статуса невозможно', [
                'order_id' => $order_id,
                'current_status' => $current_status,
                'requested_status' => $payment_status
            ]);
            return false;
        }
        
        // Получаем новый статус
        $new_status = $this->mapPaymentStatus($payment_status);
        
        // Применяем фильтры для кастомизации
        $new_status = apply_filters(
            'kosteams_yandex_order_status_mapping',
            $new_status,
            $payment_status,
            $order,
            $source
        );
        
        // Если статус не изменился, ничего не делаем
        if ($new_status === $current_status) {
            $this->logger->debug('Статус не изменился', [
                'order_id' => $order_id,
                'status' => $new_status
            ]);
            return true;
        }
        
        // Обрабатываем специальные случаи
        switch ($payment_status) {
            case 'PARTIALLY_REFUNDED':
                return $this->handlePartialRefund($order, $source);
                
            case 'AUTHORIZED':
                return $this->handleAuthorization($order, $source);
                
            case 'CAPTURED':
            case 'CONFIRMED':
                return $this->handlePaymentComplete($order, $payment_status, $source);
                
            case 'REFUNDED':
                return $this->handleFullRefund($order, $source);
                
            default:
                return $this->changeOrderStatus($order, $new_status, $payment_status, $source);
        }
    }
    
    /**
     * Пометить заказ как оплаченный
     * 
     * @param WC_Order $order
     * @param array $payment_data Данные платежа
     * @return bool
     */
    public function markOrderAsPaid(WC_Order $order, array $payment_data = []): bool {
        $order_id = $order->get_id();
        
        $this->logger->info('Помечаем заказ как оплаченный', [
            'order_id' => $order_id,
            'payment_id' => $payment_data['id'] ?? null
        ]);
        
        // Сохраняем информацию о платеже
        if (!empty($payment_data['id'])) {
            $order->update_meta_data('_yandex_payment_id', $payment_data['id']);
        }
        
        if (!empty($payment_data['paid_at'])) {
            $order->update_meta_data('_yandex_paid_at', $payment_data['paid_at']);
        }
        
        // Помечаем как оплаченный
        $order->payment_complete($payment_data['id'] ?? '');
        
        // Добавляем заметку
        $note = sprintf(
            __('Платеж получен через Яндекс. ID транзакции: %s', 'kosteams-payments-for-yandex'),
            $payment_data['id'] ?? __('не указан', 'kosteams-payments-for-yandex')
        );
        
        if (!empty($payment_data['amount'])) {
            $note .= sprintf(
                __(' Сумма: %s %s', 'kosteams-payments-for-yandex'),
                $payment_data['amount']['value'] ?? '',
                $payment_data['amount']['currency'] ?? ''
            );
        }
        
        $order->add_order_note($note);
        $order->save();
        
        // Вызываем хук для внешних обработчиков
        do_action('kosteams_yandex_order_paid', $order, $payment_data);
        
        return true;
    }
    
    /**
     * Пометить заказ как отмененный
     * 
     * @param WC_Order $order
     * @param array $cancellation_data Данные отмены
     * @return bool
     */
    public function markOrderAsCanceled(WC_Order $order, array $cancellation_data = []): bool {
        $order_id = $order->get_id();
        
        $this->logger->info('Отмена заказа', [
            'order_id' => $order_id,
            'reason' => $cancellation_data['reason'] ?? null
        ]);
        
        // Меняем статус на отменен
        $order->update_status('cancelled', 
            sprintf(
                __('Платеж отменен. Причина: %s', 'kosteams-payments-for-yandex'),
                $cancellation_data['reason'] ?? __('не указана', 'kosteams-payments-for-yandex')
            )
        );
        
        // Сохраняем данные об отмене
        if (!empty($cancellation_data['cancelled_at'])) {
            $order->update_meta_data('_yandex_cancelled_at', $cancellation_data['cancelled_at']);
        }
        
        $order->save();
        
        // Восстанавливаем товары на склад
        $this->restoreStock($order);
        
        // Вызываем хук
        do_action('kosteams_yandex_order_canceled', $order, $cancellation_data);
        
        return true;
    }
    
    /**
     * Создать возврат для заказа
     * 
     * @param WC_Order $order
     * @param float $amount Сумма возврата
     * @param array $refund_data Данные возврата
     * @return bool|\WC_Order_Refund
     */
    public function createRefund(WC_Order $order, float $amount, array $refund_data = []) {
        $order_id = $order->get_id();
        
        $this->logger->info('Создание возврата', [
            'order_id' => $order_id,
            'amount' => $amount,
            'refund_id' => $refund_data['id'] ?? null
        ]);
        
        // Проверяем возможность возврата
        if (!$this->canRefund($order, $amount)) {
            $this->logger->error('Возврат невозможен', [
                'order_id' => $order_id,
                'requested_amount' => $amount,
                'max_refund' => $order->get_remaining_refund_amount()
            ]);
            return false;
        }
        
        // Подготавливаем данные для возврата
        $refund_args = [
            'amount' => $amount,
            'reason' => $refund_data['reason'] ?? __('Возврат через Яндекс', 'kosteams-payments-for-yandex'),
            'order_id' => $order_id,
            'refund_payment' => false, // Платеж уже возвращен через Яндекс
            'restock_items' => true
        ];
        
        // Создаем возврат в WooCommerce
        $refund = wc_create_refund($refund_args);
        
        if (is_wp_error($refund)) {
            $this->logger->error('Ошибка создания возврата', [
                'order_id' => $order_id,
                'error' => $refund->get_error_message()
            ]);
            return false;
        }
        
        // Сохраняем информацию о возврате от Яндекс
        if ($refund && !empty($refund_data['id'])) {
            $refund->update_meta_data('_yandex_refund_id', $refund_data['id']);
            $refund->update_meta_data('_yandex_refunded_at', $refund_data['created_at'] ?? current_time('mysql'));
            $refund->save();
        }
        
        // Добавляем заметку к заказу
        $order->add_order_note(
            sprintf(
                __('Возврат на сумму %s выполнен через Яндекс. ID возврата: %s', 'kosteams-payments-for-yandex'),
                wc_price($amount),
                $refund_data['id'] ?? __('не указан', 'kosteams-payments-for-yandex')
            )
        );
        
        // Обновляем статус заказа при полном возврате
        if ($order->get_remaining_refund_amount() <= 0) {
            $order->update_status('refunded');
        }
        
        // Вызываем хук
        do_action('kosteams_yandex_order_refunded', $order, $refund, $refund_data);
        
        return $refund;
    }
    
    /**
     * Проверить возможность изменения статуса
     * 
     * @param WC_Order $order
     * @param string $new_payment_status
     * @return bool
     */
    private function canChangeStatus(WC_Order $order, string $new_payment_status): bool {
        $current_status = $order->get_status();
        $payment_method = $order->get_payment_method();
        
        // Проверяем, что заказ оплачен через наш плагин
        $is_our_payment = strpos($payment_method, 'kosteams-yandex') === 0;
        
        // Если заказ оплачен другим способом и уже в финальном статусе
        if (!$is_our_payment && in_array($current_status, self::FINAL_STATUSES)) {
            // Не меняем статус заказов, оплаченных другими способами
            return false;
        }
        
        // Не позволяем откатывать оплаченные заказы
        if (in_array($current_status, self::PAID_STATUSES)) {
            $new_status = $this->mapPaymentStatus($new_payment_status);
            
            // Разрешаем только переход к возврату или завершению
            if (!in_array($new_status, ['refunded', 'completed', 'processing'])) {
                return false;
            }
        }
        
        // Применяем фильтр для дополнительных проверок
        return apply_filters(
            'kosteams_yandex_can_change_order_status',
            true,
            $order,
            $new_payment_status
        );
    }
    
    /**
     * Маппинг статуса платежа на статус заказа
     * 
     * @param string $payment_status
     * @return string
     */
    private function mapPaymentStatus(string $payment_status): string {
        return self::STATUS_MAP[$payment_status] ?? 'pending';
    }
    
    /**
     * Изменить статус заказа
     * 
     * @param WC_Order $order
     * @param string $new_status
     * @param string $payment_status
     * @param string $source
     * @return bool
     */
    private function changeOrderStatus(
        WC_Order $order, 
        string $new_status, 
        string $payment_status, 
        string $source
    ): bool {
        $note = sprintf(
            __('Статус обновлен через %s. Статус платежа: %s', 'kosteams-payments-for-yandex'),
            $source === 'webhook' ? __('вебхук Яндекс', 'kosteams-payments-for-yandex') : __('API Яндекс', 'kosteams-payments-for-yandex'),
            $payment_status
        );
        
        $order->update_status($new_status, $note);
        
        $this->logger->info('Статус заказа изменен', [
            'order_id' => $order->get_id(),
            'new_status' => $new_status,
            'payment_status' => $payment_status
        ]);
        
        return true;
    }
    
    /**
     * Обработать частичный возврат
     * 
     * @param WC_Order $order
     * @param string $source
     * @return bool
     */
    private function handlePartialRefund(WC_Order $order, string $source): bool {
        $order->add_order_note(
            sprintf(
                __('Частичный возврат обработан через %s', 'kosteams-payments-for-yandex'),
                $source === 'webhook' ? __('вебхук', 'kosteams-payments-for-yandex') : 'API'
            )
        );
        
        $this->logger->info('Частичный возврат', ['order_id' => $order->get_id()]);
        
        return true;
    }
    
    /**
     * Обработать авторизацию платежа
     * 
     * @param WC_Order $order
     * @param string $source
     * @return bool
     */
    private function handleAuthorization(WC_Order $order, string $source): bool {
        $order->update_status('on-hold', 
            __('Платеж авторизован. Ожидается подтверждение.', 'kosteams-payments-for-yandex')
        );
        
        $order->update_meta_data('_yandex_payment_authorized', 'yes');
        $order->update_meta_data('_yandex_authorized_at', current_time('mysql'));
        $order->save();
        
        $this->logger->info('Платеж авторизован', ['order_id' => $order->get_id()]);
        
        return true;
    }
    
    /**
     * Обработать завершение платежа
     * 
     * @param WC_Order $order
     * @param string $payment_status
     * @param string $source
     * @return bool
     */
    private function handlePaymentComplete(WC_Order $order, string $payment_status, string $source): bool {
        // Помечаем заказ как оплаченный
        $order->payment_complete();
        
        $order->add_order_note(
            sprintf(
                __('Платеж завершен. Статус: %s. Источник: %s', 'kosteams-payments-for-yandex'),
                $payment_status,
                $source
            )
        );
        
        $this->logger->info('Платеж завершен', [
            'order_id' => $order->get_id(),
            'payment_status' => $payment_status
        ]);
        
        return true;
    }
    
    /**
     * Обработать полный возврат
     * 
     * @param WC_Order $order
     * @param string $source
     * @return bool
     */
    private function handleFullRefund(WC_Order $order, string $source): bool {
        $order->update_status('refunded',
            sprintf(
                __('Полный возврат выполнен через %s', 'kosteams-payments-for-yandex'),
                $source
            )
        );
        
        // Восстанавливаем товары на склад
        $this->restoreStock($order);
        
        $this->logger->info('Полный возврат', ['order_id' => $order->get_id()]);
        
        return true;
    }
    
    /**
     * Проверить возможность возврата
     * 
     * @param WC_Order $order
     * @param float $amount
     * @return bool
     */
    private function canRefund(WC_Order $order, float $amount): bool {
        // Проверяем статус заказа
        if (!in_array($order->get_status(), self::PAID_STATUSES)) {
            return false;
        }
        
        // Проверяем доступную сумму для возврата
        $max_refund = $order->get_remaining_refund_amount();
        
        return $amount <= $max_refund;
    }
    
    /**
     * Восстановить товары на склад
     * 
     * @param WC_Order $order
     */
    private function restoreStock(WC_Order $order): void {
        // Проверяем, не были ли уже восстановлены
        if ($order->get_meta('_stock_restored') === 'yes') {
            return;
        }
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if ($product && $product->managing_stock()) {
                $qty = $item->get_quantity();
                
                // Увеличиваем количество на складе
                $new_stock = wc_update_product_stock($product, $qty, 'increase');
                
                $this->logger->debug('Товар восстановлен на склад', [
                    'product_id' => $product->get_id(),
                    'quantity' => $qty,
                    'new_stock' => $new_stock
                ]);
            }
        }
        
        // Помечаем, что товары восстановлены
        $order->update_meta_data('_stock_restored', 'yes');
        $order->save();
    }
}