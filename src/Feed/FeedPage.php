<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Feed;

final readonly class FeedPage
{
    /** @param list<FeedChange> $changes */
    public function __construct(
        public array $changes,
        public int $lastSeq,
        public bool $hasMore,
    ) {}
}
