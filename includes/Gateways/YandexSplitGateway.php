<?php

namespace KosTeams\YandexPayments\Gateways;

/**
 * Платежный шлюз Яндекс Сплит
 * 
 * Реализация платежного шлюза для приема оплаты в рассрочку
 * через сервис Яндекс Сплит
 * 
 * @package KosTeams\YandexPayments\Gateways
 */
class YandexSplitGateway extends AbstractGateway {

    /**
     * Выбранный метод оплаты (split или card_split)
     * 
     * @var string
     */
    private string $payment_method_type;

    /**
     * Конструктор
     * 
     * @param \KosTeams\YandexPayments\Core\Container $container
     */
    public function __construct($container) {
        // Устанавливаем ID шлюза
        $this->id = 'kosteams-yandex-split';

        // Устанавливаем параметры
        $this->has_fields = true;
        $this->method_title = __('Яндекс Сплит', 'kosteams-payments-for-yandex');
        $this->method_description = __('Принимайте оплату через Яндекс Сплит или комбинацию с Яндекс Пей. Увеличьте конверсию за счет гибких способов оплаты.', 'kosteams-payments-for-yandex');

        // Поддерживаемые функции
        $this->supports = [
            'products',
            'refunds'
        ];

        parent::__construct($container);

        // Загружаем дополнительные настройки
        $this->payment_method_type = $this->get_option('payment_method', 'split');

        // Определяем иконку на основе выбранного метода
        $this->determineIcon();
    }

    /**
     * Определить иконку платежного метода
     */
    private function determineIcon(): void {
        if ($this->payment_method_type === 'card_split') {
            $this->icon = KOSTEAMS_YANDEX_URL . 'assets/image/split-ru.svg';
        } else {
            $this->icon = KOSTEAMS_YANDEX_URL . 'assets/image/split-Logo.svg';
        }
    }

    /**
     * Получить доступные методы оплаты
     * 
     * @return array
     */
    protected function getAvailablePaymentMethods(): array {
        switch ($this->payment_method_type) {
            case 'split':
                return ['SPLIT'];

            case 'card_split':
                return ['CARD', 'SPLIT'];

            default:
                return ['SPLIT'];
        }
    }

    /**
     * Получить заголовок по умолчанию
     * 
     * @return string
     */
    protected function getDefaultTitle(): string {
        return __('Яндекс Сплит', 'kosteams-payments-for-yandex');
    }

    /**
     * Получить описание по умолчанию
     * 
     * @return string
     */
    protected function getDefaultDescription(): string {
        return __('Оплата частями без переплат. Первый платеж картой, остальное — частями.', 'kosteams-payments-for-yandex');
    }

    /**
     * Дополнение базовых полей настроек
     */
    public function init_form_fields(): void {
        parent::init_form_fields();

        // Добавляем специфичные настройки для Яндекс Сплит
        $this->form_fields['payment_method'] = [
            'title' => __('Способ оплаты', 'kosteams-payments-for-yandex'),
            'type' => 'select',
            'description' => __('Выберите доступные способы оплаты', 'kosteams-payments-for-yandex'),
            'default' => 'split',
            'options' => [
                'split' => __('Только Сплит (рассрочка)', 'kosteams-payments-for-yandex'),
                'card_split' => __('Карта + Сплит (комбинированная оплата)', 'kosteams-payments-for-yandex')
            ],
            'desc_tip' => true
        ];

        // Настройки рассрочки
        $this->form_fields['split_settings'] = [
            'title' => __('Настройки рассрочки', 'kosteams-payments-for-yandex'),
            'type' => 'title',
            'description' => __('Настройте параметры рассрочки для покупателей', 'kosteams-payments-for-yandex')
        ];

        $this->form_fields['min_split_amount'] = [
            'title' => __('Минимальная сумма для рассрочки', 'kosteams-payments-for-yandex'),
            'type' => 'number',
            'description' => __('Минимальная сумма заказа для возможности оплаты в рассрочку (в рублях)', 'kosteams-payments-for-yandex'),
            'default' => '3000',
            'custom_attributes' => [
                'min' => '0',
                'step' => '100'
            ]
        ];

        $this->form_fields['max_split_amount'] = [
            'title' => __('Максимальная сумма для рассрочки', 'kosteams-payments-for-yandex'),
            'type' => 'number',
            'description' => __('Максимальная сумма заказа для возможности оплаты в рассрочку (в рублях)', 'kosteams-payments-for-yandex'),
            'default' => '150000',
            'custom_attributes' => [
                'min' => '0',
                'step' => '1000'
            ]
        ];

        $this->form_fields['split_periods'] = [
            'title' => __('Доступные периоды рассрочки', 'kosteams-payments-for-yandex'),
            'type' => 'multiselect',
            'description' => __('Выберите доступные периоды рассрочки для покупателей', 'kosteams-payments-for-yandex'),
            'default' => ['2', '4'],
            'options' => [
                '2' => __('2 платежа', 'kosteams-payments-for-yandex'),
                '3' => __('3 платежа', 'kosteams-payments-for-yandex'),
                '4' => __('4 платежа', 'kosteams-payments-for-yandex'),
                '6' => __('6 платежей', 'kosteams-payments-for-yandex')
            ],
            'class' => 'wc-enhanced-select'
        ];

        // Настройки отображения
        $this->form_fields['display_settings'] = [
            'title' => __('Настройки отображения', 'kosteams-payments-for-yandex'),
            'type' => 'title'
        ];

        $this->form_fields['show_split_badge'] = [
            'title' => __('Показать бейдж рассрочки', 'kosteams-payments-for-yandex'),
            'type' => 'checkbox',
            'label' => __('Отображать информацию о рассрочке в каталоге товаров', 'kosteams-payments-for-yandex'),
            'default' => 'yes',
            'description' => __('Показывает бейдж "Доступна рассрочка" на карточках товаров', 'kosteams-payments-for-yandex')
        ];

        $this->form_fields['show_split_calculator'] = [
            'title' => __('Показать калькулятор рассрочки', 'kosteams-payments-for-yandex'),
            'type' => 'checkbox',
            'label' => __('Отображать калькулятор рассрочки на странице товара', 'kosteams-payments-for-yandex'),
            'default' => 'yes',
            'description' => __('Показывает калькулятор с примерным расчетом платежей', 'kosteams-payments-for-yandex')
        ];

        // Уведомление о связи с основным шлюзом
        $pay_gateway_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-pay');
        $this->form_fields['main_gateway_notice'] = [
            'type' => 'title',
            'description' => sprintf(
                '<div class="notice notice-info inline">
                    <p><strong>%s</strong> %s <a href="%s" target="_blank">%s</a>.</p>
                </div>',
                __('Внимание!', 'kosteams-payments-for-yandex'),
                __('Основные настройки (Merchant ID, API-ключ) находятся в', 'kosteams-payments-for-yandex'),
                esc_url($pay_gateway_url),
                __('настройках Яндекс Пей', 'kosteams-payments-for-yandex')
            )
        ];

        // Убираем дублирующие поля из родительского класса
        unset(
            $this->form_fields['merchant_id'],
            $this->form_fields['api_key'],
            $this->form_fields['test_mode'],
            $this->form_fields['debug_mode'],
            $this->form_fields['callback_url_info']
        );
    }

    /**
     * Отрисовка специфичных полей на странице оплаты
     */
    protected function renderGatewaySpecificFields(): void {
        $order_total = WC()->cart ? WC()->cart->get_total('numeric') : 0;

        // Показываем информацию о рассрочке
        if ($this->shouldShowSplitInfo($order_total)) {
            $this->renderSplitInfo($order_total);
        }

        // Показываем калькулятор рассрочки
        if ($this->get_option('show_split_calculator', 'yes') === 'yes') {
            $this->renderSplitCalculator($order_total);
        }

        // Хук для дополнительных полей
        do_action('kosteams_yandex_split_payment_fields', $this);
    }

    /**
     * Проверить, нужно ли показывать информацию о рассрочке
     * 
     * @param float $order_total
     * @return bool
     */
    private function shouldShowSplitInfo(float $order_total): bool {
        $min_amount = (float)$this->get_option('min_split_amount', 3000);
        $max_amount = (float)$this->get_option('max_split_amount', 150000);

        return $order_total >= $min_amount && $order_total <= $max_amount;
    }

    /**
     * Отрисовать информацию о рассрочке
     * 
     * @param float $order_total
     */
    private function renderSplitInfo(float $order_total): void {
        $periods = $this->get_option('split_periods', ['2', '4']);

        if (empty($periods)) {
            return;
        }

?>
        <div class="yandex-split-info">
            <div class="split-info-header">
                <img src="<?php echo esc_url(KOSTEAMS_YANDEX_URL . 'assets/image/bnpl.svg'); ?>"
                    alt="<?php esc_attr_e('Рассрочка', 'kosteams-payments-for-yandex'); ?>"
                    height="20">
                <strong><?php esc_html_e('Доступна рассрочка без переплат', 'kosteams-payments-for-yandex'); ?></strong>
            </div>

            <div class="split-info-details">
                <p><?php esc_html_e('Разделите оплату на части:', 'kosteams-payments-for-yandex'); ?></p>
                <ul class="split-periods">
                    <?php foreach ($periods as $period): ?>
                        <li>
                            <?php
                            $payment_amount = $order_total / intval($period);
                            echo sprintf(
                                __('%d платежа по %s', 'kosteams-payments-for-yandex'),
                                intval($period),
                                wc_price($payment_amount)
                            );
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php
    }

    /**
     * Отрисовать калькулятор рассрочки
     * 
     * @param float $order_total
     */
    private function renderSplitCalculator(float $order_total): void {
        if (!$this->shouldShowSplitInfo($order_total)) {
            return;
        }

        $periods = $this->get_option('split_periods', ['2', '4']);
        $default_period = reset($periods);

    ?>
        <div class="yandex-split-calculator" data-total="<?php echo esc_attr($order_total); ?>">
            <h4><?php esc_html_e('Калькулятор рассрочки', 'kosteams-payments-for-yandex'); ?></h4>

            <div class="calculator-controls">
                <label for="split-period-select">
                    <?php esc_html_e('Выберите количество платежей:', 'kosteams-payments-for-yandex'); ?>
                </label>
                <select id="split-period-select" class="split-period-select">
                    <?php foreach ($periods as $period): ?>
                        <option value="<?php echo esc_attr($period); ?>"
                            <?php selected($period, $default_period); ?>>
                            <?php echo sprintf(
                                _n('%d платеж', '%d платежа', intval($period), 'kosteams-payments-for-yandex'),
                                intval($period)
                            ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="calculator-result">
                <div class="payment-schedule">
                    <div class="first-payment">
                        <span><?php esc_html_e('Первый платеж сегодня:', 'kosteams-payments-for-yandex'); ?></span>
                        <strong class="first-payment-amount"><?php echo wc_price($order_total / intval($default_period)); ?></strong>
                    </div>
                    <div class="next-payments">
                        <span><?php esc_html_e('Далее каждые 2 недели:', 'kosteams-payments-for-yandex'); ?></span>
                        <strong class="next-payment-amount"><?php echo wc_price($order_total / intval($default_period)); ?></strong>
                    </div>
                </div>

                <div class="split-benefits">
                    <ul>
                        <li>✓ <?php esc_html_e('Без переплат и скрытых комиссий', 'kosteams-payments-for-yandex'); ?></li>
                        <li>✓ <?php esc_html_e('Моментальное одобрение', 'kosteams-payments-for-yandex'); ?></li>
                        <li>✓ <?php esc_html_e('Автоматическое списание платежей', 'kosteams-payments-for-yandex'); ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(function($) {
                $('#split-period-select').on('change', function() {
                    var periods = $(this).val();
                    var total = $('.yandex-split-calculator').data('total');
                    var paymentAmount = total / periods;

                    $('.first-payment-amount').html('<?php echo get_woocommerce_currency_symbol(); ?>' + paymentAmount.toFixed(2));
                    $('.next-payment-amount').html('<?php echo get_woocommerce_currency_symbol(); ?>' + paymentAmount.toFixed(2));
                });
            });
        </script>
<?php
    }

    /**
     * Валидация специфичных полей
     * 
     * @return bool
     */
    protected function validateGatewaySpecificFields(): bool {
        if (!WC()->cart) {
            return false;
        }

        $total = WC()->cart->get_total('numeric');
        $min_amount = (float)$this->get_option('min_split_amount', 3000);
        $max_amount = (float)$this->get_option('max_split_amount', 150000);

        // Проверяем сумму заказа для рассрочки
        if (in_array('SPLIT', $this->getAvailablePaymentMethods())) {
            if ($total < $min_amount) {
                wc_add_notice(
                    sprintf(
                        __('Минимальная сумма для оплаты в рассрочку: %s', 'kosteams-payments-for-yandex'),
                        wc_price($min_amount)
                    ),
                    'error'
                );
                return false;
            }

            if ($total > $max_amount) {
                wc_add_notice(
                    sprintf(
                        __('Максимальная сумма для оплаты в рассрочку: %s', 'kosteams-payments-for-yandex'),
                        wc_price($max_amount)
                    ),
                    'error'
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Проверка доступности шлюза
     * 
     * @return bool
     */
    protected function checkGatewaySpecificAvailability(): bool {
        // Проверяем сумму заказа
        if (WC()->cart) {
            $total = WC()->cart->get_total('numeric');

            // Если выбран только SPLIT, проверяем минимальную сумму
            if ($this->payment_method_type === 'split') {
                $min_amount = (float)$this->get_option('min_split_amount', 3000);
                if ($total < $min_amount) {
                    return false;
                }
            }

            // Проверяем валюту
            $currency = get_woocommerce_currency();
            if (!in_array($currency, ['RUB'])) {
                return false; // Сплит доступен только для рублей
            }
        }

        return true;
    }

    /**
     * Получить настройки из основного шлюза
     * 
     * Переопределяем метод для получения общих настроек из Яндекс Pay
     * 
     * @param string $key
     * @param mixed $empty_value
     * @return mixed
     */
    public function get_option($key, $empty_value = null) {
        // Общие настройки берем из основного шлюза
        $shared_settings = ['merchant_id', 'api_key', 'test_mode', 'debug_mode'];

        if (in_array($key, $shared_settings)) {
            $main_settings = get_option('woocommerce_kosteams-yandex-pay_settings', []);
            if (isset($main_settings[$key])) {
                return $main_settings[$key];
            }
        }

        return parent::get_option($key, $empty_value);
    }

    /**
     * Обработка платежа с дополнительными параметрами
     * 
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        // Добавляем метаданные о типе рассрочки
        if (isset($_POST['split_period'])) {
            $split_period = sanitize_text_field($_POST['split_period']);
            $order->update_meta_data('_yandex_split_period', $split_period);
        }

        // Добавляем метаданные о способе оплаты
        $order->update_meta_data('_yandex_payment_gateway', 'yandex_split');
        $order->update_meta_data('_yandex_payment_methods', implode(',', $this->getAvailablePaymentMethods()));
        $order->save();

        // Вызываем родительский метод
        return parent::process_payment($order_id);
    }

    /**
     * Добавить бейджи рассрочки в каталог
     * 
     * Этот метод можно вызывать через хуки WooCommerce
     */
    public function addSplitBadgeToCatalog(): void {
        if ($this->get_option('show_split_badge', 'yes') !== 'yes') {
            return;
        }

        global $product;

        if (!$product) {
            return;
        }

        $price = $product->get_price();
        $min_amount = (float)$this->get_option('min_split_amount', 3000);
        $max_amount = (float)$this->get_option('max_split_amount', 150000);

        if ($price >= $min_amount && $price <= $max_amount) {
            echo '<div class="yandex-split-badge">';
            echo '<img src="' . esc_url(KOSTEAMS_YANDEX_URL . 'assets/image/bnpl.svg') . '" alt="Split" height="15">';
            echo '<span>' . esc_html__('Рассрочка', 'kosteams-payments-for-yandex') . '</span>';
            echo '</div>';
        }
    }
}
