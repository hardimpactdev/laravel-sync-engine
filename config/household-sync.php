<?php

declare(strict_types=1);

return [
    // The wire protocol this server speaks. See docs/protocol-v1.md.
    'protocol' => 1,

    'tables' => [
        'states' => 'household_sync_states',
        'tombstones' => 'sync_tombstones',
        'receipts' => 'sync_receipts',
        'aliases' => 'sync_aliases',
        'clients' => 'sync_clients',
    ],

    // Rows per pull page; a request may ask for fewer.
    'page_size' => 500,

    // Tombstones older than this are pruned and raise the household's floor_seq.
    'tombstone_days' => 180,

    // Receipts make a resent change a duplicate. Older ones are pruned.
    'receipt_days' => 30,
];
