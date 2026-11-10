<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 */
final class Contact extends Model
{
    protected $table = 'contacts';

    protected $guarded = [];

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
