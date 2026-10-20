<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Http;

use HardImpact\HouseholdSync\Config;
use HardImpact\HouseholdSync\Exceptions\SyncRequestFailed;
use HardImpact\HouseholdSync\Feed\FeedChange;
use HardImpact\HouseholdSync\Feed\SyncFeed;
use HardImpact\HouseholdSync\HouseholdSyncSequence;
use HardImpact\HouseholdSync\SyncCursor;
use HardImpact\HouseholdSync\SyncEntities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The sync endpoint (protocol §3). The app's controller authenticates the user,
 * checks membership and passes the household's server id.
 *
 * This version serves pulls. Pushes arrive with the push applier.
 */
final readonly class SyncEndpoint
{
    public function __construct(
        private SyncEntities $entities,
        private SyncFeed $feed,
    ) {}

    public function respond(Request $request, int $householdId): JsonResponse
    {
        try {
            return $this->handle($request, $householdId);
        } catch (SyncRequestFailed $failure) {
            return $failure->toResponse();
        }
    }

    private function handle(Request $request, int $householdId): JsonResponse
    {
        $protocol = Config::protocol();

        if ($request->input('protocol') !== $protocol) {
            throw is_int($request->input('protocol'))
                ? SyncRequestFailed::upgradeRequired()
                : SyncRequestFailed::invalidRequest('The protocol field must be an integer.');
        }

        $validator = Validator::make($request->all(), [
            'client_id' => ['required', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'entities' => ['required', 'array', 'min:1'],
            'entities.*' => ['required', 'string', 'distinct', Rule::in($this->entities->names())],
            'changes' => ['present', 'array', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Config::pageSize()],
        ]);

        if ($validator->fails()) {
            /** @var array<string, list<string>> $fields */
            $fields = $validator->errors()->toArray();

            throw SyncRequestFailed::invalidRequest('The sync request is not valid.', $fields);
        }

        /** @var array{client_id: string, cursor?: string|null, entities: list<string>, changes: list<mixed>, limit?: int|null} $input */
        $input = $validator->validated();

        if ($input['changes'] !== []) {
            throw SyncRequestFailed::invalidRequest('This server does not accept changes yet.');
        }

        $afterSeq = SyncCursor::decode($input['cursor'] ?? null, $householdId);

        if ($afterSeq > 0 && $afterSeq < HouseholdSyncSequence::floor(DB::connection(), $householdId)) {
            throw SyncRequestFailed::resyncRequired();
        }

        $page = $this->feed->page(
            $householdId,
            $input['entities'],
            $afterSeq,
            $input['limit'] ?? Config::pageSize(),
        );

        return new JsonResponse([
            'protocol' => $protocol,
            'acks' => [],
            'changes' => array_map(fn (FeedChange $change): array => $change->toArray(), $page->changes),
            'cursor' => SyncCursor::encode($householdId, $page->lastSeq),
            'has_more' => $page->hasMore,
            'server_time' => Date::now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
