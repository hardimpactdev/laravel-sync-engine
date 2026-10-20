<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use HardImpact\HouseholdSync\Exceptions\SyncRequestFailed;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** The opaque pull cursor: an encrypted {household, seq, protocol}. */
final class SyncCursor
{
    public static function encode(int $householdId, int $seq): string
    {
        return Crypt::encryptString((string) json_encode(['h' => $householdId, 's' => $seq, 'p' => self::protocol()]));
    }

    /** The seq after which to pull. A null cursor means from the start. */
    public static function decode(?string $cursor, int $householdId): int
    {
        if ($cursor === null) {
            return 0;
        }

        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw SyncRequestFailed::invalidRequest('The cursor is not valid.');
        }

        if (! is_array($payload) || ($payload['h'] ?? null) !== $householdId || ! is_int($payload['s'] ?? null)) {
            throw SyncRequestFailed::invalidRequest('The cursor belongs to another household.');
        }

        if (($payload['p'] ?? null) !== self::protocol()) {
            throw SyncRequestFailed::resyncRequired();
        }

        return $payload['s'];
    }

    private static function protocol(): int
    {
        return Config::protocol();
    }
}
