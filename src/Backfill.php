<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives existing rows a sync_id and a change_seq. Run it in the migration that
 * adds the columns, so every row is in the feed before devices pull.
 */
final class Backfill
{
    public static function run(string $table, string $householdColumn = 'household_id', string $key = 'id'): void
    {
        DB::table($table)
            ->where(fn ($query) => $query->whereNull('sync_id')->orWhereNull('change_seq'))
            ->orderBy($key)
            ->chunkById(500, function ($rows) use ($table, $householdColumn, $key): void {
                DB::transaction(function () use ($rows, $table, $householdColumn, $key): void {
                    foreach ($rows as $row) {
                        /** @var array<string, mixed> $values */
                        $values = (array) $row;
                        $householdId = $values[$householdColumn];

                        if (! is_numeric($householdId)) {
                            continue;
                        }

                        DB::table($table)->where($key, $values[$key])->update([
                            'sync_id' => $values['sync_id'] ?? (string) Str::ulid(),
                            'change_seq' => HouseholdSyncSequence::next(DB::connection(), (int) $householdId),
                        ]);
                    }
                });
            }, $key);
    }
}
