<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests\Fixtures;

use HardImpact\SyncEngine\Concerns\SyncsToDevices;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $sync_id
 * @property string $name
 * @property int $quantity
 */
final class Counter extends Model
{
    use SyncsToDevices;

    protected $guarded = [];
}
