<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Push;

use Illuminate\Database\Eloquent\Model;

/**
 * One pushed change, as the entity's handler sees it. References in `fields`
 * and `args` are already resolved: a reference field holds the referenced
 * Model, or null. `proposals` gives the ids for the rows the change makes
 * (protocol §5.5).
 */
final readonly class SyncWrite
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public int $scopeId,
        public string $clientId,
        public string $syncId,
        public array $fields,
        public array $args,
        public ?Model $existing,
        public bool $merge,
        public ProposedRows $proposals = new ProposedRows,
    ) {}
}
