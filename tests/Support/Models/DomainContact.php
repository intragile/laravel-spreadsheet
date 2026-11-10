<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 */
final class DomainContact extends Model
{
    protected $table = 'domain_contacts';

    protected $guarded = [];
}
