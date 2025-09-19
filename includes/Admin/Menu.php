<?php

namespace KosTeams\YandexPayments\Admin;

/**
 * Менеджер меню администратора
 * 
 * Отвечает за создание и управление пунктами меню
 * в административной панели WordPress
 * 
 * @package KosTeams\YandexPayments\Admin
 */
class Menu {

    /**
     * Менеджер настроек
     * 
     * @var Settings
     */
    private Settings $settings;

    /**
     * Slug главного меню
     * 
     * @var string
     */
    const MENU_SLUG = 'kosteams-yandex-payments';

    /**
     * Capability для доступа к меню
     * 
     * @var string
     */
    const CAPABILITY = 'manage_woocommerce';

    /**
     * Конструктор
     * 
     * @param Settings $settings
     */
    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Регистрация меню
     */
    public function register(): void {
        // Добавляем пункт в меню WooCommerce
        add_submenu_page(
            'woocommerce',
            __('KosTeams Яндекс Платежи', 'kosteams-payments-for-yandex'),
            __('Яндекс Платежи', 'kosteams-payments-for-yandex'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderMainPage']
        );

        // Добавляем подпункты
        $this->addSubMenuItems();

        // Добавляем ссылки в меню плагинов
        add_filter('plugin_action_links_' . KOSTEAMS_YANDEX_BASENAME, [$this, 'addPluginActionLinks']);
        add_filter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 2);
    }

    /**
     * Добавить подпункты меню
     */
    private function addSubMenuItems(): void {
        // Настройки Яндекс Pay
        add_submenu_page(
            self::MENU_SLUG,
            __('Настройки Яндекс Pay', 'kosteams-payments-for-yandex'),
            __('Яндекс Pay', 'kosteams-payments-for-yandex'),
            self::CAPABILITY,
            'kosteams-yandex-pay-settings',
            [$this, 'redirectToPaySettings']
        );

        // Настройки Яндекс Сплит
        add_submenu_page(
            self::MENU_SLUG,
            __('Настройки Яндекс Сплит', 'kosteams-payments-for-yandex'),
            __('Яндекс Сплит', 'kosteams-payments-for-yandex'),
            self::CAPABILITY,
            'kosteams-yandex-split-settings',
            [$this, 'redirectToSplitSettings']
        );

        // Статистика
        add_submenu_page(
            self::MENU_SLUG,
            __('Статистика платежей', 'kosteams-payments-for-yandex'),
            __('Статистика', 'kosteams-payments-for-yandex'),
            self::CAPABILITY,
            'kosteams-yandex-stats',
            [$this, 'renderStatsPage']
        );

        // Логи
        if ($this->settings->get('debug_mode')) {
            add_submenu_page(
                self::MENU_SLUG,
                __('Логи', 'kosteams-payments-for-yandex'),
                __('Логи', 'kosteams-payments-for-yandex'),
                self::CAPABILITY,
                'kosteams-yandex-logs',
                [$this, 'renderLogsPage']
            );
        }

        // Инструменты
        add_submenu_page(
            self::MENU_SLUG,
            __('Инструменты', 'kosteams-payments-for-yandex'),
            __('Инструменты', 'kosteams-payments-for-yandex'),
            self::CAPABILITY,
            'kosteams-yandex-tools',
            [$this, 'renderToolsPage']
        );
    }

    /**
     * Отрисовка главной страницы
     */
    public function renderMainPage(): void {
        $is_configured = $this->settings->isConfigured();
?>
        <div class="wrap kosteams-yandex-admin">
            <h1>
                <img src="<?php echo esc_url(KOSTEAMS_YANDEX_URL . 'assets/image/yandex-logo.svg'); ?>"
                    alt="Yandex" height="30" style="vertical-align: middle; margin-right: 10px;">
                <?php esc_html_e('KosTeams Яндекс Платежи', 'kosteams-payments-for-yandex'); ?>
            </h1>

            <?php if (!$is_configured): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('Плагин еще не настроен. Пожалуйста, настройте Merchant ID и API ключ.', 'kosteams-payments-for-yandex'); ?>
                        <a href="<?php echo esc_url($this->settings->getSettingsUrl()); ?>" class="button button-primary" style="margin-left: 10px;">
                            <?php esc_html_e('Настроить сейчас', 'kosteams-payments-for-yandex'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <div class="kosteams-dashboard">
                <div class="dashboard-widgets">
                    <!-- Виджет статуса -->
                    <div class="dashboard-widget status-widget">
                        <h3><?php esc_html_e('Статус системы', 'kosteams-payments-for-yandex'); ?></h3>
                        <?php $this->renderStatusWidget(); ?>
                    </div>

                    <!-- Виджет статистики -->
                    <div class="dashboard-widget stats-widget">
                        <h3><?php esc_html_e('Статистика за сегодня', 'kosteams-payments-for-yandex'); ?></h3>
                        <?php $this->renderQuickStats(); ?>
                    </div>

                    <!-- Виджет быстрых действий -->
                    <div class="dashboard-widget actions-widget">
                        <h3><?php esc_html_e('Быстрые действия', 'kosteams-payments-for-yandex'); ?></h3>
                        <?php $this->renderQuickActions(); ?>
                    </div>

                    <!-- Виджет документации -->
                    <div class="dashboard-widget docs-widget">
                        <h3><?php esc_html_e('Документация и поддержка', 'kosteams-payments-for-yandex'); ?></h3>
                        <?php $this->renderDocsWidget(); ?>
                    </div>
                </div>
            </div>

            <?php $this->renderPromotion(); ?>
        </div>
    <?php
    }

    /**
     * Отрисовка виджета статуса
     */
    private function renderStatusWidget(): void {
        $is_configured = $this->settings->isConfigured();
        $test_mode = $this->settings->get('test_mode');
        $pay_enabled = get_option('woocommerce_kosteams-yandex-pay_settings')['enabled'] ?? 'no';
        $split_enabled = get_option('woocommerce_kosteams-yandex-split_settings')['enabled'] ?? 'no';

    ?>
        <ul class="status-list">
            <li class="<?php echo $is_configured ? 'status-ok' : 'status-error'; ?>">
                <span class="status-icon"><?php echo $is_configured ? '✓' : '✗'; ?></span>
                <?php esc_html_e('Конфигурация', 'kosteams-payments-for-yandex'); ?>:
                <strong><?php echo $is_configured
                            ? esc_html__('Настроено', 'kosteams-payments-for-yandex')
                            : esc_html__('Не настроено', 'kosteams-payments-for-yandex'); ?></strong>
            </li>

            <li class="<?php echo $test_mode ? 'status-warning' : 'status-ok'; ?>">
                <span class="status-icon"><?php echo $test_mode ? '⚠' : '✓'; ?></span>
                <?php esc_html_e('Режим', 'kosteams-payments-for-yandex'); ?>:
                <strong><?php echo $test_mode
                            ? esc_html__('Тестовый', 'kosteams-payments-for-yandex')
                            : esc_html__('Рабочий', 'kosteams-payments-for-yandex'); ?></strong>
            </li>

            <li class="<?php echo $pay_enabled === 'yes' ? 'status-ok' : 'status-disabled'; ?>">
                <span class="status-icon"><?php echo $pay_enabled === 'yes' ? '✓' : '○'; ?></span>
                <?php esc_html_e('Яндекс Pay', 'kosteams-payments-for-yandex'); ?>:
                <strong><?php echo $pay_enabled === 'yes'
                            ? esc_html__('Включен', 'kosteams-payments-for-yandex')
                            : esc_html__('Выключен', 'kosteams-payments-for-yandex'); ?></strong>
            </li>

            <li class="<?php echo $split_enabled === 'yes' ? 'status-ok' : 'status-disabled'; ?>">
                <span class="status-icon"><?php echo $split_enabled === 'yes' ? '✓' : '○'; ?></span>
                <?php esc_html_e('Яндекс Сплит', 'kosteams-payments-for-yandex'); ?>:
                <strong><?php echo $split_enabled === 'yes'
                            ? esc_html__('Включен', 'kosteams-payments-for-yandex')
                            : esc_html__('Выключен', 'kosteams-payments-for-yandex'); ?></strong>
            </li>
        </ul>
    <?php
    }

    /**
     * Отрисовка быстрой статистики
     */
    private function renderQuickStats(): void {
        global $wpdb;

        // Получаем статистику за сегодня
        $today = current_time('Y-m-d');

        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(DISTINCT p.ID) as order_count,
                SUM(pm.meta_value) as total_amount
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed')
            AND DATE(p.post_date) = %s
            AND p.ID IN (
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_payment_method' 
                AND meta_value LIKE 'kosteams-yandex%'
            )
        ", $today));

    ?>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-value"><?php echo intval($stats->order_count ?? 0); ?></div>
                <div class="stat-label"><?php esc_html_e('Заказов', 'kosteams-payments-for-yandex'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo wc_price($stats->total_amount ?? 0); ?></div>
                <div class="stat-label"><?php esc_html_e('Сумма', 'kosteams-payments-for-yandex'); ?></div>
            </div>
        </div>

        <p class="stats-link">
            <a href="<?php echo esc_url(admin_url('admin.php?page=kosteams-yandex-stats')); ?>">
                <?php esc_html_e('Подробная статистика →', 'kosteams-payments-for-yandex'); ?>
            </a>
        </p>
    <?php
    }

    /**
     * Отрисовка быстрых действий
     */
    private function renderQuickActions(): void {
    ?>
        <div class="quick-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-pay')); ?>"
                class="button">
                <?php esc_html_e('Настройки Яндекс Pay', 'kosteams-payments-for-yandex'); ?>
            </a>

            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-split')); ?>"
                class="button">
                <?php esc_html_e('Настройки Яндекс Сплит', 'kosteams-payments-for-yandex'); ?>
            </a>

            <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order')); ?>"
                class="button">
                <?php esc_html_e('Все заказы', 'kosteams-payments-for-yandex'); ?>
            </a>

            <?php if ($this->settings->get('test_mode')): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=kosteams-yandex-tools&action=test-payment')); ?>"
                    class="button">
                    <?php esc_html_e('Тестовый платеж', 'kosteams-payments-for-yandex'); ?>
                </a>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Отрисовка виджета документации
     */
    private function renderDocsWidget(): void {
    ?>
        <ul class="docs-links">
            <li>
                <a href="https://kosteams.com/docs/yandex-payments" target="_blank">
                    📚 <?php esc_html_e('Документация плагина', 'kosteams-payments-for-yandex'); ?>
                </a>
            </li>
            <li>
                <a href="https://yandex.ru/dev/payments/" target="_blank">
                    📖 <?php esc_html_e('Документация Яндекс API', 'kosteams-payments-for-yandex'); ?>
                </a>
            </li>
            <li>
                <a href="https://t.me/koSteams" target="_blank">
                    💬 <?php esc_html_e('Поддержка в Telegram', 'kosteams-payments-for-yandex'); ?>
                </a>
            </li>
            <li>
                <a href="https://kosteams.com/support" target="_blank">
                    🎫 <?php esc_html_e('Создать тикет', 'kosteams-payments-for-yandex'); ?>
                </a>
            </li>
        </ul>
    <?php
    }

    /**
     * Отрисовка промо блока
     */
    private function renderPromotion(): void {
        if (is_plugin_active('kosteams-payments-for-yandex-pro/kosteams-payments-for-yandex-pro.php')) {
            return;
        }

    ?>
        <div class="kosteams-promo-block">
            <h3><?php esc_html_e('🚀 Расширьте возможности с Pro версией', 'kosteams-payments-for-yandex'); ?></h3>
            <div class="promo-features">
                <ul>
                    <li>✨ Виджеты и бейджи рассрочки в каталоге</li>
                    <li>📊 Расширенная аналитика и отчеты</li>
                    <li>🔄 Автоматические возвраты</li>
                    <li>🎨 Кастомизация внешнего вида</li>
                    <li>⚡ Приоритетная поддержка</li>
                </ul>
            </div>
            <a href="https://kosteams.com/pro" target="_blank" class="button button-primary button-hero">
                <?php esc_html_e('Узнать больше о Pro версии', 'kosteams-payments-for-yandex'); ?>
            </a>
        </div>
<?php
    }

    /**
     * Редирект на настройки Яндекс Pay
     */
    public function redirectToPaySettings(): void {
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-pay'));
        exit;
    }

    /**
     * Редирект на настройки Яндекс Сплит
     */
    public function redirectToSplitSettings(): void {
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=kosteams-yandex-split'));
        exit;
    }

    /**
     * Отрисовка страницы статистики
     */
    public function renderStatsPage(): void {
        require_once KOSTEAMS_YANDEX_PATH . 'includes/Admin/views/stats-page.php';
    }

    /**
     * Отрисовка страницы логов
     */
    public function renderLogsPage(): void {
        require_once KOSTEAMS_YANDEX_PATH . 'includes/Admin/views/logs-page.php';
    }

    /**
     * Отрисовка страницы инструментов
     */
    public function renderToolsPage(): void {
        require_once KOSTEAMS_YANDEX_PATH . 'includes/Admin/views/tools-page.php';
    }

    /**
     * Добавить ссылки действий для плагина
     * 
     * @param array $links
     * @return array
     */
    public function addPluginActionLinks(array $links): array {
        $action_links = [
            '<a href="' . esc_url($this->settings->getSettingsUrl()) . '">' .
                esc_html__('Настройки', 'kosteams-payments-for-yandex') . '</a>',

            '<a href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '">' .
                esc_html__('Панель управления', 'kosteams-payments-for-yandex') . '</a>'
        ];

        return array_merge($action_links, $links);
    }

    /**
     * Добавить мета ссылки для плагина
     * 
     * @param array $links
     * @param string $file
     * @return array
     */
    public function addPluginRowMeta(array $links, string $file): array {
        if ($file !== KOSTEAMS_YANDEX_BASENAME) {
            return $links;
        }

        $row_meta = [
            'docs' => '<a href="https://kosteams.com/docs" target="_blank">' .
                esc_html__('Документация', 'kosteams-payments-for-yandex') . '</a>',

            'support' => '<a href="https://t.me/koSteams" target="_blank">' .
                esc_html__('Поддержка', 'kosteams-payments-for-yandex') . '</a>'
        ];

        return array_merge($links, $row_meta);
    }
}
