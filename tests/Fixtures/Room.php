<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests\Fixtures;

use HardImpact\SyncEngine\Concerns\SyncsToDevices;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $name
 */
final class Room extends Model
{
    use SoftDeletes;
    use SyncsToDevices;

    protected $guarded = [];
}
