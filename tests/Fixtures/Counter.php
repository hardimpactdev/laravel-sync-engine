<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Tests\Fixtures;

use HardImpact\HouseholdSync\Concerns\SyncsToDevices;
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
