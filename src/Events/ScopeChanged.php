<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Events;

/**
 * A scope's feed has new changes up to `seq`. Fired once per committed
 * transaction. Apps broadcast it as the real-time poke (protocol §4).
 */
final readonly class ScopeChanged
{
    public function __construct(
        public int $scopeId,
        public int $seq,
        public ?string $clientId,
    ) {}
}
