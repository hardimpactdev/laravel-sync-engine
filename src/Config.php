<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine;

use UnexpectedValueException;

/** Typed access to config/sync-engine.php and to numbers read from the database. */
final class Config
{
    public static function protocol(): int
    {
        return config()->integer('sync-engine.protocol');
    }

    public static function statesTable(): string
    {
        return config()->string('sync-engine.tables.states');
    }

    public static function tombstonesTable(): string
    {
        return config()->string('sync-engine.tables.tombstones');
    }

    public static function receiptsTable(): string
    {
        return config()->string('sync-engine.tables.receipts');
    }

    public static function aliasesTable(): string
    {
        return config()->string('sync-engine.tables.aliases');
    }

    public static function clientsTable(): string
    {
        return config()->string('sync-engine.tables.clients');
    }

    public static function receiptDays(): int
    {
        return config()->integer('sync-engine.receipt_days');
    }

    public static function pageSize(): int
    {
        return config()->integer('sync-engine.page_size');
    }

    public static function tombstoneDays(): int
    {
        return config()->integer('sync-engine.tombstone_days');
    }

    /** A number as the database driver returns it: int on some drivers, numeric string on others. */
    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Expected a whole number from the database.');
    }
}
