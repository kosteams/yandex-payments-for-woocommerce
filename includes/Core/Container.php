<?php

namespace KosTeams\YandexPayments\Core;

/**
 * Dependency Injection контейнер
 * 
 * Управляет созданием и хранением зависимостей плагина.
 * Поддерживает singleton и factory паттерны для создания объектов.
 * 
 * @package KosTeams\YandexPayments\Core
 */
class Container {

    /**
     * Хранилище зарегистрированных сервисов
     * 
     * @var array
     */
    private array $services = [];

    /**
     * Хранилище созданных singleton экземпляров
     * 
     * @var array
     */
    private array $instances = [];

    /**
     * Регистрация сервиса как singleton
     * 
     * Сервис будет создан только один раз при первом обращении
     * 
     * @param string $id Идентификатор сервиса
     * @param callable $factory Фабрика для создания сервиса
     */
    public function singleton(string $id, callable $factory): void {
        $this->services[$id] = [
            'factory' => $factory,
            'singleton' => true
        ];
    }

    /**
     * Регистрация сервиса как factory
     * 
     * Новый экземпляр будет создаваться при каждом обращении
     * 
     * @param string $id Идентификатор сервиса
     * @param callable $factory Фабрика для создания сервиса
     */
    public function factory(string $id, callable $factory): void {
        $this->services[$id] = [
            'factory' => $factory,
            'singleton' => false
        ];
    }

    /**
     * Получить сервис из контейнера
     * 
     * @param string $id Идентификатор сервиса
     * @return mixed
     * @throws \Exception Если сервис не зарегистрирован
     */
    public function get(string $id) {
        if (!isset($this->services[$id])) {
            throw new \Exception(
                sprintf('Сервис "%s" не зарегистрирован в контейнере', $id)
            );
        }

        $service = $this->services[$id];

        // Если это singleton и он уже создан, возвращаем существующий экземпляр
        if ($service['singleton'] && isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Создаем новый экземпляр через фабрику
        $instance = call_user_func($service['factory'], $this);

        // Сохраняем singleton экземпляр
        if ($service['singleton']) {
            $this->instances[$id] = $instance;
        }

        return $instance;
    }

    /**
     * Проверить, зарегистрирован ли сервис
     * 
     * @param string $id Идентификатор сервиса
     * @return bool
     */
    public function has(string $id): bool {
        return isset($this->services[$id]);
    }

    /**
     * Удалить сервис из контейнера
     * 
     * @param string $id Идентификатор сервиса
     */
    public function remove(string $id): void {
        unset($this->services[$id]);
        unset($this->instances[$id]);
    }

    /**
     * Очистить все сервисы и экземпляры
     */
    public function clear(): void {
        $this->services = [];
        $this->instances = [];
    }
}
