<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Tests\Fixtures;

use HardImpact\HouseholdSync\Concerns\SyncsToDevices;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 */
final class Room extends Model
{
    use SyncsToDevices;

    protected $guarded = [];
}
