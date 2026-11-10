<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping\Templates;

/** Remembers nothing; the default when template persistence is off. */
final class NullMappingTemplateStore implements MappingTemplateStore
{
    public function findExact(string $importKey, string $fingerprint): ?MappingTemplate
    {
        return null;
    }

    public function findSimilar(string $importKey, array $headers, float $threshold = 0.8): ?MappingTemplate
    {
        return null;
    }

    public function save(MappingTemplate $template): void {}

    public function touch(MappingTemplate $template): void {}
}
