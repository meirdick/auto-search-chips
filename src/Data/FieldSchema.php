<?php

namespace Meirdick\SearchChips\Data;

use Illuminate\Support\Str;

final class FieldSchema
{
    /**
     * @param  array<int, string>  $aliases
     * @param  array<int, mixed>|null  $values  enum values (or sampled values in magic mode)
     * @param  array<string, string>  $valueLabels  value => human label
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $label = null,
        public readonly array $aliases = [],
        public readonly ?array $values = null,
        public readonly string $valueSource = 'declared',
        public readonly array $valueLabels = [],
        public readonly ?string $unit = null,
        public readonly ?float $scale = null,
        public readonly ?string $relation = null,
        public readonly ?string $relationColumn = null,
        public readonly bool $searchable = false,
        public readonly bool $orderable = false,
        public readonly bool $primary = false,
        /** @var array<string, scalar|null> column => value pairs a row must match before this filter can select it */
        public readonly array $conditions = [],
        /** @var array<string, scalar> other words for a value: 'movie' => 'video' */
        public readonly array $synonyms = [],
    ) {}

    /** The allowed value a word names: exact, plural, label or synonym. Null when it names none. */
    public function resolveValue(mixed $item): mixed
    {
        if ($this->values === null) {
            return is_scalar($item) ? $item : null;
        }

        foreach ($this->values as $value) {
            if ($item === $value) {
                return $value;
            }

            if (is_scalar($item) && is_scalar($value) && strtolower((string) $value) === strtolower(trim((string) $item))) {
                return $value;
            }
        }

        // "videos" is the plural of the value "video"; accept both directions.
        foreach ($this->values as $value) {
            if (! is_scalar($item) || ! is_scalar($value)) {
                continue;
            }

            $itemLower = strtolower(trim((string) $item));
            $valueLower = strtolower((string) $value);

            if (Str::plural($valueLower) === $itemLower || Str::singular($itemLower) === $valueLower) {
                return $value;
            }
        }

        foreach ($this->valueLabels as $value => $label) {
            if (is_scalar($item) && strcasecmp(trim((string) $item), $label) === 0) {
                return $value;
            }
        }

        if (is_scalar($item) && ($synonym = $this->synonymFor((string) $item)) !== null) {
            return $synonym;
        }

        return null;
    }

    /** The value a word stands for, by synonym, in singular or plural. */
    public function synonymFor(string $word): mixed
    {
        $word = mb_strtolower(trim($word));

        foreach ($this->synonyms as $synonym => $value) {
            $synonym = mb_strtolower((string) $synonym);

            if ($word === $synonym || $word === Str::plural($synonym)) {
                return $value;
            }
        }

        return null;
    }

    public function displayLabel(): string
    {
        return $this->label ?? ucwords(str_replace(['.', '_'], [' ', ' '], $this->name));
    }

    /**
     * Tokens that resolve to this field: name, label, aliases, and their
     * snake/case variants, pluralized for value-style words ("views").
     *
     * @return array<int, string>
     */
    public function matchTokens(): array
    {
        $tokens = [$this->name, ...$this->aliases];

        if ($this->label) {
            $tokens[] = $this->label;
        }

        $normalized = [];

        foreach ($tokens as $token) {
            $token = strtolower(trim($token));
            if ($token === '') {
                continue;
            }
            $normalized[$token] = true;
            $normalized[str_replace(['-', ' '], '_', $token)] = true;

            // "view" should match a views column; "engagement rate" an
            // engagement_rate one.
            if (strlen($token) > 3 && str_ends_with($token, 's') && ! str_ends_with($token, 'ss')) {
                $normalized[Str::singular($token)] = true;
                $normalized[str_replace(['-', ' '], '_', Str::singular($token))] = true;
            }
        }

        return array_keys($normalized);
    }

    public function isNumeric(): bool
    {
        return $this->kind === 'number';
    }

    public function isDate(): bool
    {
        return $this->kind === 'date' || $this->kind === 'datetime';
    }
}
