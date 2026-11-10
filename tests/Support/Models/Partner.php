<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $city
 * @property string|null $email
 * @property string|null $tax_number
 * @property string|null $revenue
 * @property Carbon|null $founded_at
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

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'partner_tag');
    }

    /** @return HasManyThrough<DomainContact, Domain, $this> */
    public function domainContacts(): HasManyThrough
    {
        return $this->hasManyThrough(DomainContact::class, Domain::class);
    }
}
