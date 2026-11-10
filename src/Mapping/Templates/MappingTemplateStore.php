<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping\Templates;

interface MappingTemplateStore
{
    /** The template saved for this exact header fingerprint; a named one wins, then the most recently used. */
    public function findExact(string $importKey, string $fingerprint): ?MappingTemplate;

    /**
     * The template of this import whose headers overlap the given ones the most, provided
     * the Jaccard similarity reaches the threshold.
     *
     * @param  list<string>  $headers
     */
    public function findSimilar(string $importKey, array $headers, float $threshold = 0.8): ?MappingTemplate;

    /** Inserts the template, or replaces the one with the same import key, fingerprint and name. */
    public function save(MappingTemplate $template): void;

    /** Records that the template was just used. */
    public function touch(MappingTemplate $template): void;
}
