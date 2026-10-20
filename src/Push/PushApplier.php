<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Push;

use HardImpact\HouseholdSync\Config;
use HardImpact\HouseholdSync\HouseholdSyncSequence;
use HardImpact\HouseholdSync\SyncEntities;
use HardImpact\HouseholdSync\SyncEntity;
use HardImpact\HouseholdSync\Tombstones;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies pushed changes in order, each in its own savepoint, and acks each one
 * (protocol §3.2). Receipts make a resend a duplicate. The whole batch shares
 * one transaction, so it commits, and pokes, once.
 *
 * @phpstan-type Change array{change_id: string, entity: string, id: string, op: string, fields?: array<string, mixed>, args?: array<string, mixed>, base_seq?: int|null, depends_on?: list<string>, merge?: bool, position?: int}
 * @phpstan-type Ack array<string, mixed>
 */
final readonly class PushApplier
{
    public function __construct(private SyncEntities $entities) {}

    /**
     * @param  list<Change>  $changes
     * @return list<Ack>
     */
    public function apply(int $householdId, string $clientId, array $changes): array
    {
        return HouseholdSyncSequence::asClient($clientId, fn (): array => DB::transaction(function () use ($householdId, $clientId, $changes): array {
            $acks = [];
            $position = 0;

            foreach ($changes as $change) {
                $acks[] = $this->applyOne($householdId, $clientId, $change);
                $position = max($position, $change['position'] ?? 0);
            }

            $this->recordClient($householdId, $clientId, $position);

            return $acks;
        }));
    }

    /** The highest outbox position of this install the server has applied. */
    public function lastPosition(int $householdId, string $clientId): int
    {
        $position = DB::table(Config::clientsTable())->where('household_id', $householdId)->where('client_id', $clientId)->value('last_position');

        return $position === null ? 0 : Config::int($position);
    }

    /**
     * @param  Change  $change
     * @return Ack
     */
    private function applyOne(int $householdId, string $clientId, array $change): array
    {
        $hash = hash('sha256', (string) json_encode(array_diff_key($change, ['client_time' => true])));
        $receipt = DB::table(Config::receiptsTable())
            ->where('household_id', $householdId)
            ->where('change_id', $change['change_id'])
            ->first(['payload_hash', 'ack']);

        if ($receipt !== null) {
            /** @var object{payload_hash: string, ack: string} $receipt */
            if (! hash_equals($receipt->payload_hash, $hash)) {
                return $this->rejected($change, 'change_id_reused', 'This change id was used for another change.', null);
            }

            /** @var Ack $stored */
            $stored = json_decode($receipt->ack, true, flags: JSON_THROW_ON_ERROR);

            return $stored['status'] === 'rejected' ? $stored : [...$stored, 'status' => 'duplicate'];
        }

        try {
            $ack = DB::transaction(fn (): array => $this->write($householdId, $clientId, $change));
        } catch (ValidationException $invalid) {
            /** @var array<string, list<string>> $fields */
            $fields = $invalid->errors();
            $ack = $this->rejected($change, 'validation', $invalid->getMessage(), $this->current($householdId, $change), $fields);
        } catch (SyncRejection $rejection) {
            $ack = $this->rejected($change, $rejection->errorCode, $rejection->getMessage(), $this->current($householdId, $change));
        } catch (UniqueConstraintViolationException) {
            // For example an id that belongs to a row of another household.
            $ack = $this->rejected($change, 'forbidden', 'This change conflicts with a row this device cannot change.', $this->current($householdId, $change));
        }

        DB::table(Config::receiptsTable())->insert([
            'household_id' => $householdId,
            'change_id' => $change['change_id'],
            'client_id' => $clientId,
            'payload_hash' => $hash,
            'ack' => json_encode($ack, JSON_THROW_ON_ERROR),
            'created_at' => Date::now(),
        ]);

        return $ack;
    }

    /**
     * @param  Change  $change
     * @return Ack
     */
    private function write(int $householdId, string $clientId, array $change): array
    {
        if (! $this->entities->has($change['entity'])) {
            throw SyncRejection::unknownEntity($change['entity']);
        }

        $entity = $this->entities->get($change['entity']);
        $syncId = $this->alias($householdId, $entity->name(), $change['id']) ?? $change['id'];
        $existing = $entity->find($householdId, $syncId);
        $remap = $syncId !== $change['id'] ? ['from' => $change['id'], 'to' => $syncId] : null;
        $merge = $change['merge'] ?? false;

        return match ($change['op']) {
            'upsert' => $this->upsert($entity, $householdId, $clientId, $change, $syncId, $existing, $remap, $merge),
            'delete' => $this->delete($entity, $householdId, $clientId, $change, $syncId, $existing),
            default => $this->operate($entity, $householdId, $clientId, $change, $syncId, $existing, $remap),
        };
    }

    /**
     * @param  Change  $change
     * @param  array{from: string, to: string}|null  $remap
     * @return Ack
     */
    private function upsert(SyncEntity $entity, int $householdId, string $clientId, array $change, string $syncId, ?Model $existing, ?array $remap, bool $merge): array
    {
        $fields = $change['fields'] ?? [];

        foreach (array_keys($fields) as $field) {
            if (! in_array($field, $entity->writable(), true)) {
                throw SyncRejection::readOnly($field);
            }
        }

        if ($existing === null && Tombstones::exists($householdId, $entity->name(), $syncId)) {
            throw SyncRejection::deleted();
        }

        $write = new SyncWrite($householdId, $clientId, $syncId, $this->resolve($entity, $householdId, $fields), [], $existing, $merge);

        if ($existing === null && ($match = $entity->match($write)) !== null) {
            $to = $this->syncIdOf($match);
            $this->rememberAlias($householdId, $entity->name(), $change['id'], $to);
            $remap = ['from' => $change['id'], 'to' => $to];
            $write = new SyncWrite($householdId, $clientId, $to, $write->fields, [], $match, $merge);

            // A plain match keeps the existing row; a merge fills in what it lacks.
            if (! $merge) {
                return $this->applied($change, $match, $remap);
            }
        }

        $seenSeq = $write->existing?->getAttribute('change_seq');
        $model = $entity->upsert($write);
        $overwrote = $this->overwrote($change, is_numeric($seenSeq) ? (int) $seenSeq : null, array_keys($fields));

        return $this->applied($change, $model, $remap, $overwrote);
    }

    /**
     * @param  Change  $change
     * @return Ack
     */
    private function delete(SyncEntity $entity, int $householdId, string $clientId, array $change, string $syncId, ?Model $existing): array
    {
        if ($existing === null) {
            return ['change_id' => $change['change_id'], 'status' => 'applied'];
        }

        $entity->delete(new SyncWrite($householdId, $clientId, $syncId, [], [], $existing, false), $existing);

        return ['change_id' => $change['change_id'], 'status' => 'applied', 'seq' => Tombstones::seqOf($entity->name(), $syncId)];
    }

    /**
     * @param  Change  $change
     * @param  array{from: string, to: string}|null  $remap
     * @return Ack
     */
    private function operate(SyncEntity $entity, int $householdId, string $clientId, array $change, string $syncId, ?Model $existing, ?array $remap): array
    {
        if (! in_array($change['op'], $entity->operations(), true)) {
            throw SyncRejection::unknownOperation($change['op']);
        }

        $write = new SyncWrite($householdId, $clientId, $syncId, [], $this->resolve($entity, $householdId, $change['args'] ?? []), $existing, false);
        $model = $entity->operate($write, $change['op']);
        $to = $this->syncIdOf($model);

        if ($to !== $syncId) {
            $this->rememberAlias($householdId, $entity->name(), $change['id'], $to);
            $remap = ['from' => $change['id'], 'to' => $to];
        }

        return $this->applied($change, $model, $remap);
    }

    /**
     * Replaces sync_ids in reference fields with the rows they point to.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function resolve(SyncEntity $entity, int $householdId, array $values): array
    {
        foreach ($entity->references() as $field => $target) {
            if (! array_key_exists($field, $values) || $values[$field] === null) {
                continue;
            }

            $id = is_string($values[$field]) ? $values[$field] : '';
            $id = $this->alias($householdId, $target, $id) ?? $id;
            $values[$field] = $this->entities->get($target)->find($householdId, $id) ?? throw SyncRejection::parentRejected($field);
        }

        return $values;
    }

    /**
     * Fields the change set on a row that changed after the device last saw it. Telemetry only.
     *
     * @param  Change  $change
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function overwrote(array $change, ?int $seqBefore, array $fields): array
    {
        $baseSeq = $change['base_seq'] ?? null;

        return $baseSeq !== null && $seqBefore !== null && $seqBefore > $baseSeq ? $fields : [];
    }

    /**
     * @param  Change  $change
     * @param  array{from: string, to: string}|null  $remap
     * @param  list<string>  $overwrote
     * @return Ack
     */
    private function applied(array $change, Model $model, ?array $remap, array $overwrote = []): array
    {
        $seq = $model->getAttribute('change_seq');
        $ack = ['change_id' => $change['change_id'], 'status' => 'applied', 'seq' => is_numeric($seq) ? (int) $seq : null];

        if ($remap !== null) {
            $ack['remap'] = $remap;
        }

        if ($overwrote !== []) {
            $ack['overwrote'] = $overwrote;
        }

        return $ack;
    }

    /**
     * @param  Change  $change
     * @param  array<string, mixed>|null  $current
     * @param  array<string, list<string>>  $fields
     * @return Ack
     */
    private function rejected(array $change, string $code, string $message, ?array $current, array $fields = []): array
    {
        $error = ['code' => $code, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return ['change_id' => $change['change_id'], 'status' => 'rejected', 'error' => $error, 'current' => $current];
    }

    /**
     * The server's row now, so the device can restore it.
     *
     * @param  Change  $change
     * @return array<string, mixed>|null
     */
    private function current(int $householdId, array $change): ?array
    {
        if (! $this->entities->has($change['entity'])) {
            return null;
        }

        $entity = $this->entities->get($change['entity']);
        $syncId = $this->alias($householdId, $entity->name(), $change['id']) ?? $change['id'];
        $model = $entity->find($householdId, $syncId);

        return $model === null ? null : $entity->row($model);
    }

    private function alias(int $householdId, string $entity, string $syncId): ?string
    {
        $to = DB::table(Config::aliasesTable())
            ->where('household_id', $householdId)
            ->where('entity', $entity)
            ->where('from_sync_id', $syncId)
            ->value('to_sync_id');

        return is_string($to) ? $to : null;
    }

    private function rememberAlias(int $householdId, string $entity, string $from, string $to): void
    {
        DB::table(Config::aliasesTable())->insertOrIgnore([
            'household_id' => $householdId,
            'entity' => $entity,
            'from_sync_id' => $from,
            'to_sync_id' => $to,
            'created_at' => Date::now(),
        ]);
    }

    private function recordClient(int $householdId, string $clientId, int $position): void
    {
        $known = $this->lastPosition($householdId, $clientId);
        $values = ['last_position' => max($known, $position), 'last_seen_at' => Date::now()];

        DB::table(Config::clientsTable())->updateOrInsert(['household_id' => $householdId, 'client_id' => $clientId], $values);
    }

    private function syncIdOf(Model $model): string
    {
        $syncId = $model->getAttribute('sync_id');

        return is_string($syncId) ? $syncId : throw new \LogicException('A synced row has no sync_id.');
    }
}
