<?php

namespace Meirdick\SearchChips\Parsing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Meirdick\SearchChips\Contracts\Parser;
use Meirdick\SearchChips\Data\FieldSchema;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\Operator;
use Meirdick\SearchChips\Data\ParsedQuery;

/**
 * The deterministic engine. Always available, no network, and precise:
 * every match records byte spans so "leftover" is exactly the text no rule
 * consumed. It is the last rung of the AI ladder and the operator oracle
 * for the AI engines (their spans get re-read with these comparators).
 */
final class RulesParser implements Parser
{
    private const NEGATION = 'not|except|non|without';

    private const STOPWORDS = [
        'a', 'an', 'the', 'this', 'that', 'these', 'those', 'all', 'any', 'some', 'other', 'another',
        'today', 'yesterday', 'now', 'me', 'i', 'my', 'mine', 'last', 'next', 'past',
        'is', 'are', 'was', 'were', 'with', 'and', 'or', 'but', 'than', 'then', 'there', 'here',
        'what', 'which', 'who', 'whose', 'from', 'by',
    ];

    /** Words that end a field-scoped value: the next constraint starts here. */
    private const BOUNDARY = [
        'above', 'over', 'under', 'below', 'between', 'greater', 'less', 'fewer', 'more', 'most',
        'at', 'least', 'no', 'up', 'to', 'by', 'from', 'with', 'since', 'after', 'before',
        'latest', 'oldest', 'top', 'best', 'not', 'yesterday', 'today',
    ];

    /** Words that start their own field-scoped segment ("author: Ada"). */
    private const SCOPED_BOUNDARY = '(?:by|from|with|and|or)';

    private const COMPARATOR_MAP = [
        '>=' => Operator::GTE, '<=' => Operator::LTE, '≥' => Operator::GTE, '≤' => Operator::LTE,
        'above' => Operator::GT, 'over' => Operator::GT, 'more than' => Operator::GT,
        'greater than' => Operator::GT, 'exceeding' => Operator::GT, '>' => Operator::GT,
        'at least' => Operator::GTE, 'atleast' => Operator::GTE, 'no less than' => Operator::GTE,
        'minimum' => Operator::GTE,
        'under' => Operator::LT, 'below' => Operator::LT, 'less than' => Operator::LT,
        'fewer than' => Operator::LT, '<' => Operator::LT,
        'at most' => Operator::LTE, 'up to' => Operator::LTE, 'no more than' => Operator::LTE,
        'maximum' => Operator::LTE,
    ];

    /** @var array<int, bool> byte coverage of the query */
    private array $covered = [];

    private string $query = '';

    private int $sequence = 0;

    public function parse(string $query, ModelSchema $schema): ParsedQuery
    {
        // "more then 100" is the most common typo in a comparison. Same
        // length, so every span still points at the user's text.
        $query = self::normalize($query);

        $this->query = $query;
        $this->covered = array_fill(0, max(1, strlen($query)), false);
        $this->sequence = 0;

        $filters = [];

        // Order matters: precise syntax first, vocabulary second, heuristics last.
        $this->matchQuotedPhrases($schema, $filters);
        $this->matchFieldScoped($schema, $filters);
        $this->matchValueVocabulary($schema, $filters);
        $this->matchRelationNames($schema, $filters);
        $this->matchNumericComparators($schema, $filters);
        $this->matchDateExpressions($schema, $filters);
        $this->matchBooleanVocabulary($schema, $filters);

        [$sort, $consumed] = $this->matchSortDirectives($schema);

        return new ParsedQuery(
            filters: $filters,
            leftover: null, // the normalizer derives it exactly from spans
            sort: $sort,
            engine: 'rules',
            consumed: $consumed,
        );
    }

    public function available(): bool
    {
        return true;
    }

    /** Fix "then" for "than" inside a comparison. The length does not change. */
    public static function normalize(string $text): string
    {
        return (string) preg_replace('/\b(more|less|greater|fewer|higher|lower|older|newer|later|earlier)(\s+)then\b/iu', '$1$2than', $text);
    }

    /**
     * Read an extracted span's operator for a field — the shared oracle for
     * the AI engines, whose values arrive without operators. "above 10k"
     * reads as gt, "since march" as after, bare text as contains.
     */
    public function inferOperator(string $spanText, FieldSchema $field): ?string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', trim(self::normalize($spanText))));

        if ($field->isNumeric()) {
            $keys = array_keys(self::COMPARATOR_MAP);
            usort($keys, fn ($a, $b) => strlen($b) <=> strlen($a));

            foreach ($keys as $key) {
                if (str_contains($text, $key)) {
                    return self::COMPARATOR_MAP[$key];
                }
            }

            return preg_match('/\d/u', $spanText) ? Operator::EQUALS : null;
        }

        if ($field->isDate()) {
            return match (true) {
                str_contains($text, 'before') || str_contains($text, 'until') => Operator::BEFORE,
                str_contains($text, 'since') || str_contains($text, 'after') => Operator::AFTER,
                str_contains($text, 'last') || str_contains($text, 'past') || str_contains($text, 'this') => Operator::AFTER,
                default => Operator::ON,
            };
        }

        if ($field->kind === 'boolean') {
            return Operator::IS_TRUE;
        }

        return null; // enums and text default per their own rules
    }

    // ---------------------------------------------------------------- Rules

    private function matchQuotedPhrases(ModelSchema $schema, array &$filters): void
    {
        if (! preg_match_all('/"([^"]{1,120})"/u', $this->query, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $field = $schema->searchable !== [] ? $schema->field($schema->searchable[0]) : null;

        foreach ($matches[0] as $i => $whole) {
            if ($field === null || $this->isCovered($whole[1])) {
                continue;
            }

            $this->consume($whole[1], strlen($whole[0]));

            $filters[] = $this->filter($field, Operator::CONTAINS, trim($matches[1][$i][0]), $this->lastSpans(), explicit: true);
        }
    }

    /** `type: video, article`, `author: Ada`, `views: 10k`, `published: 2024`. */
    private function matchFieldScoped(ModelSchema $schema, array &$filters): void
    {
        // A value is everything up to the next `field:` segment, a bare
        // list separator, or a word that starts its own constraint.
        if (! preg_match_all(
            '/(?P<field>[\w]+(?:\.[\w]+)?(?:\s[\w]+(?:\.[\w]+)?)?)\s*[:=]\s*(?P<value>(?:[^\s,;:]|,(?=\s)|\s(?!(?:[\w.]+\s*[:=]|'.self::SCOPED_BOUNDARY.'\b)))+)/u',
            $this->query,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return;
        }

        foreach ($matches['field'] as $i => $fieldMatch) {
            if ($this->isCovered($fieldMatch[1])) {
                continue;
            }

            $field = $schema->resolveFieldToken(trim($fieldMatch[0]));

            if ($field === null) {
                continue;
            }

            $raw = $this->scopedRawFilter($field, $matches['value'][$i][0], $matches['value'][$i][1]);

            if ($raw === null) {
                continue;
            }

            $spans = [...$this->consume($fieldMatch[1], strlen($fieldMatch[0])), ...$raw['value_spans']];

            unset($raw['value_spans']);

            $filters[] = [...$raw, 'spans' => $spans];
        }
    }

    /** @return array<string, mixed>|null raw filter + value_spans, or null to skip */
    private function scopedRawFilter(FieldSchema $field, string $rawValue, int $valueStart): ?array
    {
        $negated = false;
        $negationSpans = [];

        if (preg_match('/^(not|except)\s+/iu', $rawValue, $m)) {
            $negated = true;
            $negationSpans = $this->consume($valueStart, strlen($m[0])); // the negation word is part of the filter
            $valueStart += strlen($m[0]);
            $rawValue = ltrim(substr($rawValue, strlen($m[0])));
        }

        $quoted = false;

        if (preg_match('/^"(.*)"$/u', $rawValue, $m)) {
            $quoted = true;
            $rawValue = $m[1];
        }

        // Keep only the words that belong to this value; boundary words
        // belong to the next constraint.
        $kept = [];

        foreach (preg_split('/\s+/u', trim($rawValue)) ?: [] as $word) {
            if ($kept !== [] && $this->isBoundaryWord($word)) {
                break;
            }

            $kept[] = $word;
        }

        $text = trim(implode(' ', $kept), " \t\n\r\0\x0B,.;:!");

        if ($text === '') {
            return null;
        }

        $valueSpans = [...$negationSpans, ...$this->consume($valueStart, strlen($text))];

        return match ($field->kind) {
            'enum' => $this->scopedEnum($field, $text, $negated, $valueSpans),
            'number' => $this->scopedNumber($field, $text, $negated, $valueSpans),
            'boolean' => $this->scopedBoolean($field, $text, $negated, $valueSpans),
            'date', 'datetime' => $this->scopedDate($field, $text, $negated, $valueSpans),
            default => $this->scopedText($field, $text, $negated, $quoted, $valueSpans),
        };
    }

    private function scopedEnum(FieldSchema $field, string $text, bool $negated, array $valueSpans): array
    {
        $values = preg_split('/\s*,\s*|\s+or\s+/iu', $text) ?: [];

        return $this->filter(
            $field,
            count($values) > 1 ? Operator::IN : Operator::EQUALS,
            count($values) > 1 ? $values : $values[0],
            $valueSpans,
            negated: $negated,
            explicit: true,
        ) + ['value_spans' => $valueSpans];
    }

    private function scopedNumber(FieldSchema $field, string $text, bool $negated, array $valueSpans): array
    {
        $operator = Operator::EQUALS;

        // `views >= 10k`, `views: above 100`
        foreach (self::COMPARATOR_MAP as $word => $op) {
            if (preg_match('/^'.preg_quote($word, '/').'\s*/iu', $text, $m)) {
                $operator = $op;
                $text = substr($text, strlen($m[0]));

                break;
            }
        }

        $value = $this->expandNumber($text, $unit);

        return $this->filter($field, $operator, $value, $valueSpans, negated: $negated, unit: $unit, explicit: true)
            + ['value_spans' => $valueSpans];
    }

    private function scopedBoolean(FieldSchema $field, string $text, bool $negated, array $valueSpans): array
    {
        $truthy = ['yes', 'true', '1', 'on', 'y'];
        $isTrue = in_array(strtolower($text), $truthy, true) ? ! $negated : $negated;

        return $this->filter($field, $isTrue ? Operator::IS_TRUE : Operator::IS_FALSE, null, $valueSpans, explicit: true)
            + ['value_spans' => $valueSpans];
    }

    private function scopedDate(FieldSchema $field, string $text, bool $negated, array $valueSpans): array
    {
        if (preg_match('/^(19|20)\d{2}$/u', $text)) {
            $value = $text; // a year like "2024", not the time "20:24"
        } else {
            try {
                Carbon::parse($text);
                $value = $text;
            } catch (\Throwable) {
                return $this->scopedText($field, $text, $negated, false, $valueSpans);
            }
        }

        return $this->filter($field, Operator::ON, $value, $valueSpans, negated: $negated, explicit: true)
            + ['value_spans' => $valueSpans];
    }

    private function scopedText(FieldSchema $field, string $text, bool $negated, bool $quoted, array $valueSpans): array
    {
        $operator = $field->relation !== null || $quoted || str_contains($text, ' ')
            ? Operator::CONTAINS
            : Operator::STARTS_WITH;

        return $this->filter($field, $operator, $text, $valueSpans, negated: $negated, explicit: true)
            + ['value_spans' => $valueSpans];
    }

    /** Enum values ("videos"), their labels, and plurals — the "Type · Video" chip. */
    private function matchValueVocabulary(ModelSchema $schema, array &$filters): void
    {
        foreach ([...$schema->enumFields(), ...array_filter($schema->fields, fn ($field) => $field->valueSource === 'sampled')] as $field) {
            if ($field->values === null) {
                continue;
            }

            $candidates = [];

            foreach ($field->values as $value) {
                if (is_scalar($value)) {
                    $candidates[] = (string) $value;
                }
            }

            foreach ($field->valueLabels as $label) {
                $candidates[] = $label;
            }

            foreach (array_keys($field->synonyms) as $synonym) {
                $candidates[] = (string) $synonym;
            }

            $candidates = array_values(array_unique(array_filter($candidates, fn ($c) => $c !== '')));

            // Longest first so "video production" beats "video".
            usort($candidates, fn ($a, $b) => strlen($b) <=> strlen($a));

            foreach ($candidates as $candidate) {
                foreach ([$candidate, Str::plural($candidate)] as $form) {
                    if ($this->matchValueWord($form, $field, $filters)) {
                        break 2;
                    }
                }
            }
        }
    }

    private function matchValueWord(string $word, FieldSchema $field, array &$filters): bool
    {
        if (! preg_match('/\b'.preg_quote($word, '/').'\b/iu', $this->query, $match, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $start = $match[0][1];

        if ($this->isCovered($start)) {
            return false;
        }

        $spans = $this->consume($start, strlen($match[0][0]));
        $negated = false;

        if (preg_match('/\b(?:'.self::NEGATION.')\s+$/iu', substr($this->query, max(0, $start - 14), min(14, $start)), $neg)) {
            $negated = true;
            $spans = [...$spans, ...$this->consume($start - strlen($neg[0]), strlen($neg[0]))];
        }

        $canonical = collect($field->values)->first(fn ($value) => strcasecmp((string) $value, $word) === 0)
            ?? collect($field->values)->first(fn ($value) => strcasecmp(Str::plural((string) $value), $word) === 0)
            ?? $field->synonymFor($word)
            ?? $word;
        $filters[] = $this->filter($field, Operator::EQUALS, $canonical, $spans, negated: $negated);

        return true;
    }

    /** "by Ada", "from Acme" — the "Author · Ada" chip. */
    private function matchRelationNames(ModelSchema $schema, array &$filters): void
    {
        $nameable = array_filter(
            $schema->relations,
            fn ($r) => $r->nameColumn !== null && isset($schema->fields[$r->name.'.'.$r->nameColumn])
        );

        if (count($nameable) !== 1) {
            return; // ambiguous: which relation? field-scoped syntax still works
        }

        $relation = reset($nameable);

        if (! preg_match_all('/\b(?:by|from)\s+(?P<name>[\p{L}][\p{L}\p{N}\'’.‿-]{0,40}(?:\s+[\p{L}][\p{L}\p{N}\'’.‿-]{0,40})?)/iu', $this->query, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $field = $schema->fields[$relation->name.'.'.$relation->nameColumn];

        foreach ($matches['name'] as $i => $nameMatch) {
            $byStart = $matches[0][$i][1];

            if ($this->isCovered($byStart)) {
                continue;
            }

            $words = preg_split('/\s+/u', trim($nameMatch[0])) ?: [];

            // A trailing word that is really the next constraint isn't part of the name.
            while (count($words) > 1 && ($this->isStopword(end($words)) || $this->isBoundaryWord(end($words)) || $schema->resolveFieldToken(end($words)))) {
                array_pop($words);
            }

            $name = implode(' ', $words);

            if ($name === '' || $this->isStopword($words[0]) || $schema->resolveFieldToken($words[0])) {
                continue;
            }

            $prepositionEnd = $nameMatch[1];
            $spans = [...$this->consume($byStart, $prepositionEnd - $byStart), ...$this->consume($nameMatch[1], strlen($name))];
            $negated = false;

            if (preg_match('/\b(?:'.self::NEGATION.')\s+$/iu', substr($this->query, max(0, $byStart - 14), min(14, $byStart)), $neg)) {
                $negated = true;
                $spans = [...$spans, ...$this->consume($byStart - strlen($neg[0]), strlen($neg[0]))];
            }

            $filters[] = $this->filter($field, Operator::CONTAINS, $name, $spans, negated: $negated);
        }
    }

    /** "above 10k views", ">= 8%", "between 1k and 5k" — the "Views · > 10k" chip. */
    private function matchNumericComparators(ModelSchema $schema, array &$filters): void
    {
        $pattern = '/(?P<cmp>at\s+least|atleast|no\s+less\s+than|minimum|above|over|more\s+than|greater\s+than|exceeding|at\s+most|up\s+to|no\s+more\s+than|maximum|under|below|less\s+than|fewer\s+than|>=|<=|≥|≤|>|<)\s*(?P<cur>[$€£])?\s*(?P<num>\d[\d,]*(?:\.\d+)?)\s*(?P<suffix>%|k|m|mil|million|bn|billion)?\b/iu';

        if (preg_match_all($pattern, $this->query, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches['num'] as $i => $numMatch) {
                if ($this->isCovered($numMatch[1])) {
                    continue;
                }

                $cmp = mb_strtolower(preg_replace('/\s+/u', ' ', $matches['cmp'][$i][0]));
                $operator = self::COMPARATOR_MAP[$cmp] ?? null;

                if ($operator === null) {
                    continue;
                }

                $resolution = $this->resolveNumericField($schema, $this->endOf($matches, $i), $matches['cmp'][$i][1]);

                if ($resolution === null) {
                    continue;
                }

                [$field, $fieldSpans] = $resolution;

                $value = $this->expandNumber($matches['num'][$i][0].($matches['suffix'][$i][0] ?: ''), $unit);

                if (! empty($matches['cur'][$i][0])) {
                    $unit = $matches['cur'][$i][0];
                }

                $spans = [...$this->consume($matches['cmp'][$i][1], strlen($matches[0][$i][0])), ...$fieldSpans];

                $filters[] = $this->filter($field, $operator, $value, $spans, unit: $unit ?? null);
            }
        }

        // "between 1k and 5k views"
        if (preg_match_all('/\bbetween\s+(?P<a>\d[\d,]*(?:\.\d+)?)\s*(?P<asfx>%|k|m|mil|million|bn|billion)?\s*(?:and|to|-|–|—)\s+(?P<b>\d[\d,]*(?:\.\d+)?)\s*(?P<bsfx>%|k|m|mil|million|bn|billion)?\b/iu', $this->query, $between, PREG_OFFSET_CAPTURE)) {
            foreach ($between['a'] as $i => $aMatch) {
                if ($this->isCovered($aMatch[1])) {
                    continue;
                }

                $resolution = $this->resolveNumericField($schema, $this->endOf($between, $i, 'bsfx', 'b'), $between[0][$i][1]);

                if ($resolution === null) {
                    continue;
                }

                [$field, $fieldSpans] = $resolution;

                $min = $this->expandNumber($between['a'][$i][0].($between['asfx'][$i][0] ?: ''), $unitA);
                $max = $this->expandNumber($between['b'][$i][0].($between['bsfx'][$i][0] ?: ''), $unitB);

                $spans = [...$this->consume($between[0][$i][1], strlen($between[0][$i][0])), ...$fieldSpans];

                $filters[] = $this->filter($field, Operator::BETWEEN, [$min, $max], $spans, unit: $unitA ?? $unitB ?? null);
            }
        }
    }

    /**
     * Which numeric field does this comparison belong to? A word right after
     * the number, a word right before the comparator, the single numeric
     * field, or nothing (ambiguous).
     *
     * @return array{0: FieldSchema, 1: array<int, array<string, int>>}|null
     */
    private function resolveNumericField(ModelSchema $schema, int $afterOffset, int $beforeOffset): ?array
    {
        $numerics = $schema->numericFields();

        if ($numerics === []) {
            return null;
        }

        foreach ([2, 1] as $words) {
            $text = $this->window($afterOffset, $words);

            if ($text !== '' && ($field = $this->resolveNumericToken($schema, $text)) !== null) {
                return [$field, $this->consumeWindow($afterOffset, $words)];
            }
        }

        foreach ([2, 1] as $words) {
            $text = $this->windowBefore($beforeOffset, $words);

            if ($text !== '' && ($field = $this->resolveNumericToken($schema, $text)) !== null) {
                return [$field, $this->consumeWindowBefore($beforeOffset, $words)];
            }
        }

        if (count($numerics) === 1) {
            return [reset($numerics), []];
        }

        return null; // ambiguous: "above 5" with three numeric fields says nothing
    }

    private function resolveNumericToken(ModelSchema $schema, string $text): ?FieldSchema
    {
        $field = $schema->resolveFieldToken($text);

        return $field !== null && $field->isNumeric() ? $field : null;
    }

    /**
     * Byte offset just past match group 0's optional $suffix group — the
     * field word follows the suffix in "above 10k views", not the digits.
     */
    private function endOf(array $matches, int $i, string $suffixGroup = 'suffix', string $numberGroup = 'num'): int
    {
        $suffix = $matches[$suffixGroup][$i] ?? null;

        if ($suffix === null || $suffix[1] < 0) {
            return $matches[$numberGroup][$i][1] + strlen($matches[$numberGroup][$i][0]);
        }

        return $suffix[1] + strlen($suffix[0]);
    }

    /** "last 30 days", "this month", "yesterday", "in 2024", "since monday". */
    private function matchDateExpressions(ModelSchema $schema, array &$filters): void
    {
        /** @var array<string, \Closure(array<mixed>): array{0: Carbon, 1: Carbon, 2: string}> $expressions */
        $expressions = [
            '/\b(?:last|past)\s+(?P<n>\d{1,3})\s+(?P<unit>days?|weeks?|months?|years?)\b/iu' => fn (array $m) => [
                Carbon::now()->sub((int) $m['n'][0][0], rtrim($m['unit'][0][0], 's').'s')->startOfDay(),
                Carbon::now(),
                Operator::BETWEEN,
            ],
            '/\b(?:this|current)\s+(?P<unit>week|month|quarter|year)\b/iu' => fn (array $m) => [
                Carbon::now()->{'startOf'.ucfirst($m['unit'][0][0])}(),
                Carbon::now(),
                Operator::BETWEEN,
            ],
            // "last month" is the previous calendar month, not the last 30 days.
            '/\b(?:last|previous)\s+(?P<unit>week|month|quarter|year)\b/iu' => fn (array $m) => [
                Carbon::now()->sub(1, $m['unit'][0][0])->{'startOf'.ucfirst(strtolower($m['unit'][0][0]))}(),
                Carbon::now()->sub(1, $m['unit'][0][0])->{'endOf'.ucfirst(strtolower($m['unit'][0][0]))}(),
                Operator::BETWEEN,
            ],
            '/\byesterday\b/iu' => fn () => [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay(), Operator::ON],
            '/\btoday\b/iu' => fn () => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay(), Operator::ON],
            '/\bin\s+(?P<year>20\d{2})\b/iu' => fn (array $m) => [
                Carbon::create((int) $m['year'][0][0], 1, 1)->startOfDay(),
                Carbon::create((int) $m['year'][0][0], 1, 1)->endOfYear(),
                Operator::BETWEEN,
            ],
        ];

        foreach ($expressions as $pattern => $builder) {
            if (! preg_match_all($pattern, $this->query, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $i => $whole) {
                if ($this->isCovered($whole[1])) {
                    continue;
                }

                [$from, $to, $operator] = $builder($matches);

                $field = $this->resolveDateField($schema, $whole[1]);

                if ($field === null) {
                    continue;
                }

                $spans = $this->consume($whole[1], strlen($whole[0]));
                $value = $operator === Operator::BETWEEN ? [$from, $to] : $from;

                // "after last week", "since last month", "before this year":
                // the word in front turns the period into one bound.
                if (preg_match('/\b(after|since|before|until)\s+$/iu', substr($this->query, 0, $whole[1]), $direction, PREG_OFFSET_CAPTURE)) {
                    $word = strtolower($direction[1][0]);

                    [$operator, $value] = match ($word) {
                        'after' => [Operator::AFTER, $to],
                        'since' => [Operator::AFTER, $from],
                        default => [Operator::BEFORE, $from],
                    };

                    $spans = [...$this->consume($direction[0][1], strlen($direction[0][0])), ...$spans];
                }

                $filters[] = $this->filter($field, $operator, $value, $spans);
            }
        }

        // "since monday", "after 2024-05-01", "before march"
        if (preg_match_all('/\b(?P<dir>since|after|before)\s+(?P<date>[\p{L}\p{N}\/\-,.\s]{2,24}?)(?=\s+(?:by|from|above|over|under|below|with|between|less|more|at|in|on|about|for|and|that|which|regarding)\b|\s*[,;.]|$)/iu', $this->query, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches['date'] as $i => $dateMatch) {
                if ($this->isCovered($dateMatch[1])) {
                    continue;
                }

                $direction = strtolower($matches['dir'][$i][0]);

                try {
                    // A bare year is a year, not the time 20:26 today.
                    $date = preg_match('/^(19|20)\d{2}$/', trim($dateMatch[0]))
                        ? ($direction === 'after'
                            ? Carbon::create((int) trim($dateMatch[0]))->endOfYear()
                            : Carbon::create((int) trim($dateMatch[0]))->startOfYear())
                        : Carbon::parse(trim($dateMatch[0]));

                    // A bare month is the whole month, not today's day in it.
                    if (preg_match('/^\p{L}+(\s+(19|20)\d{2})?$/u', trim($dateMatch[0])) && preg_match('/^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i', trim($dateMatch[0]))) {
                        $date = $direction === 'after' ? $date->endOfMonth() : $date->startOfMonth();
                    }
                } catch (\Throwable) {
                    continue;
                }

                $field = $this->resolveDateField($schema, $matches[0][$i][1]);

                if ($field === null) {
                    continue;
                }

                $this->consume($matches[0][$i][1], strlen($matches[0][$i][0]));

                $operator = $direction === 'before' ? Operator::BEFORE : Operator::AFTER;

                $filters[] = $this->filter($field, $operator, $date, $this->lastSpans());
            }
        }
    }

    /** "featured", "not featured" on a boolean field. */
    private function matchBooleanVocabulary(ModelSchema $schema, array &$filters): void
    {
        foreach ($schema->booleanFields() as $field) {
            $words = [$field->name];

            foreach (['is_', 'has_', 'can_', 'with_'] as $prefix) {
                if (str_starts_with($field->name, $prefix)) {
                    $words[] = substr($field->name, strlen($prefix));
                }
            }

            foreach (array_unique($words) as $word) {
                if (! preg_match('/\b'.preg_quote($word, '/').'\b/iu', $this->query, $match, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $start = $match[0][1];

                if ($this->isCovered($start)) {
                    continue;
                }

                $spans = $this->consume($start, strlen($match[0][0]));
                $negated = false;

                if (preg_match('/\b(?:'.self::NEGATION.')\s+$/iu', substr($this->query, max(0, $start - 14), min(14, $start)), $neg)) {
                    $negated = true;
                    $spans = [...$spans, ...$this->consume($start - strlen($neg[0]), strlen($neg[0]))];
                }

                $filters[] = $this->filter($field, $negated ? Operator::IS_FALSE : Operator::IS_TRUE, null, $spans);

                break;
            }
        }
    }

    /** "latest", "oldest", "top views" — sort words are consumed but are not chips. */
    private function matchSortDirectives(ModelSchema $schema): array
    {
        $sort = null;
        $consumed = [];

        $latest = $this->sortField($schema);

        if ($latest !== null && preg_match('/\b(latest|newest|most recent)\b/iu', $this->query, $match, PREG_OFFSET_CAPTURE) && ! $this->isCovered($match[0][1])) {
            $consumed = $this->consume($match[0][1], strlen($match[0][0]));
            $sort = ['field' => $latest->name, 'direction' => 'desc', 'label' => 'Latest'];
        } elseif ($latest !== null && preg_match('/\boldest\b/iu', $this->query, $match, PREG_OFFSET_CAPTURE) && ! $this->isCovered($match[0][1])) {
            $consumed = $this->consume($match[0][1], strlen($match[0][0]));
            $sort = ['field' => $latest->name, 'direction' => 'asc', 'label' => 'Oldest'];
        } elseif (preg_match('/\b(top|best|most|highest)\b(?:\s+(?P<field>[\p{L}\p{N}]+(?:\s+[\p{L}\p{N}]+)?))?/iu', $this->query, $match, PREG_OFFSET_CAPTURE) && ! $this->isCovered($match[0][1])) {
            $field = null;

            if (! empty($match['field'][0])) {
                // "most viewed tips": the field is two words ("view count") or only the first.
                $words = $match['field'][0];
                $first = (string) strtok($words, ' ');

                if (($field = $this->resolveNumericToken($schema, $words)) !== null) {
                    $consumed = $this->consume($match[0][1], strlen($match[0][0]));
                } elseif (($field = $this->resolveNumericToken($schema, $first)) !== null) {
                    $consumed = $this->consume($match[0][1], $match['field'][1] + strlen($first) - $match[0][1]);
                }
            }

            if ($field === null) {
                $numerics = $schema->numericFields();
                $field = count($numerics) === 1 ? reset($numerics) : null;

                if ($field !== null) {
                    $consumed = $this->consume($match[0][1], strlen($match[1][0]));
                }
            }

            if ($field !== null) {
                $sort = ['field' => $field->name, 'direction' => 'desc', 'label' => 'Top '.$field->displayLabel()];
            }
        }

        return [$sort, $consumed];
    }

    private function sortField(ModelSchema $schema): ?FieldSchema
    {
        if ($field = $schema->defaultDateField()) {
            return $field;
        }

        foreach ($schema->orderable as $name) {
            if (($field = $schema->field($name)) && $field->isDate()) {
                return $field;
            }
        }

        return null;
    }

    // ------------------------------------------------------------- Utilities

    private function resolveDateField(ModelSchema $schema, int $expressionStart): ?FieldSchema
    {
        $word = $this->windowBefore($expressionStart, 1);

        if ($word !== '' && ($field = $schema->resolveFieldToken($word)) && $field->isDate()) {
            $this->consumeWindowBefore($expressionStart, 1);

            return $field;
        }

        return $schema->defaultDateField();
    }

    /**
     * Expand "10k" -> 10000, "1.5m" -> 1500000; "8%" keeps 8 and reports a
     * percent unit for the normalizer to scale.
     */
    private function expandNumber(string $text, ?string &$unit = null): float
    {
        $unit = null;

        if (! preg_match('/^(?P<num>[\d,]*(?:\.\d+)?)\s*(?P<suffix>.*)$/u', trim($text), $m)) {
            return (float) $text;
        }

        $number = (float) str_replace(',', '', $m['num'] ?: '0');
        $suffix = strtolower(trim($m['suffix']));

        // The unit travels with the filter so the normalizer can scale it.
        if ($suffix === '%' || in_array($suffix, ['$', '€', '£'], true)) {
            $unit = $suffix;

            return $number;
        }

        return match (true) {
            in_array($suffix, ['k', 'thousand']) => $number * 1_000,
            in_array($suffix, ['m', 'mil', 'million']) => $number * 1_000_000,
            in_array($suffix, ['bn', 'billion', 'b']) => $number * 1_000_000_000,
            default => $number,
        };
    }

    private function isStopword(string $word): bool
    {
        return in_array(mb_strtolower(trim($word)), self::STOPWORDS, true);
    }

    private function isBoundaryWord(string $word): bool
    {
        return in_array(mb_strtolower(trim($word)), self::BOUNDARY, true);
    }

    private function isCovered(int $offset): bool
    {
        return $this->covered[$offset] ?? false;
    }

    /** @var array<int, array<string, int>> */
    private array $lastSpansCache = [];

    /** @return array<int, array<string, int>> */
    private function consume(int $start, int $length): array
    {
        $end = min($start + $length, strlen($this->query));

        for ($i = max(0, $start); $i < $end; $i++) {
            $this->covered[$i] = true;
        }

        return $this->lastSpansCache = [['start' => max(0, $start), 'end' => $end]];
    }

    private function lastSpans(): array
    {
        return $this->lastSpansCache;
    }

    /** Up to $words words starting at byte $offset. */
    private function window(int $offset, int $words): string
    {
        if ($offset >= strlen($this->query)) {
            return '';
        }

        if (! preg_match('/^\s*((?:[\p{L}\p{N}]+\s*){1,'.$words.'})/iu', substr($this->query, $offset), $m)) {
            return '';
        }

        return trim($m[1]);
    }

    private function windowBefore(int $offset, int $words): string
    {
        if (! preg_match('/((?:\s*[\p{L}\p{N}]+){1,'.$words.'})\s*$/iu', substr($this->query, 0, $offset), $m)) {
            return '';
        }

        return trim($m[1]);
    }

    /** @return array<int, array<string, int>> */
    private function consumeWindow(int $offset, int $words): array
    {
        $text = $this->window($offset, $words);

        if ($text === '') {
            return [];
        }

        $start = strpos($this->query, $text, $offset);

        return $start === false ? [] : $this->consume($start, strlen($text));
    }

    /** @return array<int, array<string, int>> */
    private function consumeWindowBefore(int $offset, int $words): array
    {
        $text = $this->windowBefore($offset, $words);

        if ($text === '') {
            return [];
        }

        $start = strrpos(substr($this->query, 0, $offset), $text);

        return $start === false ? [] : $this->consume($start, strlen($text));
    }

    /** @return array<string, mixed> */
    private function filter(
        FieldSchema $field,
        string $operator,
        mixed $value,
        array $spans,
        bool $negated = false,
        ?string $unit = null,
        bool $explicit = false,
    ): array {
        return [
            'id' => 'r:'.(++$this->sequence),
            'field' => $field->name,
            'operator' => $operator,
            'value' => $value,
            'negated' => $negated,
            'source' => 'rules',
            'confidence' => 1.0,
            'spans' => $spans,
            'unit' => $unit,
            'explicit' => $explicit,
        ];
    }
}
