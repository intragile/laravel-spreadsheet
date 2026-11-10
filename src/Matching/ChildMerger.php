<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\Canonicalizer;

/**
 * Merges one imported child row (a contact, an address, ...) into a parent's children.
 *
 * Keys are ordered by priority. The first key the row carries decides the match: a child
 * matches when that key is equal and every higher-priority key is blank on the child, so a
 * row identified only by a lower-priority key never claims a child that has a higher one.
 * A matched child only gets its blank attributes filled; existing values are never overwritten.
 */
final class ChildMerger
{
    public function __construct(private readonly ?CanonicalizerRegistry $registry = null) {}

    /**
     * @template TRelated of Model
     * @template TDeclaring of Model
     *
     * @param  HasOneOrMany<TRelated, TDeclaring, *>|MorphOneOrMany<TRelated, TDeclaring, *>  $relation
     * @param  array<string, mixed>  $attributes
     * @param  array<string, Canonicalizer|string>  $keys  Attribute => canonicalizer, highest priority first.
     */
    public function merge(HasOneOrMany|MorphOneOrMany $relation, array $attributes, array $keys): ChildMergeOutcome
    {
        $registry = $this->registry ?? app(CanonicalizerRegistry::class);
        $canonicalizers = array_map(
            static fn (Canonicalizer|string $c): Canonicalizer => $c instanceof Canonicalizer ? $c : $registry->for($c),
            $keys,
        );

        $attributeNames = array_keys($canonicalizers);

        foreach ($attributeNames as $position => $attribute) {
            $raw = $attributes[$attribute] ?? null;
            $key = $canonicalizers[$attribute]->canonicalize($raw);

            if ($key === null) {
                continue;
            }

            $higher = array_slice($attributeNames, 0, $position);
            $match = $this->find($relation, $canonicalizers, $attribute, $key, $raw, $higher);

            return $match === null
                ? $this->create($relation, $attributes)
                : $this->fill($match, $attributes, $canonicalizers);
        }

        return ChildMergeOutcome::Unchanged;
    }

    /**
     * @template TRelated of Model
     * @template TDeclaring of Model
     *
     * @param  HasOneOrMany<TRelated, TDeclaring, *>|MorphOneOrMany<TRelated, TDeclaring, *>  $relation
     * @param  array<string, Canonicalizer>  $canonicalizers
     * @param  list<string>  $higher
     */
    private function find(HasOneOrMany|MorphOneOrMany $relation, array $canonicalizers, string $attribute, string $key, mixed $raw, array $higher): ?Model
    {
        $query = $relation->getQuery()->clone();
        $canonicalizers[$attribute]->prefilter($query, $attribute, [$key], is_scalar($raw) ? [(string) $raw] : []);

        foreach ($query->get() as $child) {
            foreach ($higher as $other) {
                if ($canonicalizers[$other]->canonicalize($child->getAttribute($other)) !== null) {
                    continue 2;
                }
            }

            if ($canonicalizers[$attribute]->canonicalize($child->getAttribute($attribute)) === $key) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @template TRelated of Model
     * @template TDeclaring of Model
     *
     * @param  HasOneOrMany<TRelated, TDeclaring, *>|MorphOneOrMany<TRelated, TDeclaring, *>  $relation
     * @param  array<string, mixed>  $attributes
     */
    private function create(HasOneOrMany|MorphOneOrMany $relation, array $attributes): ChildMergeOutcome
    {
        $child = $relation->make();
        $child->forceFill($attributes);
        $relation->save($child);

        return ChildMergeOutcome::Added;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, Canonicalizer>  $canonicalizers
     */
    private function fill(Model $child, array $attributes, array $canonicalizers): ChildMergeOutcome
    {
        foreach ($attributes as $attribute => $value) {
            if ($this->isBlank($value) || ! $this->isBlank($child->getAttribute($attribute))) {
                continue;
            }

            if (isset($canonicalizers[$attribute]) && $canonicalizers[$attribute]->canonicalize($value) === null) {
                continue;
            }

            $child->forceFill([$attribute => $value]);
        }

        if (! $child->isDirty()) {
            return ChildMergeOutcome::Unchanged;
        }

        $child->save();

        return ChildMergeOutcome::Filled;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
