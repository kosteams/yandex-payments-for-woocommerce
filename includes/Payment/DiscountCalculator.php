<?php

namespace KosTeams\YandexPayments\Payment;

use WC_Order;
use WC_Order_Item_Product;

/**
 * Калькулятор скидок
 * 
 * Рассчитывает и распределяет скидки по товарам в заказе,
 * включая купоны, бонусные программы и другие виды скидок
 * 
 * @package KosTeams\YandexPayments\Payment
 */
class DiscountCalculator {

    /**
     * Рассчитать общую сумму скидок для заказа
     * 
     * @param WC_Order $order
     * @return float
     */
    public function calculateTotalDiscount(WC_Order $order): float {
        $total_discount = 0;

        // 1. Скидки от купонов
        $coupon_discount = $this->calculateCouponDiscount($order);
        $total_discount += $coupon_discount;

        // 2. Бонусная скидка (если применяется)
        $bonus_discount = $this->calculateBonusDiscount($order);
        $total_discount += $bonus_discount;

        // 3. Скидки от отрицательных сборов
        $fee_discount = $this->calculateFeeDiscount($order);
        $total_discount += $fee_discount;

        // 4. Скидка на доставку
        $shipping_discount = $this->calculateShippingDiscount($order);
        $total_discount += $shipping_discount;

        // Применяем фильтр для кастомных скидок
        $total_discount = apply_filters(
            'kosteams_yandex_total_discount',
            $total_discount,
            $order
        );

        return round($total_discount, 2);
    }

    /**
     * Распределить скидки по товарам
     * 
     * @param WC_Order $order
     * @param float $total_discount Общая сумма скидок
     * @return array Массив [item_id => discount_amount]
     */
    public function distributeDiscounts(WC_Order $order, float $total_discount): array {
        $item_discounts = [];
        $total_goods = 0;

        // Если нет скидок, возвращаем пустой массив
        if ($total_discount <= 0) {
            return $item_discounts;
        }

        // Собираем информацию о товарах
        $items_data = [];
        foreach ($order->get_items() as $item_id => $item) {
            $line_subtotal = round((float)$item->get_subtotal(), 2);

            $items_data[$item_id] = [
                'subtotal' => $line_subtotal,
                'quantity' => $item->get_quantity(),
                'product_id' => $item->get_product_id()
            ];

            $total_goods += $line_subtotal;
        }

        // Если нет товаров, возвращаем пустой массив
        if ($total_goods <= 0) {
            return $item_discounts;
        }

        // Определяем метод распределения скидок
        $distribution_method = $this->getDistributionMethod($order);

        switch ($distribution_method) {
            case 'proportional':
                $item_discounts = $this->distributeProportionally($items_data, $total_discount, $total_goods);
                break;

            case 'equal':
                $item_discounts = $this->distributeEqually($items_data, $total_discount);
                break;

            case 'priority':
                $item_discounts = $this->distributeByPriority($items_data, $total_discount, $order);
                break;

            default:
                $item_discounts = $this->distributeProportionally($items_data, $total_discount, $total_goods);
        }

        // Корректировка точности
        $item_discounts = $this->adjustPrecision($item_discounts, $total_discount);

        return $item_discounts;
    }

    /**
     * Рассчитать скидку от купонов
     * 
     * @param WC_Order $order
     * @return float
     */
    private function calculateCouponDiscount(WC_Order $order): float {
        $discount = 0;

        foreach ($order->get_coupons() as $coupon) {
            $discount += round((float)$coupon->get_discount(), 2);
        }

        return $discount;
    }

    /**
     * Рассчитать бонусную скидку
     * 
     * @param WC_Order $order
     * @return float
     */
    private function calculateBonusDiscount(WC_Order $order): float {
        $user_id = $order->get_user_id();

        if (!$user_id) {
            return 0;
        }

        // Проверяем наличие бонусной программы
        $bonus_enabled = get_option('kosteams_bonus_program_enabled', 'no');

        if ($bonus_enabled !== 'yes') {
            return 0;
        }

        // Получаем уровень бонусов пользователя
        $bonus_level = $this->getUserBonusLevel($user_id);

        if (!$bonus_level) {
            return 0;
        }

        // Рассчитываем скидку на основе уровня
        $order_total = $order->get_subtotal();
        $discount_percent = $bonus_level['discount'] ?? 0;

        return round($order_total * ($discount_percent / 100), 2);
    }

    /**
     * Получить бонусный уровень пользователя
     * 
     * @param int $user_id
     * @return array|null
     */
    private function getUserBonusLevel(int $user_id): ?array {
        $total_spent = (float)wc_get_customer_total_spent($user_id);
        $order_count = wc_get_customer_order_count($user_id);

        // Получаем настройки бонусных уровней
        $bonus_levels = get_option('kosteams_bonus_levels', []);

        if (!is_array($bonus_levels) || empty($bonus_levels)) {
            return null;
        }

        // Сортируем уровни по порогу
        usort($bonus_levels, function ($a, $b) {
            return ($b['threshold'] ?? 0) - ($a['threshold'] ?? 0);
        });

        // Находим подходящий уровень
        foreach ($bonus_levels as $level) {
            $threshold = $level['threshold'] ?? 0;
            $threshold_type = $level['threshold_type'] ?? 'amount';

            if ($threshold_type === 'amount' && $total_spent >= $threshold) {
                return $level;
            } elseif ($threshold_type === 'orders' && $order_count >= $threshold) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Рассчитать скидку от отрицательных сборов
     * 
     * @param WC_Order $order
     * @return float
     */
    private function calculateFeeDiscount(WC_Order $order): float {
        $discount = 0;

        foreach ($order->get_fees() as $fee_item) {
            $fee_amount = round((float)$fee_item->get_total(), 2);

            // Отрицательные сборы считаем как скидки
            if ($fee_amount < 0) {
                $discount += abs($fee_amount);
            }
        }

        return $discount;
    }

    /**
     * Рассчитать скидку на доставку
     * 
     * @param WC_Order $order
     * @return float
     */
    private function calculateShippingDiscount(WC_Order $order): float {
        $discount = 0;

        foreach ($order->get_items('shipping') as $shipping_item) {
            // Проверяем наличие скидки на доставку
            if (method_exists($shipping_item, 'get_meta')) {
                $shipping_discount = $shipping_item->get_meta('discount_amount');

                if ($shipping_discount) {
                    $discount += round((float)$shipping_discount, 2);
                }
            }

            // Альтернативный способ - через купоны на бесплатную доставку
            foreach ($order->get_coupons() as $coupon) {
                $coupon_obj = new \WC_Coupon($coupon->get_code());

                if ($coupon_obj->get_free_shipping()) {
                    // Если купон дает бесплатную доставку, считаем всю стоимость доставки как скидку
                    $discount += round((float)$shipping_item->get_total(), 2);
                    break; // Учитываем только один раз
                }
            }
        }

        return $discount;
    }

    /**
     * Получить метод распределения скидок
     * 
     * @param WC_Order $order
     * @return string proportional|equal|priority
     */
    private function getDistributionMethod(WC_Order $order): string {
        // Проверяем настройки плагина
        $method = get_option('kosteams_discount_distribution_method', 'proportional');

        // Применяем фильтр для кастомизации
        $method = apply_filters(
            'kosteams_yandex_discount_distribution_method',
            $method,
            $order
        );

        return in_array($method, ['proportional', 'equal', 'priority']) ? $method : 'proportional';
    }

    /**
     * Распределить скидки пропорционально стоимости товаров
     * 
     * @param array $items_data
     * @param float $total_discount
     * @param float $total_goods
     * @return array
     */
    private function distributeProportionally(array $items_data, float $total_discount, float $total_goods): array {
        $item_discounts = [];
        $distributed = 0;

        foreach ($items_data as $item_id => $data) {
            // Рассчитываем долю товара в общей сумме
            $ratio = $data['subtotal'] / $total_goods;

            // Рассчитываем скидку для товара
            $item_discount = round($total_discount * $ratio, 2);

            // Убеждаемся, что скидка не больше стоимости товара
            if ($item_discount > $data['subtotal']) {
                $item_discount = $data['subtotal'];
            }

            $item_discounts[$item_id] = $item_discount;
            $distributed += $item_discount;
        }

        return $item_discounts;
    }

    /**
     * Распределить скидки равномерно между товарами
     * 
     * @param array $items_data
     * @param float $total_discount
     * @return array
     */
    private function distributeEqually(array $items_data, float $total_discount): array {
        $item_discounts = [];
        $item_count = count($items_data);

        if ($item_count === 0) {
            return $item_discounts;
        }

        $per_item_discount = round($total_discount / $item_count, 2);

        foreach ($items_data as $item_id => $data) {
            // Скидка не может быть больше стоимости товара
            $item_discount = min($per_item_discount, $data['subtotal']);
            $item_discounts[$item_id] = $item_discount;
        }

        return $item_discounts;
    }

    /**
     * Распределить скидки по приоритету товаров
     * 
     * @param array $items_data
     * @param float $total_discount
     * @param WC_Order $order
     * @return array
     */
    private function distributeByPriority(array $items_data, float $total_discount, WC_Order $order): array {
        $item_discounts = [];
        $remaining_discount = $total_discount;

        // Получаем приоритеты товаров
        $priorities = $this->getItemPriorities($items_data, $order);

        // Сортируем товары по приоритету
        arsort($priorities);

        foreach ($priorities as $item_id => $priority) {
            if ($remaining_discount <= 0) {
                $item_discounts[$item_id] = 0;
                continue;
            }

            $data = $items_data[$item_id];

            // Применяем скидку в соответствии с приоритетом
            $priority_factor = $priority / 100;
            $max_discount = min($data['subtotal'], $remaining_discount);
            $item_discount = round($max_discount * $priority_factor, 2);

            $item_discounts[$item_id] = $item_discount;
            $remaining_discount -= $item_discount;
        }

        return $item_discounts;
    }

    /**
     * Получить приоритеты товаров для распределения скидок
     * 
     * @param array $items_data
     * @param WC_Order $order
     * @return array
     */
    private function getItemPriorities(array $items_data, WC_Order $order): array {
        $priorities = [];

        foreach ($items_data as $item_id => $data) {
            // Базовый приоритет 50%
            $priority = 50;

            // Увеличиваем приоритет для более дорогих товаров
            if ($data['subtotal'] > 1000) {
                $priority += 20;
            }

            // Применяем фильтр для кастомизации
            $priority = apply_filters(
                'kosteams_yandex_item_discount_priority',
                $priority,
                $item_id,
                $data,
                $order
            );

            $priorities[$item_id] = max(0, min(100, $priority));
        }

        return $priorities;
    }

    /**
     * Корректировка точности распределения
     * 
     * @param array $item_discounts
     * @param float $total_discount
     * @return array
     */
    private function adjustPrecision(array $item_discounts, float $total_discount): array {
        if (empty($item_discounts)) {
            return $item_discounts;
        }

        $distributed_sum = array_sum($item_discounts);
        $difference = round($total_discount - $distributed_sum, 2);

        // Если разница меньше копейки, игнорируем
        if (abs($difference) < 0.01) {
            return $item_discounts;
        }

        // Корректируем последний товар
        $last_item_id = array_key_last($item_discounts);
        $item_discounts[$last_item_id] = round($item_discounts[$last_item_id] + $difference, 2);

        // Убеждаемся, что скидка не отрицательная
        if ($item_discounts[$last_item_id] < 0) {
            $item_discounts[$last_item_id] = 0;
        }

        return $item_discounts;
    }

    /**
     * Валидировать скидки
     * 
     * @param WC_Order $order
     * @param array $item_discounts
     * @return bool
     */
    public function validateDiscounts(WC_Order $order, array $item_discounts): bool {
        foreach ($order->get_items() as $item_id => $item) {
            $discount = $item_discounts[$item_id] ?? 0;
            $subtotal = $item->get_subtotal();

            // Скидка не может быть больше стоимости товара
            if ($discount > $subtotal) {
                return false;
            }

            // Скидка не может быть отрицательной
            if ($discount < 0) {
                return false;
            }
        }

        return true;
    }
}
