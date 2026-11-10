<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping\Templates;

use IntrAgile\LaravelSpreadsheet\Mapping\HeaderFingerprint;

/** Keeps mapping templates in the table published by the `spreadsheet-migrations` tag. */
final class DatabaseMappingTemplateStore implements MappingTemplateStore
{
    public function findExact(string $importKey, string $fingerprint): ?MappingTemplate
    {
        $templates = MappingTemplateModel::query()
            ->where('import_key', $importKey)
            ->where('fingerprint', $fingerprint)
            ->get()
            ->map(static fn (MappingTemplateModel $model): MappingTemplate => $model->toTemplate())
            ->all();

        usort($templates, self::byPreference(...));

        return $templates[0] ?? null;
    }

    public function findSimilar(string $importKey, array $headers, float $threshold = 0.8): ?MappingTemplate
    {
        $ignored = HeaderFingerprint::ignoredHeaders();
        $wanted = array_unique(HeaderFingerprint::tokens($headers, $ignored));
        $best = null;
        $bestScore = 0.0;

        $candidates = MappingTemplateModel::query()->where('import_key', $importKey)->get();

        foreach ($candidates as $model) {
            $template = $model->toTemplate();
            $own = array_unique(HeaderFingerprint::tokens($template->headers, $ignored));
            $union = count(array_unique([...$wanted, ...$own]));

            if ($union === 0) {
                continue;
            }

            $score = count(array_intersect($wanted, $own)) / $union;

            if ($score + 1e-9 < $threshold) {
                continue;
            }

            if ($best === null || $score > $bestScore + 1e-9 || (abs($score - $bestScore) <= 1e-9 && self::byPreference($template, $best) < 0)) {
                $best = $template;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function save(MappingTemplate $template): void
    {
        MappingTemplateModel::query()->updateOrCreate(
            ['import_key' => $template->importKey, 'fingerprint' => $template->fingerprint, 'name' => $template->name],
            ['headers' => $template->headers, 'mapping' => $template->mapping, 'last_used_at' => $template->lastUsedAt],
        );
    }

    public function touch(MappingTemplate $template): void
    {
        MappingTemplateModel::query()
            ->where('import_key', $template->importKey)
            ->where('fingerprint', $template->fingerprint)
            ->where('name', $template->name)
            ->update(['last_used_at' => now()]);
    }

    /** Named templates first, then the most recently used; never-used templates count as oldest. */
    private static function byPreference(MappingTemplate $a, MappingTemplate $b): int
    {
        return [$a->name === '' ? 1 : 0, -($a->lastUsedAt?->getTimestamp() ?? -PHP_INT_MAX)]
            <=> [$b->name === '' ? 1 : 0, -($b->lastUsedAt?->getTimestamp() ?? -PHP_INT_MAX)];
    }
}
