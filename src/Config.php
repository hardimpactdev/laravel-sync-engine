<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use UnexpectedValueException;

/** Typed access to config/household-sync.php and to numbers read from the database. */
final class Config
{
    public static function protocol(): int
    {
        return config()->integer('household-sync.protocol');
    }

    public static function statesTable(): string
    {
        return config()->string('household-sync.tables.states');
    }

    public static function tombstonesTable(): string
    {
        return config()->string('household-sync.tables.tombstones');
    }

    public static function receiptsTable(): string
    {
        return config()->string('household-sync.tables.receipts');
    }

    public static function aliasesTable(): string
    {
        return config()->string('household-sync.tables.aliases');
    }

    public static function clientsTable(): string
    {
        return config()->string('household-sync.tables.clients');
    }

    public static function receiptDays(): int
    {
        return config()->integer('household-sync.receipt_days');
    }

    public static function pageSize(): int
    {
        return config()->integer('household-sync.page_size');
    }

    public static function tombstoneDays(): int
    {
        return config()->integer('household-sync.tombstone_days');
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
