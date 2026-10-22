<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Push;

use stdClass;

/**
 * The proposed ids of one push batch (protocol §5.5): those handed out, and
 * the dead ones. A later change in the batch that needs a dead id is rejected
 * `parent_rejected`.
 *
 * @internal
 */
final class ProposedIds
{
    /** @var array<string, true> */
    private array $handedOut = [];

    /** @var array<string, true> */
    private array $dead = [];

    public function __construct(private readonly int $scopeId = 0) {}

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
     * A proposed id stays usable when the action made its row under it, or the
     * server aliased it to the row in `made`. Every other one is dead: the
     * change was rejected, the action did not make the key, or the id was taken.
     *
     * @param  array<string, string>  $proposals
     * @param  array<string, mixed>  $ack
     */
    public function settle(array $proposals, array $ack): void
    {
        $made = $ack['made'] ?? [];
        $made = $ack['status'] !== 'rejected' && (is_array($made) || $made instanceof stdClass) ? (array) $made : [];

        foreach ($proposals as $key => $syncId) {
            $to = $made[$key] ?? null;

            if ($to !== $syncId && (! is_string($to) || ! Aliases::links($this->scopeId, $syncId, $to))) {
                $this->dead[$syncId] = true;
            }
        }
    }
}
