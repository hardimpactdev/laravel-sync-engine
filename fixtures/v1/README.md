# Protocol v1 fixtures

The entities here (`items`, `rooms`, `counters`) are neutral examples, not the
tables of any app. `counters.adjust` stands for any delta operation.
`opaque-cursor-*` stand for real encrypted cursors, which tests compare by value
only. See `docs/protocol-v1.md` §7.

`push-request-proposals.json` and `push-response-proposals.json` pin proposed
rows (§5.5). `items.shelve {room_name}` stands for any action that makes a row:
it puts the item in the room with that name and makes the room under the
proposed id. The ack reports it in `made`, and the response announces
`features`. `push-response.json` has no `features`, as a server from before
§5.5 answers.
