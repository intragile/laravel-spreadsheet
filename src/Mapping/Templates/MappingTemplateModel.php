<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping\Templates;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $import_key
 * @property string $fingerprint
 * @property string $name
 * @property list<string> $headers
 * @property array<string, string> $mapping
 * @property Carbon|null $last_used_at
 */
final class MappingTemplateModel extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('spreadsheet.templates.table', 'spreadsheet_mapping_templates');

        return is_string($table) ? $table : 'spreadsheet_mapping_templates';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function toTemplate(): MappingTemplate
    {
        return new MappingTemplate(
            $this->import_key,
            $this->fingerprint,
            $this->name,
            $this->headers,
            $this->mapping,
            $this->last_used_at?->toDateTimeImmutable(),
        );
    }
}
