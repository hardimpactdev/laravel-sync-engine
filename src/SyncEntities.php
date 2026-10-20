<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine;

use InvalidArgumentException;

/** The app's synced entities, registered in a service provider. */
final class SyncEntities
{
    /** @var array<string, SyncEntity> */
    private array $entities = [];

    public function register(SyncEntity ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->entities[$entity->name()] = $entity;
        }
    }

    public function get(string $name): SyncEntity
    {
        return $this->entities[$name] ?? throw new InvalidArgumentException("No synced entity is called {$name}.");
    }

    public function has(string $name): bool
    {
        return isset($this->entities[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entities);
    }
}
