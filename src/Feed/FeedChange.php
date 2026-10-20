<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Feed;

/** One pulled change (protocol §3.3). */
final readonly class FeedChange
{
    /** @param array<string, mixed>|null $row */
    public function __construct(
        public string $entity,
        public string $id,
        public int $seq,
        public string $op,
        public ?array $row = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $change = ['entity' => $this->entity, 'id' => $this->id, 'seq' => $this->seq, 'op' => $this->op];

        if ($this->row !== null) {
            $change['row'] = $this->row;
        }

        return $change;
    }
}
