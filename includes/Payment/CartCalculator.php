<?php

namespace KosTeams\YandexPayments\Payment;

use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Fee;

/**
 * Калькулятор корзины
 * 
 * Отвечает за расчет позиций корзины, применение скидок
 * и формирование данных для отправки в API Яндекс
 * 
 * @package KosTeams\YandexPayments\Payment
 */
class CartCalculator {

    /**
     * Калькулятор скидок
     * 
     * @var DiscountCalculator
     */
    private DiscountCalculator $discount_calculator;

    /**
     * Точность округления для денежных сумм
     * 
     * @var int
     */
    const MONEY_PRECISION = 2;

    /**
     * Конструктор
     * 
     * @param DiscountCalculator $discount_calculator
     */
    public function __construct(DiscountCalculator $discount_calculator) {
        $this->discount_calculator = $discount_calculator;
    }

    /**
     * Рассчитать данные корзины для заказа
     * 
     * @param WC_Order $order Заказ
     * @return array Данные корзины для API
     */
    public function calculate(WC_Order $order): array {
        $cart_items = [];
        $items_sum = 0.0;
        $discounts_sum = 0.0;

        // Получаем точную сумму заказа из WooCommerce
        $woocommerce_total = $this->formatMoney($order->get_total());

        // Рассчитываем все скидки
        $total_discount = $this->discount_calculator->calculateTotalDiscount($order);

        // Получаем распределение скидок по товарам
        $item_discounts = $this->discount_calculator->distributeDiscounts($order, $total_discount);

        // 1. Обработка товаров
        foreach ($order->get_items() as $item_id => $item) {
            $product_items = $this->processProductItem($item, $item_discounts[$item_id] ?? 0);

            foreach ($product_items as $product_item) {
                $cart_items[] = $product_item;
                $items_sum += (float)$product_item['subtotal'];

                // Считаем скидку
                $item_discount = (float)$product_item['subtotal'] - (float)$product_item['total'];
                $discounts_sum += $item_discount;
            }
        }

        // 2. Обработка доставки
        foreach ($order->get_items('shipping') as $shipping_item) {
            $shipping_data = $this->processShippingItem($shipping_item);
            $cart_items[] = $shipping_data;
            $items_sum += (float)$shipping_data['subtotal'];

            // Считаем скидку на доставку
            $shipping_discount = (float)$shipping_data['subtotal'] - (float)$shipping_data['total'];
            $discounts_sum += $shipping_discount;
        }

        // 3. Обработка дополнительных сборов
        foreach ($order->get_fees() as $fee_item) {
            $fee_data = $this->processFeeItem($fee_item);
            if ($fee_data) {
                $cart_items[] = $fee_data;
                $items_sum += (float)$fee_data['subtotal'];
            }
        }

        // Финальная корректировка для точного соответствия
        $this->adjustTotalPrecision($cart_items, $woocommerce_total, $items_sum, $discounts_sum);

        // Формируем результат
        return [
            'externalId' => (string)$order->get_id(),
            'items' => $cart_items,
            'total' => [
                'amount' => $this->formatMoneyString($woocommerce_total),
                'pointsAmount' => '0'
            ]
        ];
    }

    /**
     * Обработать товарную позицию
     * 
     * @param WC_Order_Item_Product $item Товар
     * @param float $item_discount Скидка на товар
     * @return array Массив позиций для API
     */
    private function processProductItem(WC_Order_Item_Product $item, float $item_discount): array {
        $quantity = (int)$item->get_quantity();
        $product = $item->get_product();

        if (!$product) {
            throw new \Exception(
                sprintf('Продукт не найден для позиции заказа #%s', $item->get_id())
            );
        }

        $unit_price = $this->formatMoney($item->get_subtotal() / $quantity);
        $line_subtotal = $this->formatMoney($item->get_subtotal());
        $line_total = $this->formatMoney($line_subtotal - $item_discount);

        // Убеждаемся, что итоговая сумма не отрицательная
        if ($line_total < 0) {
            $line_total = 0;
        }

        $items = [];

        // Если скидка есть и товаров больше одного, распределяем скидку
        if ($item_discount > 0 && $quantity > 1) {
            $items = $this->splitDiscountedItem($item, $unit_price, $item_discount, $quantity);
        } else {
            // Простой случай - один товар или нет скидки
            $discounted_price = $this->formatMoney($line_total / $quantity);

            $items[] = [
                'productId' => (string)$product->get_id(),
                'title' => $this->sanitizeTitle($item->get_name()),
                'quantity' => ['count' => (string)$quantity],
                'unitPrice' => $this->formatMoneyString($unit_price),
                'discountedUnitPrice' => $this->formatMoneyString($discounted_price),
                'subtotal' => $this->formatMoneyString($line_subtotal),
                'total' => $this->formatMoneyString($line_total),
                'measurements' => $this->getProductMeasurements($product)
            ];
        }

        return $items;
    }

    /**
     * Разделить товар со скидкой на отдельные позиции
     * 
     * @param WC_Order_Item_Product $item Товар
     * @param float $unit_price Цена за единицу
     * @param float $total_discount Общая скидка
     * @param int $quantity Количество
     * @return array
     */
    private function splitDiscountedItem(
        WC_Order_Item_Product $item,
        float $unit_price,
        float $total_discount,
        int $quantity
    ): array {
        $items = [];
        $product = $item->get_product();

        // Рассчитываем скидку на единицу товара
        $per_item_discount = floor($total_discount * 100 / $quantity) / 100;
        $remainder = $this->formatMoney($total_discount - ($per_item_discount * $quantity));

        // Создаем отдельную позицию для каждой единицы товара
        for ($i = 1; $i <= $quantity; $i++) {
            // Последнему товару добавляем остаток скидки
            if ($i == $quantity && $remainder > 0) {
                $discounted_price = $this->formatMoney($unit_price - $per_item_discount - $remainder);
            } else {
                $discounted_price = $this->formatMoney($unit_price - $per_item_discount);
            }

            // Убеждаемся, что цена не отрицательная
            if ($discounted_price < 0) {
                $discounted_price = 0;
            }

            $items[] = [
                'productId' => (string)$product->get_id() . '-' . $i,
                'title' => $this->sanitizeTitle($item->get_name()),
                'quantity' => ['count' => '1'],
                'unitPrice' => $this->formatMoneyString($unit_price),
                'discountedUnitPrice' => $this->formatMoneyString($discounted_price),
                'subtotal' => $this->formatMoneyString($unit_price),
                'total' => $this->formatMoneyString($discounted_price),
                'measurements' => $this->getProductMeasurements($product)
            ];
        }

        return $items;
    }

    /**
     * Обработать доставку
     * 
     * @param WC_Order_Item_Shipping $shipping_item Доставка
     * @return array
     */
    private function processShippingItem(WC_Order_Item_Shipping $shipping_item): array {
        $shipping_cost = $this->formatMoney($shipping_item->get_total());
        $shipping_tax = $this->formatMoney($shipping_item->get_total_tax());
        $shipping_total = $this->formatMoney($shipping_cost + $shipping_tax);

        // Проверяем наличие скидки на доставку
        $shipping_discount = 0;
        if (method_exists($shipping_item, 'get_meta')) {
            $discount_amount = $shipping_item->get_meta('discount_amount');
            if ($discount_amount) {
                $shipping_discount = $this->formatMoney($discount_amount);
            }
        }

        $shipping_subtotal = $this->formatMoney($shipping_total + $shipping_discount);

        return [
            'productId' => 'shipping-' . $shipping_item->get_id(),
            'title' => $this->sanitizeTitle($shipping_item->get_name()),
            'quantity' => ['count' => '1'],
            'unitPrice' => $this->formatMoneyString($shipping_subtotal),
            'discountedUnitPrice' => $this->formatMoneyString($shipping_total),
            'subtotal' => $this->formatMoneyString($shipping_subtotal),
            'total' => $this->formatMoneyString($shipping_total),
            'type' => 'DELIVERY'
        ];
    }

    /**
     * Обработать дополнительный сбор
     * 
     * @param WC_Order_Item_Fee $fee_item Сбор
     * @return array|null
     */
    private function processFeeItem(WC_Order_Item_Fee $fee_item): ?array {
        $fee_amount = $this->formatMoney($fee_item->get_total());

        // Пропускаем отрицательные сборы (они обрабатываются как скидки)
        if ($fee_amount < 0) {
            return null;
        }

        $fee_tax = $this->formatMoney($fee_item->get_total_tax());
        $fee_total = $this->formatMoney($fee_amount + $fee_tax);

        return [
            'productId' => 'fee-' . $fee_item->get_id(),
            'title' => $this->sanitizeTitle($fee_item->get_name()),
            'quantity' => ['count' => '1'],
            'unitPrice' => $this->formatMoneyString($fee_total),
            'discountedUnitPrice' => $this->formatMoneyString($fee_total),
            'subtotal' => $this->formatMoneyString($fee_total),
            'total' => $this->formatMoneyString($fee_total),
            'type' => 'SERVICE'
        ];
    }

    /**
     * Получить измерения продукта
     * 
     * @param \WC_Product $product
     * @return array
     */
    private function getProductMeasurements(\WC_Product $product): array {
        $measurements = [];

        // Вес
        if ($product->has_weight()) {
            $weight_unit = get_option('woocommerce_weight_unit');
            $weight = $product->get_weight();

            // Конвертируем в граммы для API
            $weight_in_grams = $this->convertWeightToGrams($weight, $weight_unit);
            if ($weight_in_grams > 0) {
                $measurements['weight'] = (int)$weight_in_grams;
            }
        }

        // Размеры
        if ($product->has_dimensions()) {
            $dimension_unit = get_option('woocommerce_dimension_unit');
            $length = $product->get_length();
            $width = $product->get_width();
            $height = $product->get_height();

            // Конвертируем в сантиметры для API
            if ($length) {
                $measurements['length'] = $this->convertDimensionToCm($length, $dimension_unit);
            }
            if ($width) {
                $measurements['width'] = $this->convertDimensionToCm($width, $dimension_unit);
            }
            if ($height) {
                $measurements['height'] = $this->convertDimensionToCm($height, $dimension_unit);
            }
        }

        return $measurements;
    }

    /**
     * Конвертировать вес в граммы
     * 
     * @param float $weight Вес
     * @param string $unit Единица измерения
     * @return int
     */
    private function convertWeightToGrams(float $weight, string $unit): int {
        switch ($unit) {
            case 'kg':
                return (int)($weight * 1000);
            case 'g':
                return (int)$weight;
            case 'lbs':
                return (int)($weight * 453.592);
            case 'oz':
                return (int)($weight * 28.3495);
            default:
                return (int)$weight;
        }
    }

    /**
     * Конвертировать размер в сантиметры
     * 
     * @param float $dimension Размер
     * @param string $unit Единица измерения
     * @return int
     */
    private function convertDimensionToCm(float $dimension, string $unit): int {
        switch ($unit) {
            case 'm':
                return (int)($dimension * 100);
            case 'cm':
                return (int)$dimension;
            case 'mm':
                return (int)($dimension / 10);
            case 'in':
                return (int)($dimension * 2.54);
            case 'yd':
                return (int)($dimension * 91.44);
            default:
                return (int)$dimension;
        }
    }

    /**
     * Корректировка для точного соответствия итоговой суммы
     * 
     * @param array &$cart_items Позиции корзины
     * @param float $target_total Целевая сумма
     * @param float $items_sum Сумма позиций
     * @param float $discounts_sum Сумма скидок
     */
    private function adjustTotalPrecision(
        array &$cart_items,
        float $target_total,
        float $items_sum,
        float $discounts_sum
    ): void {
        if (empty($cart_items)) {
            return;
        }

        $calculated_total = $this->formatMoney($items_sum - $discounts_sum);
        $difference = $this->formatMoney($target_total - $calculated_total);

        // Если разница меньше копейки, игнорируем
        if (abs($difference) < 0.01) {
            return;
        }

        // Корректируем последнюю позицию
        $last_index = count($cart_items) - 1;
        $last_item = &$cart_items[$last_index];

        $current_total = (float)$last_item['total'];
        $new_total = $this->formatMoney($current_total + $difference);

        // Убеждаемся, что сумма не отрицательная
        if ($new_total >= 0) {
            $last_item['total'] = $this->formatMoneyString($new_total);
            $last_item['discountedUnitPrice'] = $last_item['total'];
        }
    }

    /**
     * Форматировать денежную сумму
     * 
     * @param float $amount
     * @return float
     */
    private function formatMoney(float $amount): float {
        return round($amount, self::MONEY_PRECISION);
    }

    /**
     * Форматировать денежную сумму в строку
     * 
     * @param float $amount
     * @return string
     */
    private function formatMoneyString(float $amount): string {
        return number_format($amount, self::MONEY_PRECISION, '.', '');
    }

    /**
     * Очистить название товара
     * 
     * @param string $title
     * @return string
     */
    private function sanitizeTitle(string $title): string {
        // Удаляем HTML теги
        $title = wp_strip_all_tags($title);

        // Ограничиваем длину (API Яндекс может иметь ограничения)
        if (mb_strlen($title) > 128) {
            $title = mb_substr($title, 0, 125) . '...';
        }

        return $title;
    }
}
