<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
final class Tag extends Model
{
    protected $table = 'tags';

    protected $guarded = [];
}
