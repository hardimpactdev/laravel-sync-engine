<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Console;

use HardImpact\HouseholdSync\Config;
use HardImpact\HouseholdSync\Tombstones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/** Removes old tombstones and raises each household's floor_seq to match. */
final class PruneTombstones extends Command
{
    protected $signature = 'household-sync:prune-tombstones {--days= : Keep tombstones newer than this many days}';

    protected $description = 'Prune old sync tombstones and receipts, and raise the household floor_seq.';

    public function handle(): int
    {
        $option = $this->option('days');
        $days = is_string($option) && ctype_digit($option) ? (int) $option : Config::tombstoneDays();
        $cutoff = Date::now()->subDays($days);
        $states = Config::statesTable();

        $floors = DB::table(Tombstones::table())
            ->where('deleted_at', '<', $cutoff)
            ->groupBy('household_id')
            ->selectRaw('household_id, max(change_seq) as floor')
            ->get();

        foreach ($floors as $floor) {
            /** @var object{household_id: int|string, floor: int|string} $floor */
            DB::transaction(function () use ($floor, $cutoff, $states): void {
                DB::table($states)
                    ->where('household_id', $floor->household_id)
                    ->where('floor_seq', '<', $floor->floor)
                    ->update(['floor_seq' => Config::int($floor->floor)]);

                DB::table(Tombstones::table())
                    ->where('household_id', $floor->household_id)
                    ->where('deleted_at', '<', $cutoff)
                    ->delete();
            });
        }

        $receipts = DB::table(Config::receiptsTable())->where('created_at', '<', Date::now()->subDays(Config::receiptDays()))->delete();

        $this->info("Pruned tombstones older than {$days} days for {$floors->count()} households, and {$receipts} receipts.");

        return self::SUCCESS;
    }
}
