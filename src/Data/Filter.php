<?php

namespace Meirdick\SearchChips\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A single chip. One validated filter over one schema field, carrying its
 * provenance (source, confidence, spans) so the UI can show what the
 * search understood and where it came from.
 */
final class Filter implements Arrayable
{
    public function __construct(
        public readonly string $id,
        public readonly string $field,
        public readonly string $operator,
        public readonly mixed $value,
        public readonly bool $negated = false,
        public readonly ?string $label = null,
        public readonly string $source = 'rules',
        public readonly float $confidence = 1.0,
        public readonly ?array $spans = null,
        public readonly ?string $unit = null,
        public readonly bool $suggested = false,
        /**
         * Explicit chips come from deliberate user syntax (colon, quoted
         * phrases) and survive engine merging; inferred chips may be replaced
         * by an AI engine's reading of the same field.
         */
        public readonly bool $explicit = false,
    ) {}

    public static function fromArray(array $raw, ?string $id = null): self
    {
        return new self(
            id: (string) ($raw['id'] ?? $id ?? 'raw'),
            field: (string) ($raw['field'] ?? ''),
            operator: (string) ($raw['operator'] ?? ''),
            value: $raw['value'] ?? null,
            negated: (bool) ($raw['negated'] ?? false),
            label: $raw['label'] ?? null,
            source: (string) ($raw['source'] ?? 'rules'),
            confidence: (float) ($raw['confidence'] ?? 1.0),
            spans: isset($raw['spans']) && is_array($raw['spans']) ? array_values($raw['spans']) : null,
            unit: $raw['unit'] ?? null,
            suggested: (bool) ($raw['suggested'] ?? false),
            explicit: (bool) ($raw['explicit'] ?? false),
        );
    }

    public function withLabel(string $label): self
    {
        return new self($this->id, $this->field, $this->operator, $this->value, $this->negated, $label, $this->source, $this->confidence, $this->spans, $this->unit, $this->suggested, $this->explicit);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => self::plain($this->value),
            'negated' => $this->negated,
            'label' => $this->label ?? $this->field,
            'source' => $this->source,
            'confidence' => round($this->confidence, 3),
            'spans' => $this->spans,
            'unit' => $this->unit,
            'suggested' => $this->suggested,
        ];
    }

    /** Dates leave as strings, so a chip survives JSON, Livewire and a URL unchanged. */
    private static function plain(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * A human label, e.g. "Views at least 10,000" or "Author contains Ada".
     */
    public function describe(string $fieldLabel): string
    {
        $value = $this->displayValue();

        if ($this->negated) {
            $phrase = match ($this->operator) {
                Operator::CONTAINS => 'does not contain',
                Operator::STARTS_WITH => 'does not start with',
                Operator::ENDS_WITH => 'does not end with',
                Operator::EQUALS => 'is not',
                default => 'not '.Operator::describe($this->operator),
            };

            return trim($fieldLabel.' '.$phrase.' '.$value);
        }

        return match ($this->operator) {
            Operator::IS_TRUE, Operator::IS_FALSE, Operator::IS_EMPTY, Operator::NOT_EMPTY => trim($fieldLabel.' '.Operator::describe($this->operator)),
            default => trim($fieldLabel.' '.Operator::describe($this->operator).' '.$value),
        };
    }

    public function displayValue(): string
    {
        if (is_array($this->value)) {
            $values = array_map(fn ($v) => match (true) {
                is_int($v) || is_float($v) => self::number($v),
                $v instanceof \DateTimeInterface => $v->format('M j, Y'),
                is_scalar($v) => (string) $v,
                default => json_encode($v),
            }, $this->value);

            return implode(' – ', $values);
        }

        if ($this->value instanceof \DateTimeInterface) {
            return $this->value->format('M j, Y');
        }

        if (is_int($this->value) || is_float($this->value)) {
            return self::number($this->value);
        }

        return (string) $this->value;
    }

    /** 10000.0 reads "10,000"; 0.085 stays "0.085". */
    private static function number(int|float $value): string
    {
        return floor($value) == $value
            ? number_format($value)
            : rtrim(rtrim(number_format($value, 4), '0'), '.');
    }
}
