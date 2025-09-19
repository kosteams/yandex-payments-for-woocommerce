<?php

namespace KosTeams\YandexPayments\Gateways;

/**
 * Платежный шлюз Яндекс Pay
 * 
 * Реализация платежного шлюза для приема оплаты картами
 * через сервис Яндекс Pay
 * 
 * @package KosTeams\YandexPayments\Gateways
 */
class YandexPayGateway extends AbstractGateway {

    /**
     * Конструктор
     * 
     * @param \KosTeams\YandexPayments\Core\Container $container
     */
    public function __construct($container) {
        // Устанавливаем ID шлюза
        $this->id = 'kosteams-yandex-pay';

        // Устанавливаем параметры
        $this->has_fields = true;
        $this->method_title = __('Яндекс Pay', 'kosteams-payments-for-yandex');
        $this->method_description = __('Принимайте оплату через Yandex Pay. Увеличьте конверсию за счет быстрой оплаты.', 'kosteams-payments-for-yandex');

        // Иконка платежного метода
        $this->icon = KOSTEAMS_YANDEX_URL . 'assets/image/Pay-Logo.svg';

        // Поддерживаемые функции
        $this->supports = [
            'products',
            'refunds'
        ];

        parent::__construct($container);
    }

    /**
     * Получить доступные методы оплаты
     * 
     * @return array
     */
    protected function getAvailablePaymentMethods(): array {
        // Яндекс Pay поддерживает только оплату картой
        return ['CARD'];
    }

    /**
     * Получить заголовок по умолчанию
     * 
     * @return string
     */
    protected function getDefaultTitle(): string {
        return __('Яндекс Pay', 'kosteams-payments-for-yandex');
    }

    /**
     * Получить описание по умолчанию
     * 
     * @return string
     */
    protected function getDefaultDescription(): string {
        return __('Быстрая и безопасная оплата картой через Яндекс Pay', 'kosteams-payments-for-yandex');
    }

    /**
     * Дополнение базовых полей настроек
     */
    public function init_form_fields(): void {
        parent::init_form_fields();

        // Добавляем специфичные настройки для Яндекс Pay
        $this->form_fields['payment_method'] = [
            'title' => __('Способ оплаты', 'kosteams-payments-for-yandex'),
            'type' => 'select',
            'description' => __('Выберите способ оплаты через Яндекс Pay', 'kosteams-payments-for-yandex'),
            'default' => 'card',
            'options' => [
                'card' => __('Банковская карта', 'kosteams-payments-for-yandex')
            ]
        ];

        // Настройки отображения
        $this->form_fields['display_settings'] = [
            'title' => __('Настройки отображения', 'kosteams-payments-for-yandex'),
            'type' => 'title',
            'description' => __('Настройте, как будет отображаться метод оплаты', 'kosteams-payments-for-yandex')
        ];

        $this->form_fields['show_badge'] = [
            'title' => __('Показать бейдж кэшбека', 'kosteams-payments-for-yandex'),
            'type' => 'checkbox',
            'label' => __('Отображать информацию о возможном кэшбеке', 'kosteams-payments-for-yandex'),
            'default' => 'yes',
            'description' => __('Показывает покупателям информацию о возможности получения кэшбека при оплате', 'kosteams-payments-for-yandex')
        ];

        $this->form_fields['button_style'] = [
            'title' => __('Стиль кнопки оплаты', 'kosteams-payments-for-yandex'),
            'type' => 'select',
            'default' => 'black',
            'options' => [
                'black' => __('Черная кнопка', 'kosteams-payments-for-yandex'),
                'white' => __('Белая кнопка', 'kosteams-payments-for-yandex'),
                'outlined' => __('С обводкой', 'kosteams-payments-for-yandex')
            ],
            'description' => __('Выберите стиль кнопки Яндекс Pay', 'kosteams-payments-for-yandex')
        ];

        // Уведомление о связи с Яндекс Сплит
        if ($this->isYandexSplitEnabled()) {
            $split_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-split');
            $this->form_fields['split_notice'] = [
                'type' => 'title',
                'description' => sprintf(
                    '<div class="notice notice-info inline">
                        <p>%s <a href="%s">%s</a></p>
                    </div>',
                    __('Дополнительные настройки доступны в', 'kosteams-payments-for-yandex'),
                    esc_url($split_url),
                    __('настройках Яндекс Сплит', 'kosteams-payments-for-yandex')
                )
            ];
        }
    }

    /**
     * Отрисовка специфичных полей на странице оплаты
     */
    protected function renderGatewaySpecificFields(): void {
        // Показываем бейдж кэшбека, если включено
        if ($this->get_option('show_badge', 'yes') === 'yes') {
            echo '<div class="yandex-pay-cashback-badge">';
            echo '<img src="' . esc_url(KOSTEAMS_YANDEX_URL . 'assets/image/cashback.svg') . '" alt="' .
                esc_attr__('Кэшбек', 'kosteams-payments-for-yandex') . '" height="20">';
            echo '<span>' . __('Возможен кэшбек до 3%', 'kosteams-payments-for-yandex') . '</span>';
            echo '</div>';
        }

        // Добавляем скрытое поле для device_id (для антифрода)
        echo '<input type="hidden" id="yandex_pay_device_id" name="yandex_pay_device_id" value="">';

        // JavaScript для получения device fingerprint
        $this->renderDeviceFingerprintScript();
    }

    /**
     * Рендеринг скрипта для получения отпечатка устройства
     */
    private function renderDeviceFingerprintScript(): void {
?>
        <script type="text/javascript">
            (function() {
                // Простой fingerprint на основе доступных данных браузера
                function getDeviceId() {
                    var fingerprint = '';

                    // Screen resolution
                    fingerprint += screen.width + 'x' + screen.height + '-';

                    // Timezone
                    fingerprint += new Date().getTimezoneOffset() + '-';

                    // User agent
                    fingerprint += navigator.userAgent.replace(/[^a-zA-Z0-9]/g, '').substr(0, 50) + '-';

                    // Language
                    fingerprint += navigator.language + '-';

                    // Random component for this session
                    fingerprint += Math.random().toString(36).substr(2, 9);

                    return btoa(fingerprint).replace(/[^a-zA-Z0-9]/g, '').substr(0, 32);
                }

                document.getElementById('yandex_pay_device_id').value = getDeviceId();
            })();
        </script>
<?php
    }

    /**
     * Валидация специфичных полей
     * 
     * @return bool
     */
    protected function validateGatewaySpecificFields(): bool {
        // Проверяем device_id если включен антифрод
        if (isset($_POST['yandex_pay_device_id'])) {
            $device_id = sanitize_text_field($_POST['yandex_pay_device_id']);

            if (empty($device_id)) {
                wc_add_notice(
                    __('Не удалось определить устройство. Попробуйте обновить страницу.', 'kosteams-payments-for-yandex'),
                    'error'
                );
                return false;
            }

            // Сохраняем device_id в сессии для последующего использования
            WC()->session->set('yandex_pay_device_id', $device_id);
        }

        return true;
    }

    /**
     * Проверка доступности шлюза
     * 
     * @return bool
     */
    protected function checkGatewaySpecificAvailability(): bool {
        // Проверяем минимальную сумму заказа
        $min_amount = $this->get_option('min_amount', 0);
        if ($min_amount > 0 && WC()->cart) {
            $total = WC()->cart->get_total('numeric');
            if ($total < $min_amount) {
                return false;
            }
        }

        // Проверяем максимальную сумму заказа
        $max_amount = $this->get_option('max_amount', 0);
        if ($max_amount > 0 && WC()->cart) {
            $total = WC()->cart->get_total('numeric');
            if ($total > $max_amount) {
                return false;
            }
        }

        // Проверяем доступность для текущей валюты
        if (WC()->cart) {
            $currency = get_woocommerce_currency();
            $supported_currencies = ['RUB', 'BYN', 'KZT'];

            if (!in_array($currency, $supported_currencies)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Обработка платежа с дополнительными данными
     * 
     * @param int $order_id ID заказа
     * @return array
     */
    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        // Сохраняем device_id в заказе для антифрода
        if (WC()->session->get('yandex_pay_device_id')) {
            $order->update_meta_data('device_id', WC()->session->get('yandex_pay_device_id'));
            $order->save();

            // Очищаем из сессии
            WC()->session->set('yandex_pay_device_id', null);
        }

        // Добавляем метаданные о способе оплаты
        $order->update_meta_data('_yandex_payment_gateway', 'yandex_pay');
        $order->update_meta_data('_yandex_payment_method', 'CARD');
        $order->save();

        // Вызываем родительский метод для обработки
        return parent::process_payment($order_id);
    }

    /**
     * Проверить, включен ли Яндекс Сплит
     * 
     * @return bool
     */
    private function isYandexSplitEnabled(): bool {
        $split_settings = get_option('woocommerce_kosteams-yandex-split_settings', []);
        return isset($split_settings['enabled']) && $split_settings['enabled'] === 'yes';
    }

    /**
     * Получить настройки из связанного шлюза Яндекс Сплит
     * 
     * @param string $key Ключ настройки
     * @param mixed $empty_value Значение по умолчанию
     * @return mixed
     */
    public function get_option($key, $empty_value = null) {
        // Общие настройки берем из Яндекс Сплит, если он включен
        $shared_settings = ['merchant_id', 'api_key', 'test_mode', 'debug_mode'];

        if (in_array($key, $shared_settings) && $this->isYandexSplitEnabled()) {
            $split_settings = get_option('woocommerce_kosteams-yandex-split_settings', []);
            if (isset($split_settings[$key])) {
                return $split_settings[$key];
            }
        }

        return parent::get_option($key, $empty_value);
    }

    /**
     * AJAX обработчик для проверки доступности метода
     */
    public function ajax_check_availability(): void {
        check_ajax_referer('yandex_pay_nonce', 'nonce');

        $available = $this->is_available();
        $reasons = [];

        if (!$available) {
            // Собираем причины недоступности
            if (empty($this->merchant_id) || empty($this->api_key)) {
                $reasons[] = __('Не настроены учетные данные', 'kosteams-payments-for-yandex');
            }

            $currency = get_woocommerce_currency();
            if (!in_array($currency, ['RUB', 'BYN', 'KZT'])) {
                $reasons[] = __('Валюта не поддерживается', 'kosteams-payments-for-yandex');
            }
        }

        wp_send_json([
            'available' => $available,
            'reasons' => $reasons
        ]);
    }
}
