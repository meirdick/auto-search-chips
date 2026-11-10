<?php

namespace Meirdick\SearchChips\Data;

use Illuminate\Support\Str;

final class ModelSchema
{
    /**
     * @param  array<string, FieldSchema>  $fields
     * @param  array<string, RelationSchema>  $relations
     * @param  array<int, string>  $searchable
     * @param  array<int, string>  $orderable
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly array $fields,
        public readonly array $relations = [],
        public readonly array $searchable = [],
        public readonly array $orderable = [],
    ) {}

    public function field(string $name): ?FieldSchema
    {
        return $this->fields[$name] ?? null;
    }

    /** @return array<string, FieldSchema> */
    public function fieldsOfKind(string ...$kinds): array
    {
        return array_filter($this->fields, fn (FieldSchema $f) => in_array($f->kind, $kinds, true));
    }

    public function numericFields(): array
    {
        return $this->fieldsOfKind('number');
    }

    public function dateFields(): array
    {
        return $this->fieldsOfKind('date', 'datetime');
    }

    public function enumFields(): array
    {
        return array_values(array_filter($this->fields, fn (FieldSchema $field) => $field->values !== null && $field->valueSource !== 'sampled' || $field->kind === 'enum'));
    }

    public function booleanFields(): array
    {
        return $this->fieldsOfKind('boolean');
    }

    public function textFields(): array
    {
        return $this->fieldsOfKind('text');
    }

    /**
     * Resolve a user token to a field by exact name, alias, label, or
     * case/space variants. Returns the canonical field name.
     */
    public function resolveFieldToken(string $token): ?FieldSchema
    {
        $token = strtolower(trim($token));

        if ($token === '') {
            return null;
        }

        $variants = [
            $token,
            str_replace([' ', '-'], '_', $token),
            str_replace('_', ' ', $token),
            Str::camel($token),
            Str::snake($token),
        ];

        // "most viewed" means the views field; "commented" the comments one.
        if (strlen($token) > 4 && str_ends_with($token, 'ed')) {
            $variants[] = substr($token, 0, -2);
            $variants[] = substr($token, 0, -1);
        }

        foreach ($variants as $variant) {
            if (isset($this->fields[$variant])) {
                return $this->fields[$variant];
            }
        }

        foreach ($this->fields as $field) {
            foreach ($variants as $variant) {
                if (in_array($variant, $field->matchTokens(), true)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * The default date field for bare temporal expressions ("last week"):
     * published_at/created_at when present, else the first date field.
     */
    public function defaultDateField(): ?FieldSchema
    {
        foreach (['published_at', 'created_at', 'updated_at'] as $preferred) {
            if (isset($this->fields[$preferred]) && $this->fields[$preferred]->isDate()) {
                return $this->fields[$preferred];
            }
        }

        return $this->dateFields() ? array_values($this->dateFields())[0] : null;
    }

    /**
     * The fields as a filter panel needs them: name, label, kind, options.
     *
     * @return array<int, array{name: string, label: string, kind: string, options: array<int, array{value: mixed, label: string}>}>
     */
    public function toPanel(): array
    {
        $panel = [];

        foreach ($this->fields as $field) {
            if ($field->primary) {
                continue;
            }

            $panel[] = [
                'name' => $field->name,
                'label' => $field->label ?? ($field->relation !== null ? ucfirst(str_replace('_', ' ', $field->relation)) : $field->displayLabel()),
                'kind' => $field->values !== null && $field->kind === 'text' ? 'enum' : $field->kind,
                'options' => array_map(fn ($value) => [
                    'value' => $value,
                    'label' => $field->valueLabels[(string) $value] ?? ucfirst((string) $value),
                ], array_values(array_filter((array) $field->values, 'is_scalar'))),
            ];
        }

        return $panel;
    }

    public function version(): string
    {
        return substr(md5(json_encode([
            $this->modelClass,
            array_keys($this->fields),
            array_map(fn (FieldSchema $f) => [$f->kind, $f->values], $this->fields),
            array_keys($this->relations),
            $this->searchable,
            $this->orderable,
        ])), 0, 8);
    }
}
