<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Push;

use HardImpact\SyncEngine\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * A device's sync_id that the server mapped to another row (protocol §5.3,
 * §5.5). Later changes that address or reference it reach that row.
 */
final class Aliases
{
    public static function of(int $scopeId, string $entity, string $syncId): ?string
    {
        $to = DB::table(Config::aliasesTable())
            ->where('scope_id', $scopeId)
            ->where('entity', $entity)
            ->where('from_sync_id', $syncId)
            ->value('to_sync_id');

        return is_string($to) ? $to : null;
    }

    public static function remember(int $scopeId, string $entity, string $from, string $to): void
    {
        DB::table(Config::aliasesTable())->insertOrIgnore([
            'scope_id' => $scopeId,
            'entity' => $entity,
            'from_sync_id' => $from,
            'to_sync_id' => $to,
            'created_at' => Date::now(),
        ]);
    }
}
