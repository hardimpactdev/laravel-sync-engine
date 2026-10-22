<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Http;

use HardImpact\SyncEngine\Config;
use HardImpact\SyncEngine\Exceptions\SyncRequestFailed;
use HardImpact\SyncEngine\Feed\FeedChange;
use HardImpact\SyncEngine\Feed\SyncFeed;
use HardImpact\SyncEngine\Push\PushApplier;
use HardImpact\SyncEngine\SyncCursor;
use HardImpact\SyncEngine\SyncEntities;
use HardImpact\SyncEngine\SyncSequence;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The sync endpoint (protocol §3). The app's controller authenticates the user,
 * checks membership and passes the scope's server id.
 *
 * Pushed changes are applied first, so the pull page includes them.
 */
final readonly class SyncEndpoint
{
    private const string ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    private const string PROPOSAL_KEY = '/^[a-z0-9_\-:|.]{1,64}$/';

    public function __construct(
        private SyncEntities $entities,
        private SyncFeed $feed,
        private PushApplier $push,
    ) {}

    public function respond(Request $request, int $scopeId): JsonResponse
    {
        try {
            return $this->handle($request, $scopeId);
        } catch (SyncRequestFailed $failure) {
            return $failure->toResponse();
        }
    }

    private function handle(Request $request, int $scopeId): JsonResponse
    {
        $protocol = Config::protocol();

        if ($request->input('protocol') !== $protocol) {
            throw is_int($request->input('protocol'))
                ? SyncRequestFailed::upgradeRequired()
                : SyncRequestFailed::invalidRequest('The protocol field must be an integer.');
        }

        $validator = Validator::make($request->all(), [
            'client_id' => ['required', 'string', 'regex:'.self::ULID],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'entities' => ['required', 'array', 'min:1'],
            'entities.*' => ['required', 'string', 'distinct', Rule::in($this->entities->names())],
            'changes' => ['present', 'array', 'max:100'],
            'changes.*' => ['array'],
            'changes.*.change_id' => ['required', 'string', 'distinct', 'regex:'.self::ULID],
            'changes.*.entity' => ['required', 'string', 'max:64'],
            'changes.*.id' => ['required', 'string', 'regex:'.self::ULID],
            'changes.*.op' => ['required', 'string', 'max:64'],
            'changes.*.fields' => ['sometimes', 'array'],
            'changes.*.args' => ['sometimes', 'array'],
            'changes.*.base_seq' => ['nullable', 'integer', 'min:0'],
            'changes.*.depends_on' => ['sometimes', 'array'],
            'changes.*.merge' => ['sometimes', 'boolean'],
            'changes.*.position' => ['sometimes', 'integer', 'min:1'],
            'changes.*.client_time' => ['sometimes', 'nullable', 'string', 'max:64'],
            'changes.*.proposals' => ['sometimes', 'array', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Config::pageSize()],
        ]);

        // On the raw input: the validator rewrites dots in keys.
        $validator->after(function (ValidatorContract $validator) use ($request): void {
            $seen = [];

            foreach ((array) $request->input('changes') as $index => $change) {
                $proposals = is_array($change) ? ($change['proposals'] ?? []) : [];

                if (! $this->validProposals($proposals, $seen)) {
                    $validator->errors()->add("changes.{$index}.proposals", 'Each proposal maps a key to a ULID that no other proposal of the batch has.');
                }
            }
        });

        if ($validator->fails()) {
            /** @var array<string, list<string>> $fields */
            $fields = $validator->errors()->toArray();

            throw SyncRequestFailed::invalidRequest('The sync request is not valid.', $fields);
        }

        /** @var array{client_id: string, cursor?: string|null, entities: list<string>, limit?: int|null} $input */
        $input = $validator->validated();
        /** @var list<array{change_id: string, entity: string, id: string, op: string, fields?: array<string, mixed>, args?: array<string, mixed>, base_seq?: int|null, depends_on?: list<string>, merge?: bool, position?: int, proposals?: array<string, string>}> $changes */
        $changes = array_values((array) $request->input('changes'));

        $afterSeq = SyncCursor::decode($input['cursor'] ?? null, $scopeId);

        // Checked before the push, so a stale cursor applies nothing. The page
        // checks again inside its snapshot.
        if ($afterSeq > 0 && $afterSeq < SyncSequence::floor(DB::connection(), $scopeId)) {
            throw SyncRequestFailed::resyncRequired();
        }

        $acks = $changes === [] ? [] : $this->push->apply($scopeId, $input['client_id'], $changes);

        $page = $this->feed->page(
            $scopeId,
            $input['entities'],
            $afterSeq,
            $input['limit'] ?? Config::pageSize(),
        );

        return new JsonResponse([
            'protocol' => $protocol,
            'acks' => $acks,
            'changes' => array_map(fn (FeedChange $change): array => $change->toArray(), $page->changes),
            'cursor' => SyncCursor::encode($scopeId, $page->lastSeq),
            'has_more' => $page->hasMore,
            'last_position' => $this->push->lastPosition($scopeId, $input['client_id']),
            'server_time' => Date::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'features' => ['proposals'],
        ]);
    }

    /**
     * Keys as protocol §5.5 allows them, each with a ULID that appears once in the batch.
     *
     * @param  array<string, true>  $seen  the ids of the batch so far
     */
    private function validProposals(mixed $proposals, array &$seen): bool
    {
        if (! is_array($proposals)) {
            return true; // the array rule reports it
        }

        foreach ($proposals as $key => $syncId) {
            if (preg_match(self::PROPOSAL_KEY, (string) $key) !== 1 || ! is_string($syncId) || preg_match(self::ULID, $syncId) !== 1 || isset($seen[$syncId])) {
                return false;
            }

            $seen[$syncId] = true;
        }

        return true;
    }
}
