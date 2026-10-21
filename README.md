# Sync Engine for Laravel

The server half of Sync Engine: local-first sync between a Laravel app and an
app that keeps its data in SQLite on the device. The client half is the
TypeScript package
[sync-engine-typescript](https://github.com/hardimpactdev/sync-engine-typescript)
(`@hardimpactdev/sync-engine`).

The package gives every write a per-scope `change_seq`, serves a change feed
after a cursor, applies pushed changes through your own domain code, and fires
an event after each commit that you broadcast as a real-time poke.

A **scope** is the unit that syncs together and that a user may read: a team, a
household, a workspace. Your app decides what a scope is and who may read it.

## How it works

1. The device writes a row and its outbox entry in one SQLite transaction.
2. The sync client posts the next outbox batch and its pull cursor to one
   endpoint.
3. The server applies each change in its own savepoint, through your handler,
   and stores a receipt, so a resend is a duplicate. Each change gets an ack.
4. The server answers with the acks and the next page of the change feed. The
   device lays its pending changes over the pulled rows until the server
   confirms them.
5. After the commit the server fires `ScopeChanged`. Your app broadcasts it as
   a small poke, and other open devices of that scope pull at once.

Conflicts: the last write wins per field, in server order. A delete wins over an
edit. Counters move as deltas through named operations, so concurrent changes
add up.

The wire contract is [`docs/protocol-v1.md`](docs/protocol-v1.md). The JSON
fixtures in [`fixtures/v1/`](fixtures/v1) pin it. This repository owns both;
sync-engine-typescript keeps a copy that its CI checks against this one.

## Requirements

- PHP 8.4+ and Laravel 13.
- SQLite, MySQL, MariaDB or PostgreSQL. Each pull page reads in one
  transaction that sees one snapshot: a deferred read transaction on SQLite
  (use WAL mode, so pulls and writes do not block each other), REPEATABLE READ
  on the others. On SQLite, `transaction_mode` IMMEDIATE or EXCLUSIVE makes
  every pull take the write lock.
- If you call the feed inside your own transaction, it keeps your isolation.
  At READ COMMITTED or stronger no change is skipped, but a row's relations
  may be newer than its seq. Use REPEATABLE READ for one snapshot.
- Each push takes its scope's write lock before it reads, so concurrent pushes
  wait for each other instead of failing on SQLite. If you apply a push inside
  your own transaction on SQLite, write before you read in it, or use
  `transaction_mode` IMMEDIATE for that connection.
- On the device, [`@hardimpactdev/sync-engine`](https://github.com/hardimpactdev/sync-engine-typescript),
  or any client that speaks protocol v1.

## Install

Install the package from Packagist. Composer adds it as `^0.1`:

```sh
composer require hardimpactdev/sync-engine
```

The service provider registers itself. It loads the engine's migrations, which
create `sync_states`, `sync_tombstones`, `sync_receipts`, `sync_aliases` and
`sync_clients`. Publish the config to change table names, the page size or the
retention periods:

```sh
php artisan vendor:publish --tag=sync-engine-config
php artisan migrate
```

## Server

### Make a model sync

The scope model itself (here `Team`) needs a unique `sync_id` ULID column: it is
the scope's id on the wire and in the channel name. It does not need the trait.

A synced table needs a unique `sync_id` (a ULID), a nullable `change_seq` and
the column that holds the scope:

```php
Schema::table('projects', function (Blueprint $table) {
    $table->char('sync_id', 26)->nullable()->unique();
    $table->unsignedBigInteger('change_seq')->nullable();
    $table->index(['team_id', 'change_seq']);
});

// Give existing rows a sync_id and a seq, so they are in the feed.
\HardImpact\SyncEngine\Backfill::run('projects', scopeColumn: 'team_id');
```

Add the trait. The scope column is `scope_id` unless you say otherwise:

```php
use HardImpact\SyncEngine\Concerns\SyncsToDevices;

class Project extends Model
{
    use SyncsToDevices;

    public function syncScopeColumn(): string
    {
        return 'team_id';
    }

    /** Changes to these columns do not reach devices. */
    public function syncIgnoredAttributes(): array
    {
        return ['last_viewed_at'];
    }
}
```

Every save that changes a synced attribute now takes the next `change_seq` of
its scope, and every delete leaves a tombstone. Saves and deletes run in a
transaction, so the seq and the row commit together.

### Register an entity

An entity says how a model looks on the wire and what a device may change. The
handlers call your own actions, so validation and side effects stay in your
app.

```php
use HardImpact\SyncEngine\Push\SyncWrite;
use HardImpact\SyncEngine\SyncEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ProjectEntity extends SyncEntity
{
    public function name(): string
    {
        return 'projects'; // the entity name on the wire
    }

    public function query(int $scopeId): Builder
    {
        return Project::query()->where('team_id', $scopeId);
    }

    public function row(Model $model): array
    {
        // References are sync_ids, never server ids.
        return ['name' => $model->name, 'customer_id' => $model->customer?->sync_id];
    }

    public function writable(): array
    {
        return ['name', 'customer_id'];
    }

    public function references(): array
    {
        return ['customer_id' => 'customers']; // resolved to the Customer model before upsert()
    }

    public function upsert(SyncWrite $write): Model
    {
        return $write->existing
            ? app(UpdateProject::class)->handle($write->existing, $write->fields)
            : app(CreateProject::class)->handle($write->scopeId, $write->syncId, $write->fields);
    }
}
```

`upsert` gets the fields with references already resolved to models. Override
`delete` for a custom delete, `operations` and `operate` for named operations
such as `adjust {delta}`, and `match` to land a new row on an existing one (two
devices that both add "Kitchen" get one row). A handler rejects a change by
throwing a `ValidationException` or a `SyncRejection`; the device gets the
error and the server's current row.

Register your entities in a service provider:

```php
public function boot(): void
{
    $this->app->make(\HardImpact\SyncEngine\SyncEntities::class)
        ->register(new ProjectEntity, new CustomerEntity);
}
```

### Add the endpoint

Your route authenticates the user, checks that they may read the scope and
passes the scope's server id. Answer `404` when they may not: the device then
removes the scope's local data.

```php
use HardImpact\SyncEngine\Http\SyncEndpoint;

Route::post('/api/teams/{team:sync_id}/sync', function (Request $request, Team $team, SyncEndpoint $sync) {
    abort_unless($request->user()->belongsToTeam($team), 404);

    return $sync->respond($request, $team->id);
})->middleware('auth:sanctum');
```

### Broadcast the change event over Reverb

After every commit that changed a scope, the package fires
`HardImpact\SyncEngine\Events\ScopeChanged` once, with `scopeId`, `seq` and the
pushing `clientId` (null for web, jobs and imports). A rolled-back write fires
nothing. The package does not broadcast it; your app sends the poke on the
private channel `sync.{scope_sync_id}` as `scope.changed` (protocol §4):

```php
use HardImpact\SyncEngine\Events\ScopeChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class SyncPoke implements ShouldBroadcastNow
{
    public function __construct(public string $scopeSyncId, public int $seq, public ?string $clientId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("sync.{$this->scopeSyncId}");
    }

    public function broadcastAs(): string
    {
        return 'scope.changed';
    }

    public function broadcastWith(): array
    {
        return ['seq' => $this->seq, 'client_id' => $this->clientId];
    }
}

// In a service provider
Event::listen(function (ScopeChanged $event) {
    $team = Team::find($event->scopeId);

    if ($team === null) {
        return; // the team was deleted in the same transaction
    }

    broadcast(new SyncPoke($team->sync_id, $event->seq, $event->clientId));
});

// routes/channels.php
Broadcast::channel('sync.{syncId}', fn (User $user, string $syncId) => $user->teams()->where('sync_id', $syncId)->exists());
```

The poke carries no row data. It only tells devices to pull. On the device,
listen with Echo and trigger a sync:

```ts
echo.private(`sync.${teamSyncId}`).listen(".scope.changed", () => scheduler.trigger("poke"));
```

### Prune old tombstones

Tombstones and receipts are kept for a while (180 and 30 days by default).
Schedule the prune command:

```php
Schedule::command('sync-engine:prune-tombstones')->daily();
```

A device whose cursor is older than the pruned history gets `409
resync_required` and pulls the scope again from the start.

## Rules

- **Write synced models only through Eloquent, so model events fire.**
  `saveQuietly`, `withoutEvents`, query-builder writes (`Project::query()->update()`,
  `DB::table('projects')->insert()`) and mass deletes skip the feed: devices
  never pull the change, and a delete leaves no tombstone. Load the models and
  save or delete them one by one.
- **Take the seq inside the write's transaction.** The trait does this for you.
  `SyncSequence::next()` throws outside a transaction.
- **References are sync_ids on the wire,** never server integer ids.
- **Push handlers call your domain actions.** Do not write around them, or the
  device and the web app will validate differently.
- **A breaking wire change needs a new protocol number.** Change
  `docs/protocol-v1.md` and `fixtures/v1/` together, here first, then copy them
  to sync-engine-typescript. Adding an optional field is not breaking.

## Development

| Intent     | Command          |
| ---------- | ---------------- |
| All checks | `composer check` |
| Tests      | `composer test`  |

## License

MIT. See [LICENSE](LICENSE).
