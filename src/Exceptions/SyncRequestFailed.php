<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A protocol-level failure with its HTTP status and error code (protocol §3.4). */
final class SyncRequestFailed extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    private function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, list<string>> $fields */
    public static function invalidRequest(string $message, array $fields = []): self
    {
        return new self(422, 'invalid_request', $message, $fields);
    }

    public static function upgradeRequired(): self
    {
        return new self(426, 'upgrade_required', 'This app speaks an older sync protocol. Update the app.');
    }

    public static function resyncRequired(): self
    {
        return new self(409, 'resync_required', 'The cursor is older than the kept history. Pull again from the start.');
    }

    public function toResponse(): JsonResponse
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];

        if ($this->fields !== []) {
            $error['fields'] = $this->fields;
        }

        return new JsonResponse(['error' => $error], $this->status);
    }
}
