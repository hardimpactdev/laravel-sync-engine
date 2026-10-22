<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Push;

use RuntimeException;

/** A change that will never apply (protocol §3.3). Handlers may throw it too. */
final class SyncRejection extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function deleted(): self
    {
        return new self('deleted', 'This was deleted on another device.');
    }

    public static function parentRejected(string $field): self
    {
        return new self('parent_rejected', "The row that {$field} points to does not exist.");
    }

    /** The id is one an earlier change in the batch proposed but did not make (protocol §5.5). */
    public static function notMade(string $field): self
    {
        return new self('parent_rejected', "{$field} names a row that was not made.");
    }

    public static function readOnly(string $field): self
    {
        return new self('read_only_field', "The {$field} field cannot be changed from a device.");
    }

    public static function unknownEntity(string $entity): self
    {
        return new self('unknown_entity', "{$entity} does not sync.");
    }

    public static function unknownOperation(string $op): self
    {
        return new self('unknown_op', "{$op} is not an operation of this entity.");
    }
}
