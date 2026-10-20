<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests\Fixtures;

use HardImpact\SyncEngine\Concerns\SyncsToDevices;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $name
 * @property string|null $notes
 */
final class Item extends Model
{
    use SoftDeletes;
    use SyncsToDevices;

    protected $guarded = [];

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return list<string> */
    public function syncIgnoredAttributes(): array
    {
        return ['views'];
    }
}
