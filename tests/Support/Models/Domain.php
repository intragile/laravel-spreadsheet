<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $domain
 */
final class Domain extends Model
{
    use SoftDeletes;

    protected $table = 'domains';

    protected $guarded = [];

    /** @return HasMany<DomainContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(DomainContact::class);
    }
}
