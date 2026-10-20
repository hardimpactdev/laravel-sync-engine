# household-sync

Local-first sync for a Laravel server and an Expo (React Native) app. Shared by
Hauzer and Mealou.

- The phone keeps household data in SQLite and writes through one store with an
  outbox.
- The server gives every write a per-household `change_seq` and serves a change
  feed after a cursor.
- One endpoint pushes changes and pulls the feed. Each change has its own ack.
- After every commit the server sends a small Reverb poke, and open devices pull
  at once.

The contract is [`docs/protocol-v1.md`](docs/protocol-v1.md). The design and its
reasons are in Hauzer's ADR `docs/adr/mobile-local-first-sync.md`.

## Layout

| Path        | What                                                    | Package                           |
| ----------- | ------------------------------------------------------- | --------------------------------- |
| `src/`      | Laravel side: sequence, tombstones, receipts, endpoint  | Composer `hardimpactdev/household-sync` |
| `js/src/`   | Phone side: store, outbox, sync client, realtime        | npm `@hardimpactdev/household-sync`     |
| `fixtures/` | Wire format fixtures read by both test suites           | —                                 |
| `docs/`     | Protocol and architecture                               | —                                 |

The package holds only the engine. Each app supplies its schema, its entity
specs and the handlers that call its own domain actions.

## Commands

| Intent               | Command                         |
| -------------------- | ------------------------------- |
| PHP tests            | `composer test`                 |
| TypeScript tests     | `npm test`                      |
| TypeScript typecheck | `npm run typecheck`             |

## Phone store

```ts
import { createLocalStore, migrate, syncEngineSchemaV1 } from "@hardimpactdev/household-sync";
import { useLocalQuery } from "@hardimpactdev/household-sync/react";

migrate(db, [syncEngineSchemaV1, appSchema]);
const store = createLocalStore(db, {
    tables: ["rooms", "items", "counters"],
    entities: {
        items: { references: { room_id: "rooms" }, serverOwned: ["illustration"] },
        counters: { operations: { adjust: { counters: [{ field: "quantity", delta: "delta" }] } } },
    },
    newId,
});

store.update("items", itemId, { name: "Dishwasher" });
store.operate("counters", counterId, "adjust", { delta: 1 });
```

A sync run calls `takeBatch`, posts the changes, then `applyAcks` and
`applyPullPage` with the parsed response, or `returnToPending` when the request
fails. The HTTP client, realtime pokes and the file queue come next.

## Status

Under construction. The phone side starts from Mealou's local store (Mealou PR
#48). The server side starts with Hauzer's inventory (step 1 of the ADR).
