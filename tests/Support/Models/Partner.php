<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string|null $city
 */
final class Partner extends Model
{
    use SoftDeletes;

    protected $table = 'partners';

    protected $guarded = [];

    protected $casts = [
        'revenue' => 'decimal:2',
        'founded_at' => 'date',
        'active' => 'boolean',
    ];

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
