# Who builds what

The engine is built once, here, and used by both apps.

| Part                                                                | Built by                 | First user      |
| ------------------------------------------------------------------- | ------------------------ | --------------- |
| `js/`: SQL adapter, ULIDs, migration runner, store with outbox states and merging, `operate`, `useLocalQuery`, pull rebase, ack handling | Mealou agent (from Mealou PR #48) | Mealou phase 1 |
| `js/`: sync client (push, pull, resync, purge) and scheduler (debounce, backoff) | Hauzer agent | Hauzer step 2 |
| `js/`: realtime pokes, file queue, backfill | Hauzer agent | Hauzer steps 3–4 |
| `src/`: sequence, `SyncsToDevices`, tombstones, receipts, cursor, pull feed | Hauzer agent              | Hauzer step 1 |
| `src/`: push applier, receipts, aliases, write specs, `HouseholdChanged` event | Hauzer agent | Hauzer step 3 |

Rules:

- `docs/protocol-v1.md` and `fixtures/v1/` are the contract. Change them only in
  a pull request that both sides review.
- Keep app names out of the package. Apps pass their schema, specs and handlers.
- Each part lands through its own pull request on `main`.
