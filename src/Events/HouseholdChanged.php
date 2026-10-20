<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Events;

/**
 * A household's feed has new changes up to `seq`. Fired once per committed
 * transaction. Apps broadcast it as the real-time poke (protocol §4).
 */
final readonly class HouseholdChanged
{
    public function __construct(
        public int $householdId,
        public int $seq,
        public ?string $clientId,
    ) {}
}
