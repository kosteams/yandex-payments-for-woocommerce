<?php

namespace KosTeams\YandexPayments\Core;

/**
 * Загрузчик хуков WordPress
 * 
 * Централизованное управление всеми хуками и фильтрами плагина.
 * Регистрирует хуки в WordPress при вызове метода run().
 * 
 * @package KosTeams\YandexPayments\Core
 */
class Loader {

    /**
     * Массив зарегистрированных actions
     * 
     * @var array
     */
    private array $actions = [];

    /**
     * Массив зарегистрированных filters
     * 
     * @var array
     */
    private array $filters = [];

    /**
     * Массив зарегистрированных shortcodes
     * 
     * @var array
     */
    private array $shortcodes = [];

    /**
     * Добавить action хук
     * 
     * @param string $hook Название хука WordPress
     * @param object $component Объект, содержащий метод
     * @param string $callback Название метода для вызова
     * @param int $priority Приоритет выполнения (по умолчанию 10)
     * @param int $accepted_args Количество принимаемых аргументов (по умолчанию 1)
     */
    public function addAction(
        string $hook,
        $component,
        string $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        $this->actions[] = [
            'hook' => $hook,
            'component' => $component,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        ];
    }

    /**
     * Добавить filter хук
     * 
     * @param string $hook Название хука WordPress
     * @param object $component Объект, содержащий метод
     * @param string $callback Название метода для вызова
     * @param int $priority Приоритет выполнения (по умолчанию 10)
     * @param int $accepted_args Количество принимаемых аргументов (по умолчанию 1)
     */
    public function addFilter(
        string $hook,
        $component,
        string $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        $this->filters[] = [
            'hook' => $hook,
            'component' => $component,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        ];
    }

    /**
     * Добавить shortcode
     * 
     * @param string $tag Тег шорткода
     * @param object $component Объект, содержащий метод
     * @param string $callback Название метода для вызова
     */
    public function addShortcode(string $tag, $component, string $callback): void {
        $this->shortcodes[] = [
            'tag' => $tag,
            'component' => $component,
            'callback' => $callback
        ];
    }

    /**
     * Удалить action хук
     * 
     * @param string $hook Название хука WordPress
     * @param object|null $component Объект, содержащий метод
     * @param string|null $callback Название метода
     * @param int $priority Приоритет
     */
    public function removeAction(
        string $hook,
        $component = null,
        string $callback = null,
        int $priority = 10
    ): void {
        if ($component && $callback) {
            remove_action($hook, [$component, $callback], $priority);
        } else {
            remove_all_actions($hook, $priority);
        }

        // Удаляем из внутреннего списка
        $this->actions = array_filter($this->actions, function ($action) use ($hook, $component, $callback) {
            return !($action['hook'] === $hook &&
                $action['component'] === $component &&
                $action['callback'] === $callback);
        });
    }

    /**
     * Удалить filter хук
     * 
     * @param string $hook Название хука WordPress
     * @param object|null $component Объект, содержащий метод
     * @param string|null $callback Название метода
     * @param int $priority Приоритет
     */
    public function removeFilter(
        string $hook,
        $component = null,
        string $callback = null,
        int $priority = 10
    ): void {
        if ($component && $callback) {
            remove_filter($hook, [$component, $callback], $priority);
        } else {
            remove_all_filters($hook, $priority);
        }

        // Удаляем из внутреннего списка
        $this->filters = array_filter($this->filters, function ($filter) use ($hook, $component, $callback) {
            return !($filter['hook'] === $hook &&
                $filter['component'] === $component &&
                $filter['callback'] === $callback);
        });
    }

    /**
     * Зарегистрировать все хуки в WordPress
     * 
     * Этот метод должен быть вызван после регистрации всех хуков
     */
    public function run(): void {
        // Регистрация actions
        foreach ($this->actions as $action) {
            add_action(
                $action['hook'],
                [$action['component'], $action['callback']],
                $action['priority'],
                $action['accepted_args']
            );
        }

        // Регистрация filters
        foreach ($this->filters as $filter) {
            add_filter(
                $filter['hook'],
                [$filter['component'], $filter['callback']],
                $filter['priority'],
                $filter['accepted_args']
            );
        }

        // Регистрация shortcodes
        foreach ($this->shortcodes as $shortcode) {
            add_shortcode(
                $shortcode['tag'],
                [$shortcode['component'], $shortcode['callback']]
            );
        }
    }

    /**
     * Получить список зарегистрированных actions
     * 
     * @return array
     */
    public function getActions(): array {
        return $this->actions;
    }

    /**
     * Получить список зарегистрированных filters
     * 
     * @return array
     */
    public function getFilters(): array {
        return $this->filters;
    }

    /**
     * Получить список зарегистрированных shortcodes
     * 
     * @return array
     */
    public function getShortcodes(): array {
        return $this->shortcodes;
    }

    /**
     * Проверить, зарегистрирован ли action
     * 
     * @param string $hook Название хука
     * @param object|null $component Объект
     * @param string|null $callback Метод
     * @return bool
     */
    public function hasAction(string $hook, $component = null, string $callback = null): bool {
        foreach ($this->actions as $action) {
            if ($action['hook'] === $hook) {
                if ($component && $callback) {
                    if ($action['component'] === $component && $action['callback'] === $callback) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Проверить, зарегистрирован ли filter
     * 
     * @param string $hook Название хука
     * @param object|null $component Объект
     * @param string|null $callback Метод
     * @return bool
     */
    public function hasFilter(string $hook, $component = null, string $callback = null): bool {
        foreach ($this->filters as $filter) {
            if ($filter['hook'] === $hook) {
                if ($component && $callback) {
                    if ($filter['component'] === $component && $filter['callback'] === $callback) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Очистить все зарегистрированные хуки
     * 
     * Полезно для тестирования
     */
    public function clear(): void {
        $this->actions = [];
        $this->filters = [];
        $this->shortcodes = [];
    }

    /**
     * Получить статистику по зарегистрированным хукам
     * 
     * @return array
     */
    public function getStats(): array {
        return [
            'actions' => count($this->actions),
            'filters' => count($this->filters),
            'shortcodes' => count($this->shortcodes),
            'total' => count($this->actions) + count($this->filters) + count($this->shortcodes)
        ];
    }
}
