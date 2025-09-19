<?php

namespace KosTeams\YandexPayments\Gateways;

use KosTeams\YandexPayments\Core\Container;
use KosTeams\YandexPayments\Payment\PaymentProcessor;
use KosTeams\YandexPayments\API\YandexApiClient;
use KosTeams\YandexPayments\Utils\Logger;
use WC_Payment_Gateway;
use WC_Order;

/**
 * Базовый класс платежного шлюза
 * 
 * Абстрактный класс, от которого наследуются конкретные реализации
 * платежных шлюзов (Яндекс Pay и Яндекс Сплит). Содержит общую
 * логику и делегирует специфические операции соответствующим сервисам.
 * 
 * @package KosTeams\YandexPayments\Gateways
 */
abstract class AbstractGateway extends WC_Payment_Gateway {

    /**
     * DI контейнер для доступа к сервисам
     * 
     * @var Container
     */
    protected Container $container;

    /**
     * Процессор платежей
     * 
     * @var PaymentProcessor
     */
    protected PaymentProcessor $payment_processor;

    /**
     * API клиент
     * 
     * @var YandexApiClient
     */
    protected YandexApiClient $api_client;

    /**
     * Логгер
     * 
     * @var Logger
     */
    protected Logger $logger;

    /**
     * Merchant ID из настроек
     * 
     * @var string
     */
    protected string $merchant_id;

    /**
     * API ключ из настроек
     * 
     * @var string
     */
    protected string $api_key;

    /**
     * Режим песочницы
     * 
     * @var bool
     */
    protected bool $test_mode;

    /**
     * Режим отладки
     * 
     * @var bool
     */
    protected bool $debug_mode;

    /**
     * Конструктор
     * 
     * @param Container $container DI контейнер
     */
    public function __construct(Container $container) {
        $this->container = $container;

        // Инициализация сервисов из контейнера
        $this->initializeServices();

        // Инициализация базовых настроек WooCommerce
        $this->init_form_fields();
        $this->init_settings();

        // Загрузка настроек
        $this->loadSettings();

        // Настройка API клиента
        $this->configureApiClient();

        // Регистрация хуков
        $this->registerHooks();
    }

    /**
     * Инициализация сервисов из контейнера
     */
    protected function initializeServices(): void {
        $this->payment_processor = $this->container->get('payment_processor');
        $this->api_client = $this->container->get('api_client');
        $this->logger = $this->container->get('logger');
    }

    /**
     * Загрузка настроек шлюза
     */
    protected function loadSettings(): void {
        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->merchant_id = $this->get_option('merchant_id', '');
        $this->api_key = $this->get_option('api_key', '');
        $this->test_mode = $this->get_option('test_mode', 'no') === 'yes';
        $this->debug_mode = $this->get_option('debug_mode', 'no') === 'yes';

        // Настройка уровня логирования
        if ($this->debug_mode) {
            $this->logger->setLevel(Logger::DEBUG);
        }
    }

    /**
     * Настройка API клиента с учетными данными
     */
    protected function configureApiClient(): void {
        if (!empty($this->merchant_id) && !empty($this->api_key)) {
            $this->api_client->setCredentials(
                $this->merchant_id,
                $this->api_key,
                $this->test_mode
            );
        }
    }

    /**
     * Регистрация хуков WordPress/WooCommerce
     */
    protected function registerHooks(): void {
        // Сохранение настроек админки
        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );

        // Обработка вебхуков (если требуется для конкретного шлюза)
        if ($this->supportsWebhooks()) {
            add_action(
                'woocommerce_api_' . $this->id,
                [$this, 'handleWebhook']
            );
        }
    }

    /**
     * Инициализация полей настроек
     * 
     * Базовая реализация с общими полями
     */
    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Включить/Выключить', 'kosteams-payments-for-yandex'),
                'type' => 'checkbox',
                'label' => __('Включить этот способ оплаты', 'kosteams-payments-for-yandex'),
                'default' => 'yes'
            ],
            'title' => [
                'title' => __('Название', 'kosteams-payments-for-yandex'),
                'type' => 'text',
                'description' => __('Название, отображаемое покупателю при оформлении заказа', 'kosteams-payments-for-yandex'),
                'default' => $this->getDefaultTitle(),
                'desc_tip' => true
            ],
            'description' => [
                'title' => __('Описание', 'kosteams-payments-for-yandex'),
                'type' => 'textarea',
                'description' => __('Описание способа оплаты для покупателя', 'kosteams-payments-for-yandex'),
                'default' => $this->getDefaultDescription(),
                'desc_tip' => true
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'kosteams-payments-for-yandex'),
                'type' => 'text',
                'description' => __('Уникальный идентификатор магазина в системе Яндекс', 'kosteams-payments-for-yandex'),
                'default' => '',
                'desc_tip' => true
            ],
            'api_key' => [
                'title' => __('API ключ', 'kosteams-payments-for-yandex'),
                'type' => 'password',
                'description' => __('Секретный ключ для доступа к API', 'kosteams-payments-for-yandex'),
                'default' => '',
                'desc_tip' => true
            ],
            'test_mode' => [
                'title' => __('Тестовый режим', 'kosteams-payments-for-yandex'),
                'type' => 'checkbox',
                'label' => __('Включить тестовый режим (песочница)', 'kosteams-payments-for-yandex'),
                'default' => 'no',
                'description' => __('В тестовом режиме платежи не будут реальными', 'kosteams-payments-for-yandex')
            ],
            'debug_mode' => [
                'title' => __('Режим отладки', 'kosteams-payments-for-yandex'),
                'type' => 'checkbox',
                'label' => __('Включить подробное логирование', 'kosteams-payments-for-yandex'),
                'default' => 'no',
                'description' => __('Записывать подробные логи для отладки', 'kosteams-payments-for-yandex')
            ]
        ];

        // Добавляем информацию о Callback URL
        $this->addCallbackUrlInfo();
    }

    /**
     * Добавить информацию о Callback URL в настройки
     */
    protected function addCallbackUrlInfo(): void {
        $callback_url = home_url('/wc-api/kosteams-payments-for-yandex/');

        $this->form_fields['callback_url_info'] = [
            'title' => __('Callback URL', 'kosteams-payments-for-yandex'),
            'type' => 'title',
            'description' => sprintf(
                '<div class="notice notice-info inline">
                    <p>%s</p>
                    <p><strong><code>%s</code></strong></p>
                </div>',
                __('Укажите этот URL в личном кабинете Яндекс:', 'kosteams-payments-for-yandex'),
                esc_url($callback_url)
            )
        ];
    }

    /**
     * Обработка платежа
     * 
     * @param int $order_id ID заказа
     * @return array Результат обработки
     */
    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->logger->error('Заказ не найден', ['order_id' => $order_id]);

            wc_add_notice(
                __('Ошибка: заказ не найден', 'kosteams-payments-for-yandex'),
                'error'
            );

            return ['result' => 'failure'];
        }

        try {
            // Получаем доступные методы оплаты для этого шлюза
            $payment_methods = $this->getAvailablePaymentMethods();

            // Создаем платеж через процессор
            $payment_url = $this->payment_processor->createPayment($order, $payment_methods);

            // Устанавливаем метод оплаты для заказа
            $order->set_payment_method($this->id);
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $payment_url
            ];
        } catch (\Exception $e) {
            $this->logger->error('Ошибка обработки платежа', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);

            wc_add_notice(
                sprintf(
                    __('Ошибка при создании платежа: %s', 'kosteams-payments-for-yandex'),
                    $e->getMessage()
                ),
                'error'
            );

            return ['result' => 'failure'];
        }
    }

    /**
     * Проверка возможности использования шлюза
     * 
     * @return bool
     */
    public function is_available(): bool {
        // Базовые проверки WooCommerce
        if (!parent::is_available()) {
            return false;
        }

        // Проверка настроек
        if (empty($this->merchant_id) || empty($this->api_key)) {
            return false;
        }

        // Дополнительные проверки для конкретного шлюза
        return $this->checkGatewaySpecificAvailability();
    }

    /**
     * Вывод полей платежного метода на странице оформления заказа
     */
    public function payment_fields(): void {
        // Описание метода оплаты
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        // Дополнительные поля для конкретного шлюза
        $this->renderGatewaySpecificFields();

        // Тестовый режим - предупреждение
        if ($this->test_mode) {
            echo '<div class="notice notice-warning inline">';
            echo '<p>' . __(
                'ВНИМАНИЕ: Включен тестовый режим. Реальные платежи не проводятся.',
                'kosteams-payments-for-yandex'
            ) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Обработка возврата средств
     * 
     * @param int $order_id ID заказа
     * @param float|null $amount Сумма возврата
     * @param string $reason Причина возврата
     * @return bool
     */
    public function process_refund($order_id, $amount = null, $reason = ''): bool {
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        // Используем полную сумму заказа, если не указана конкретная сумма
        if ($amount === null) {
            $amount = $order->get_total();
        }

        return $this->payment_processor->refundPayment($order, $amount, $reason);
    }

    /**
     * Валидация полей на странице оформления заказа
     * 
     * @return bool
     */
    public function validate_fields(): bool {
        // Базовая валидация
        $is_valid = true;

        // Дополнительная валидация для конкретного шлюза
        $is_valid = $is_valid && $this->validateGatewaySpecificFields();

        return $is_valid;
    }

    /**
     * Обработка вебхука
     * 
     * Должна быть реализована в дочерних классах, если требуется
     */
    public function handleWebhook(): void {
        // Будет переопределена в дочерних классах при необходимости
        wp_die('Webhook handler not implemented', 'Not Implemented', 501);
    }

    // Абстрактные методы, которые должны быть реализованы в дочерних классах

    /**
     * Получить доступные методы оплаты
     * 
     * @return array Массив методов ['CARD', 'SPLIT']
     */
    abstract protected function getAvailablePaymentMethods(): array;

    /**
     * Получить заголовок по умолчанию
     * 
     * @return string
     */
    abstract protected function getDefaultTitle(): string;

    /**
     * Получить описание по умолчанию
     * 
     * @return string
     */
    abstract protected function getDefaultDescription(): string;

    /**
     * Проверка специфичных условий доступности шлюза
     * 
     * @return bool
     */
    protected function checkGatewaySpecificAvailability(): bool {
        return true;
    }

    /**
     * Отрисовка специфичных полей шлюза
     */
    protected function renderGatewaySpecificFields(): void {
        // Может быть переопределено в дочерних классах
    }

    /**
     * Валидация специфичных полей шлюза
     * 
     * @return bool
     */
    protected function validateGatewaySpecificFields(): bool {
        return true;
    }

    /**
     * Поддерживает ли шлюз вебхуки
     * 
     * @return bool
     */
    protected function supportsWebhooks(): bool {
        return false;
    }
}
