<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Pipeline;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizerRegistry;

/**
 * Normalizes the mapped cells of a row with each column's own context, then validates the
 * normalized values with the import's rules.
 */
final class RowProcessor
{
    /** @var array<string, Field> */
    private array $fields = [];

    /** @var array<int, array{0: Field, 1: NormalizationContext}> */
    private array $columns = [];

    /** @var array<int, array<string, array{0: string, 1: list<mixed>}>> */
    private array $rules = [];

    /** @var array<string, array<string, string>> */
    private array $names = [];

    public function __construct(
        private readonly Import $import,
        ColumnMapping $mapping,
        private readonly NormalizerRegistry $normalizers,
        private readonly ValidationFactory $validator,
    ) {
        foreach ($import->fields() as $field) {
            $this->fields[$field->key] = $field;
        }

        $configured = config('spreadsheet.import.locales', ['en']);
        $locales = array_values(array_filter(is_array($configured) ? $configured : ['en'], is_string(...)));

        foreach ($mapping->columns as $match) {
            if ($match->field !== null && isset($this->fields[$match->field])) {
                $this->columns[$match->column] = [
                    $this->fields[$match->field],
                    new NormalizationContext($locales, $match->profile->dateFormat, $match->profile->decimalSeparator),
                ];
            }
        }
    }

    /** @param  array<int, mixed>  $cells */
    public function process(int $rowNumber, array $cells, bool $isUpdate = false): ProcessedRow
    {
        $values = [];
        $errors = [];
        $warnings = [];
        $failed = [];

        foreach ($this->columns as $column => [$field, $context]) {
            $result = $this->normalizers->for($field)->normalize($cells[$column] ?? null, $field, $context);
            $values[$field->key] = $result->value;

            if ($result->error !== null) {
                $failed[$field->key] = true;
                $errors[] = $this->label($field).': '.$this->translate($result->error, $result->errorParams);
            }

            if ($result->warning !== null) {
                $warnings[] = $this->label($field).': '.$this->translate($result->warning, $result->warningParams);
            }
        }

        foreach ($this->validate($values, $failed, $isUpdate) as $message) {
            $errors[] = $message;
        }

        return new ProcessedRow($rowNumber, $cells, $values, $errors, $warnings);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, true>  $failed
     * @return list<string>
     */
    private function validate(array $values, array $failed, bool $isUpdate): array
    {
        $rules = [];

        foreach ($this->rulesFor($isUpdate) as $key => [$root, $rule]) {
            if (! isset($failed[$root])) {
                $rules[$key] = $rule;
            }
        }

        if ($rules === []) {
            return [];
        }

        $validator = $this->validator->make($values, $rules, [], $this->names());

        return array_values(array_map(strval(...), $validator->errors()->all()));
    }

    /**
     * Rules of one mode, normalized once: [root key, rule list] by rule key.
     *
     * @return array<string, array{0: string, 1: list<mixed>}>
     */
    private function rulesFor(bool $isUpdate): array
    {
        if (isset($this->rules[(int) $isUpdate])) {
            return $this->rules[(int) $isUpdate];
        }

        $mapped = [];
        foreach ($this->columns as [$field]) {
            $mapped[$field->key] = $field;
        }

        $rules = [];

        foreach ($isUpdate ? $this->import->updateRules() : $this->import->rules() as $key => $rule) {
            $root = explode('.', (string) $key)[0];
            $list = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? array_values($rule) : [$rule]);

            if (isset($this->fields[$root])) {
                if (! isset($mapped[$root])) {
                    continue;
                }

                $list = $this->fieldRules($mapped[$root], $list, $isUpdate);
            }

            $rules[(string) $key] = [$root, $list];
        }

        if (! $isUpdate) {
            foreach ($this->fields as $key => $field) {
                if ($field->isRequired() && ! isset($rules[$key])) {
                    $rules[$key] = [$key, ['required']];
                }
            }
        }

        return $this->rules[(int) $isUpdate] = $rules;
    }

    /**
     * @param  list<mixed>  $list
     * @return list<mixed>
     */
    private function fieldRules(Field $field, array $list, bool $isUpdate): array
    {
        if (in_array('required', $list, true)) {
            return $list;
        }

        if (! $isUpdate && $field->isRequired()) {
            array_unshift($list, 'required');
        } elseif (! in_array('nullable', $list, true)) {
            array_unshift($list, 'nullable');
        }

        return $list;
    }

    /** @return array<string, string> */
    private function names(): array
    {
        $locale = app()->getLocale();

        if (! isset($this->names[$locale])) {
            $names = [];
            foreach ($this->columns as [$field]) {
                $names[$field->key] = $this->label($field);
            }
            foreach ($this->fields as $key => $field) {
                $names[$key] ??= $this->label($field);
            }
            $this->names[$locale] = $names;
        }

        return $this->names[$locale];
    }

    private function label(Field $field): string
    {
        $labels = $field->labels();

        return $labels[app()->getLocale()] ?? $labels[Field::ANY_LOCALE] ?? $field->key;
    }

    /** @param  array<string, mixed>  $params */
    private function translate(string $key, array $params): string
    {
        $replace = [];

        foreach ($params as $name => $value) {
            $replace[$name] = is_array($value) ? implode(', ', array_filter($value, is_scalar(...))) : (is_scalar($value) ? (string) $value : '');
        }

        return (string) __($key, $replace);
    }
}
