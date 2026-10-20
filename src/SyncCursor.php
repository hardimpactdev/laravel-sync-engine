<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine;

use HardImpact\SyncEngine\Exceptions\SyncRequestFailed;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** The opaque pull cursor: an encrypted {scope, seq, protocol}. */
final class SyncCursor
{
    public static function encode(int $scopeId, int $seq): string
    {
        return Crypt::encryptString((string) json_encode(['scope' => $scopeId, 'seq' => $seq, 'protocol' => self::protocol()]));
    }

    /** The seq after which to pull. A null cursor means from the start. */
    public static function decode(?string $cursor, int $scopeId): int
    {
        if ($cursor === null) {
            return 0;
        }

        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw SyncRequestFailed::invalidRequest('The cursor is not valid.');
        }

        if (! is_array($payload) || ($payload['scope'] ?? null) !== $scopeId || ! is_int($payload['seq'] ?? null)) {
            throw SyncRequestFailed::invalidRequest('The cursor belongs to another scope.');
        }

        if (($payload['protocol'] ?? null) !== self::protocol()) {
            throw SyncRequestFailed::resyncRequired();
        }

        return $payload['seq'];
    }

    private static function protocol(): int
    {
        return Config::protocol();
    }
}
