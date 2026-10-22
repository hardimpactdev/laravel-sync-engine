<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Push;

use stdClass;

/**
 * The proposed ids of one push batch (protocol §5.5): those handed out, and
 * those whose row was not made. A later change in the batch that needs a dead
 * id is rejected `parent_rejected`.
 *
 * @internal
 */
final class ProposedIds
{
    /** @var array<string, true> */
    private array $handedOut = [];

    /** @var array<string, true> */
    private array $dead = [];

    public function handOut(string $syncId): void
    {
        $this->handedOut[$syncId] = true;
    }

    public function isHandedOut(string $syncId): bool
    {
        return isset($this->handedOut[$syncId]);
    }

    public function isDead(string $syncId): bool
    {
        return isset($this->dead[$syncId]);
    }

    /**
     * A rejected change made none of its rows; an applied one made the keys in `made`.
     *
     * @param  array<string, string>  $proposals
     * @param  array<string, mixed>  $ack
     */
    public function settle(array $proposals, array $ack): void
    {
        $made = $ack['made'] ?? [];
        $made = $ack['status'] !== 'rejected' && (is_array($made) || $made instanceof stdClass) ? (array) $made : [];

        foreach ($proposals as $key => $syncId) {
            if (! array_key_exists($key, $made)) {
                $this->dead[$syncId] = true;
            }
        }
    }
}
