<?php

declare(strict_types=1);

return [
    // The wire protocol this server speaks. See docs/protocol-v1.md.
    'protocol' => 1,

    'tables' => [
        'states' => 'household_sync_states',
        'tombstones' => 'sync_tombstones',
    ],

    // Rows per pull page; a request may ask for fewer.
    'page_size' => 500,

    // Tombstones older than this are pruned and raise the household's floor_seq.
    'tombstone_days' => 180,
];
