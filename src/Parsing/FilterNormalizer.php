<?php

namespace Meirdick\SearchChips\Parsing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Meirdick\SearchChips\Data\FieldSchema;
use Meirdick\SearchChips\Data\Filter;
use Meirdick\SearchChips\Data\FilterCollection;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\Operator;
use Meirdick\SearchChips\Data\ParsedQuery;
use Meirdick\SearchChips\Data\Stopwords;
use Meirdick\SearchChips\ParseResult;

/**
 * The trust boundary. Every engine's raw output — including the AI engines —
 * passes through here: fields are resolved against the schema, operators are
 * checked against the closed vocabulary, values are coerced, spans are
 * validated, and anything invalid is dropped into warnings instead of the
 * filter list.
 */
final class FilterNormalizer
{
    public function __construct(
        private readonly ModelSchema $schema,
        private readonly int $maxFilters = 10,
        private readonly string $query = '',
    ) {}

    public function normalize(ParsedQuery $parsed): ParseResult
    {
        $filters = [];
        $warnings = $parsed->warnings;
        $seen = [];
        $length = strlen($this->query);

        foreach (array_values($parsed->filters) as $index => $raw) {
            if (! is_array($raw) || ! is_string($raw['field'] ?? null) || ! is_string($raw['operator'] ?? null)) {
                $warnings[] = 'Ignored a malformed filter.';

                continue;
            }
            if (count($filters) >= $this->maxFilters) {
                $warnings[] = "Too many filters; kept the first {$this->maxFilters}.";
                break;
            }

            $field = $this->schema->resolveFieldToken($raw['field']);

            if ($field === null) {
                $warnings[] = 'Ignored a filter for unknown field ['.$raw['field'].'].';

                continue;
            }

            $raw = $this->normalizeOperator($raw, $field);
            $operator = (string) ($raw['operator'] ?? '');

            if (! in_array($operator, Operator::forKind($field->kind), true)) {
                $warnings[] = "Operator [{$operator}] is not valid for {$field->name}.";

                continue;
            }

            $value = $this->coerceValue($field, $operator, $raw['value'] ?? null, $raw, $warnings);

            if ($value === null && ! in_array($operator, [Operator::IS_TRUE, Operator::IS_FALSE, Operator::IS_EMPTY, Operator::NOT_EMPTY], true)) {
                continue; // a warning was already pushed
            }

            $spans = $this->validateSpans($raw['spans'] ?? null, $length);

            // A chip set that comes back from the browser was validated when it
            // was made; its spans pointed into a query that is gone.
            if ($spans === null && $parsed->engine !== 'user' && in_array($raw['source'] ?? 'rules', ['system-one', 'ai'], true)) {
                // AI filters must tell us what they consumed or they get dropped.
                $warnings[] = "Filter on {$field->name} had no usable spans.";

                continue;
            }

            $dedupe = $field->name.'|'.$operator.'|'.json_encode($value).'|'.(int) ($raw['negated'] ?? false);

            if (isset($seen[$dedupe])) {
                continue;
            }

            $seen[$dedupe] = true;

            // Ids travel to the browser and back; keep them to a safe alphabet.
            $id = substr((string) preg_replace('/[^A-Za-z0-9:_.-]/', '', is_scalar($raw['id'] ?? null) ? (string) $raw['id'] : ''), 0, 80);
            $filter = Filter::fromArray([...$raw, 'id' => $id !== '' ? $id : $parsed->engine.':'.$index]);

            $filters[] = new Filter(
                id: $filter->id,
                field: $field->name,
                operator: $operator,
                value: $value,
                negated: (bool) ($raw['negated'] ?? false),
                label: $this->labelFor($field, [...$raw, 'operator' => $operator, 'value' => $value]),
                source: $filter->source,
                confidence: max(0.0, min(1.0, (float) ($raw['confidence'] ?? 1.0))),
                spans: $spans,
                unit: $raw['unit'] ?? $field->unit,
                suggested: (bool) ($raw['suggested'] ?? false),
                explicit: (bool) ($raw['explicit'] ?? false),
            );
        }

        $collection = new FilterCollection($filters);

        return new ParseResult(
            filters: $collection,
            leftover: $this->computeLeftover($parsed, $collection),
            sort: $this->normalizeSort($parsed->sort, $warnings),
            engine: $parsed->engine,
            warnings: $warnings,
            consumed: $parsed->consumed,
            keywords: $parsed->keywords,
        );
    }

    /**
     * Exact leftover text of a query: everything no span consumed. Shared
     * with the manager, which recomputes it after merging the AI parse with
     * explicit rules chips.
     */
    public static function leftoverFor(string $query, FilterCollection $filters, array $consumed = []): string
    {
        if ($query === '') {
            return '';
        }

        $spans = [...$filters->coveredSpans(), ...array_map(fn ($s) => [$s['start'], $s['end']], $consumed)];
        // Replacing bytes, rather than splitting characters, preserves all
        // unconsumed Unicode and punctuation. Spans are validated byte offsets.
        foreach ($spans as [$start, $end]) {
            if ($start >= 0 && $end > $start && $end <= strlen($query)) {
                $query = substr_replace($query, str_repeat(' ', $end - $start), $start, $end - $start);
            }
        }

        return Stopwords::strip(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query)));
    }

    /** equals + negated collapses to not_equals; other operators keep the flag. */
    private function normalizeOperator(array $raw, FieldSchema $field): array
    {
        $operator = (string) ($raw['operator'] ?? '');
        $negated = (bool) ($raw['negated'] ?? false);

        if ($negated && $operator === Operator::EQUALS && in_array(Operator::NOT_EQUALS, Operator::forKind($field->kind), true)) {
            $raw['operator'] = Operator::NOT_EQUALS;
            $raw['negated'] = false;
        }

        return $raw;
    }

    private function coerceValue(FieldSchema $field, string $operator, mixed $value, array $raw, array &$warnings): mixed
    {
        if (in_array($operator, [Operator::IS_TRUE, Operator::IS_FALSE, Operator::IS_EMPTY, Operator::NOT_EMPTY], true)) {
            return null;
        }

        return match ($field->kind) {
            'number' => $this->coerceNumber($field, $operator, $value, $raw, $warnings),
            'date', 'datetime' => $this->coerceDate($field, $operator, $value, $warnings),
            'boolean' => $this->coerceBoolean($value, $warnings, $field->name),
            'enum' => $this->coerceEnum($field, $operator, $value, $warnings),
            default => $this->coerceText($operator, $value),
        };
    }

    private function coerceNumber(FieldSchema $field, string $operator, mixed $value, array $raw, array &$warnings): mixed
    {
        $items = $operator === Operator::BETWEEN ? (is_array($value) ? $value : null) : [$value];

        if ($items === null || ($operator === Operator::BETWEEN && count($items) !== 2)) {
            $warnings[] = "{$field->name} filter needs two numbers for between.";

            return null;
        }

        $scaled = [];

        foreach ($items as $item) {
            if (is_bool($item) || ! is_scalar($item) || ! is_numeric($number = str_replace(',', '', (string) $item))) {
                $warnings[] = 'Could not use ['.(is_scalar($item) ? (string) $item : gettype($item))."] as a number for {$field->name}.";

                return null;
            }

            $number = $this->scaleNumber($field, (float) $number, $raw);
            if (! is_finite($number)) {
                $warnings[] = "Non-finite number for {$field->name}.";

                return null;
            }
            $scaled[] = $number;
        }

        if ($operator === Operator::BETWEEN && count($scaled) === 2) {
            [$min, $max] = $scaled;
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }

            return [$min, $max];
        }

        return $scaled[0];
    }

    private function scaleNumber(FieldSchema $field, float $number, array $raw): float
    {
        // An explicit unit from the engine ("8%") or a percent-named field
        // with no unit at all ("engagement above 8") both scale; a bare
        // number on an unconfigured field never does.
        $hasUnit = in_array($raw['unit'] ?? null, ['%', 'percent', 'percent_points'], true)
            || (($raw['unit'] ?? null) === null && $field->unit === 'percent');

        if ($hasUnit && $field->scale !== null) {
            return $number * $field->scale;
        }

        return $number;
    }

    private function coerceDate(FieldSchema $field, string $operator, mixed $value, array &$warnings): mixed
    {
        $items = $operator === Operator::BETWEEN ? (is_array($value) ? array_values($value) : null) : [$value];

        if ($items === null || ($operator === Operator::BETWEEN && count($items) !== 2)) {
            $warnings[] = "{$field->name} filter needs two dates for between.";

            return null;
        }

        $parsed = [];

        foreach ($items as $item) {
            $date = $this->parseDate($item);

            if ($date === null) {
                $warnings[] = 'Could not read ['.(is_scalar($item) ? (string) $item : gettype($item))."] as a date for {$field->name}.";

                return null;
            }

            $parsed[] = $date;
        }

        if ($operator === Operator::BETWEEN && count($parsed) === 2) {
            [$from, $to] = $parsed;

            if ($from->greaterThan($to)) {
                [$from, $to] = [$to, $from];
            }

            return [$from, $to];
        }

        return $parsed[0];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse(trim($value));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function coerceBoolean(mixed $value, array &$warnings, string $fieldName): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $map = ['yes' => true, 'true' => true, 'on' => true, '1' => true, 'no' => false, 'false' => false, 'off' => false, '0' => false];
            $lower = strtolower(trim($value));

            if (array_key_exists($lower, $map)) {
                return $map[$lower];
            }
        }

        $warnings[] = "Could not use value as a yes/no value for {$fieldName}.";

        return null;
    }

    private function coerceEnum(FieldSchema $field, string $operator, mixed $value, array &$warnings): mixed
    {
        $items = ($operator === Operator::IN || is_array($value))
            ? (is_array($value) ? array_values($value) : [$value])
            : [$value];

        $resolved = [];

        foreach ($items as $item) {
            $match = $this->resolveEnumValue($field, $item);

            if ($match === null) {
                $warnings[] = "Unknown value for {$field->name}.";

                return null;
            }

            $resolved[] = $match;
        }

        return $operator === Operator::IN ? $resolved : $resolved[0];
    }

    private function resolveEnumValue(FieldSchema $field, mixed $item): mixed
    {
        if ($field->values === null) {
            return is_scalar($item) ? $item : null;
        }

        foreach ($field->values as $value) {
            if ($item === $value) {
                return $value;
            }

            if (is_scalar($item) && is_scalar($value) && strtolower((string) $value) === strtolower(trim((string) $item))) {
                return $value;
            }
        }

        // "videos" is the plural of the value "video"; accept both directions.
        foreach ($field->values as $value) {
            if (! is_scalar($item) || ! is_scalar($value)) {
                continue;
            }

            $itemLower = strtolower(trim((string) $item));
            $valueLower = strtolower((string) $value);

            if (Str::plural($valueLower) === $itemLower || Str::singular($itemLower) === $valueLower) {
                return $value;
            }
        }

        foreach ($field->valueLabels as $value => $label) {
            if (is_scalar($item) && strcasecmp(trim((string) $item), $label) === 0) {
                return $value;
            }
        }

        if (is_scalar($item) && ($synonym = $field->synonymFor((string) $item)) !== null) {
            return $synonym;
        }

        return null;
    }

    private function coerceText(string $operator, mixed $value): mixed
    {
        if ($operator === Operator::IN) {
            if (! is_array($value) || $value === [] || count($value) > 25 || array_filter($value, fn ($v) => ! is_scalar($v))) {
                return null;
            }

            return array_values(array_map(fn ($v) => trim((string) $v), $value));
        }

        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @return array<int, array<string, int>>|null */
    private function validateSpans(mixed $spans, int $length): ?array
    {
        if (! is_array($spans)) {
            return null;
        }

        $valid = [];

        foreach ($spans as $span) {
            if (! is_array($span) || ! isset($span['start'], $span['end'])) {
                return null;
            }

            $start = (int) $span['start'];
            $end = (int) $span['end'];

            if ($start < 0 || $end <= $start || $end > $length
                || ! mb_check_encoding(substr($this->query, 0, $start), 'UTF-8')
                || ! mb_check_encoding(substr($this->query, $start, $end - $start), 'UTF-8')) {
                return null;
            }

            $valid[] = ['start' => $start, 'end' => $end];
        }

        return $valid === [] ? null : $valid;
    }

    private function labelFor(FieldSchema $field, array $raw): string
    {
        // A label that comes with a chip is never used: a chip from the browser
        // could carry text that does not match its operator and value.
        $label = $field->relation !== null && $field->label === null
            ? ucfirst(str_replace('_', ' ', $field->relation))
            : $field->displayLabel();

        if (is_scalar($raw['value'] ?? null) && isset($field->valueLabels[(string) $raw['value']])) {
            $raw['value'] = $field->valueLabels[(string) $raw['value']];
        }

        return Filter::fromArray($raw)->describe($label);
    }

    private function computeLeftover(ParsedQuery $parsed, FilterCollection $filters): string
    {
        if ($filters->isNotEmpty() && $filters->allHaveSpans()) {
            return self::leftoverFor($this->query, $filters, $parsed->consumed);
        }

        if ($parsed->leftover !== null) {
            return trim($parsed->leftover);
        }

        return trim($this->query);
    }

    private function normalizeSort(?array $sort, array &$warnings): ?array
    {
        if ($sort === null) {
            return null;
        }

        $field = $this->schema->resolveFieldToken((string) ($sort['field'] ?? ''));

        if ($field === null || ! in_array($field->name, $this->schema->orderable, true)) {
            $warnings[] = 'Ignored a sort on ['.($sort['field'] ?? '').'].';

            return null;
        }

        $direction = strtolower((string) ($sort['direction'] ?? 'desc'));

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        return [
            'field' => $field->name,
            'direction' => $direction,
            'label' => $sort['label'] ?? null,
        ];
    }
}
