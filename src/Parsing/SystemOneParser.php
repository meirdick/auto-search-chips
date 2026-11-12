<?php

namespace Meirdick\SearchChips\Parsing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Meirdick\SearchChips\Contracts\Parser;
use Meirdick\SearchChips\Data\FieldSchema;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\Operator;
use Meirdick\SearchChips\Data\ParsedQuery;
use Meirdick\SearchChips\Exceptions\EngineUnavailableException;
use Throwable;

/**
 * Cloudflare Clairic extraction. It receives typed fields derived from the
 * schema and returns one typed answer per field, with confidence, p_null,
 * and character spans. Clairic returns values, not operators: the operator
 * of each chip is read from the words around its span by the rules engine.
 *
 * When meirdick/laravel-cf-workersai is installed and its workers-ai provider
 * is configured, that package owns the request. Direct credentials remain
 * the fallback so this package has no hard dependency.
 *
 * https://developers.cloudflare.com/workers-ai/models/clairic/
 */
final class SystemOneParser implements Parser
{
    /** Words before a span that can carry its comparator ("no more than 10k"). */
    private const CONTEXT_WORDS = 4;

    /** A guessed word with p_null above this is a topic word ("rockets"), not a value. */
    private const VALUE_WORD_P_NULL = 0.9;

    /**
     * Words next to a value that belong to the filter, not to the keyword
     * search: "above 10k views" must leave no leftover.
     */
    /** The extra question that asks which words are the topic of the search. */
    public const KEYWORDS = 'search_keywords';

    public const KEYWORDS_INSTRUCTIONS = 'The subject words to look for in the text of a record. Leave out words that state a filter, a comparison, a date, a number, a person, or a sort order. Leave out filler words.';

    private const GLUE = [
        'above', 'over', 'under', 'below', 'more', 'less', 'fewer', 'greater', 'than', 'exceeding',
        'at', 'least', 'most', 'no', 'up', 'to', 'minimum', 'maximum', 'between', 'and',
        'before', 'after', 'since', 'until', 'last', 'past', 'this', 'on', 'in', 'from',
        'not', 'except', 'without', 'excluding', 'by', 'with', 'is', 'are', 'of',
        '>', '<', '>=', '<=', '=', ':',
    ];

    public function __construct(
        private readonly array $config,
        private readonly RulesParser $rules,
        private readonly ?WorkersAiExtractor $workersAi = null,
    ) {}

    public function available(): bool
    {
        return $this->extractor()->available()
            || (filled($this->config['account_id'] ?? null) && filled($this->config['api_token'] ?? null));
    }

    public function parse(string $query, ModelSchema $schema): ParsedQuery
    {
        $fields = $this->fields($schema);

        if ($fields === []) {
            return new ParsedQuery([], $query, null, 'system-one');
        }

        try {
            $answers = $this->extractor()->available() && ($this->config['transport'] ?? 'auto') !== 'direct'
                ? $this->extractor()->extract($query, $schema, $this->config)
                : $this->extractDirectly($query, $fields);
        } catch (Throwable $exception) {
            throw new EngineUnavailableException('Clairic request failed: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($answers)) {
            throw new EngineUnavailableException('Clairic returned no answers.');
        }

        return $this->assemble($this->matchValues($answers, $query, $schema), $query, $schema);
    }

    /**
     * Clairic copies words from the query, so "movies" arrives as the type
     * and names no allowed value. Ask the decision model which value each
     * such word means. A word it cannot place stays as it was.
     */
    private function matchValues(array $answers, string $query, ModelSchema $schema): array
    {
        $words = [];

        foreach ($answers as $name => $answer) {
            if (! is_array($answer) || ! is_string($name) || $name === self::KEYWORDS) {
                continue;
            }

            $field = $schema->field($name) ?? $schema->resolveFieldToken($name);
            $word = $answer['value'] ?? $answer['guess'] ?? null;

            if ($field === null || $field->values === null || ! is_string($word) || trim($word) === ''
                || ! (isset($answer['span']) || isset($answer['spans']))
                || (float) ($answer['confidence'] ?? 0) < (float) ($this->config['confidence_threshold'] ?? 0.6)
                || (($answer['value'] ?? null) === null && (float) ($answer['p_null'] ?? 1) > self::VALUE_WORD_P_NULL)
                || $field->resolveValue($word) !== null) {
                continue;
            }

            $words[$name] = ['field' => $field, 'word' => trim($word)];
        }

        if ($words === []) {
            return $answers;
        }

        foreach ((new ValueMatcher($this->config))->match($query, $words) as $name => $match) {
            $answers[$name] = [...$answers[$name], 'value' => $match['value'], 'confidence' => $match['confidence'], 'p_null' => 0.0];
        }

        return $answers;
    }

    private function extractor(): WorkersAiExtractor
    {
        return $this->workersAi ?? new WorkersAiPackageExtractor;
    }

    private function extractDirectly(string $query, array $fields): ?array
    {
        $response = Http::acceptJson()
            ->withToken((string) $this->config['api_token'])
            ->timeout((int) ($this->config['timeout'] ?? 8))
            ->post($this->endpoint(), [
                'model' => 'clairic', // required by the model's request schema
                'state' => $query,
                'reference_date' => Carbon::now()->toDateString(),
                'fields' => $fields,
            ])->throw()->json();

        // The REST API wraps the model output in `result`; a Worker binding
        // or a gateway may return it bare.
        $answers = $response['result']['answers'] ?? $response['answers'] ?? null;

        return is_array($answers) ? $answers : null;
    }

    private function endpoint(): string
    {
        if (filled($this->config['url'] ?? null)) {
            return (string) $this->config['url'];
        }

        $account = rawurlencode((string) $this->config['account_id']);
        $model = ltrim((string) ($this->config['model'] ?? '@cf/cloudflare/clairic'), '/');

        return "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/run/{$model}";
    }

    private function fields(ModelSchema $schema): array
    {
        $fields = [];

        foreach ($schema->fields as $field) {
            if (count($fields) >= (int) ($this->config['max_fields'] ?? 24)) {
                break;
            }

            // Free keyword text is the leftover's job. Asking the model for
            // it yields a "title contains <the whole query>" chip.
            if ($field->kind === 'text' && $field->relation === null && $field->values === null && in_array($field->name, $schema->searchable, true)) {
                continue;
            }

            $label = $field->displayLabel();

            $fields[$field->name] = match ($field->kind) {
                'number' => ['type' => 'number', 'instructions' => "Threshold or value for {$label}. Return the number only."],
                'date' => ['type' => 'date', 'instructions' => "Date constraint for {$label}."],
                'datetime' => ['type' => 'datetime', 'instructions' => "Date or time constraint for {$label}."],
                'boolean' => ['type' => 'text', 'instructions' => "The words that say whether {$label} is wanted or not wanted. Null if not mentioned."],
                default => [
                    'type' => 'text',
                    'instructions' => $field->values
                        ? "{$label}. Allowed values: ".implode(', ', array_map('strval', [...$field->values, ...array_keys($field->synonyms)])).'. Return the matching words from the query.'
                        : "Value for {$label}, copied from the query.",
                ],
            };
        }

        if ($schema->searchable !== [] && ! isset($fields[self::KEYWORDS])) {
            $fields[self::KEYWORDS] = ['type' => 'list', 'items' => 'text', 'instructions' => self::KEYWORDS_INSTRUCTIONS];
        }

        return $fields;
    }

    private function assemble(array $answers, string $query, ModelSchema $schema): ParsedQuery
    {
        $presence = (float) ($this->config['presence_threshold'] ?? 0.6);
        $minimum = (float) ($this->config['confidence_threshold'] ?? 0.6);
        $suggestBelow = (float) ($this->config['suggest_below'] ?? 0.85);

        // Pass 1: keep the confident answers and settle every span, so that
        // pass 2 can read each operator from its own stretch of the query.
        $kept = [];
        $claimed = [];

        foreach ($answers as $name => $answer) {
            if (! is_array($answer) || ! is_string($name)) {
                continue;
            }

            // Clairic nulls `value` when it is unsure the query states the
            // field, and keeps its best reading in `guess`. A confident
            // guess becomes a suggested chip: shown, not applied.
            $guessed = ($answer['value'] ?? null) === null;

            if ($guessed) {
                if (($answer['guess'] ?? null) === null
                    || ! isset($answer['span'])
                    || (float) ($answer['p_null'] ?? 1) > (float) ($this->config['guess_p_null'] ?? 0.65)
                    || (float) ($answer['confidence'] ?? 0) < $suggestBelow) {
                    continue;
                }

                $answer['value'] = $answer['guess'];
            }

            if ($name === self::KEYWORDS || ($field = $schema->field($name) ?? $schema->resolveFieldToken($name)) === null) {
                continue;
            }

            $score = (float) ($answer['confidence'] ?? 0);

            if ($score < $minimum || (! $guessed && (float) ($answer['p_null'] ?? 0) > 1 - $presence)) {
                continue;
            }

            $spans = $this->spans($answer, $query);
            $kept[] = ['field' => $field, 'answer' => $answer, 'score' => $score, 'spans' => $spans, 'guessed' => $guessed];
            $claimed = [...$claimed, ...($spans ?? [])];
        }

        foreach ($kept as $i => $item) {
            if ($item['spans'] === null && ($field = $item['field'])->kind !== 'text' && $field->kind !== 'enum') {
                $kept[$i]['spans'] = $this->inferredSpan($query, $item['answer']['value'], $claimed);
                $claimed = [...$claimed, ...($kept[$i]['spans'] ?? [])];
            }
        }

        // Pass 2: operators and filters.
        $filters = [];

        foreach ($kept as $item) {
            /** @var FieldSchema $field */
            $field = $item['field'];
            [$before, $context] = $this->context($query, $item['spans'], $claimed);
            [$operator, $value, $negated] = $this->interpret($field, $item['answer']['value'], $context, $before);

            if ($operator === null) {
                continue;
            }

            $filters[] = [
                'field' => $field->name,
                'operator' => $operator,
                'value' => $value,
                'negated' => $negated,
                'source' => 'system-one',
                'confidence' => $item['score'],
                'spans' => $this->absorb($query, $item['spans'], $field, $claimed),
                'suggested' => $item['guessed'] || $item['score'] < $suggestBelow,
            ];
        }

        $keywords = $answers[self::KEYWORDS]['value'] ?? null;

        return new ParsedQuery(
            $filters,
            null,
            null,
            'system-one',
            keywords: is_array($keywords) && $schema->field(self::KEYWORDS) === null
                ? array_values(array_filter($keywords, 'is_string'))
                : null,
        );
    }

    /** @return array{0: ?string, 1: mixed, 2: bool} operator, value, negated */
    private function interpret(FieldSchema $field, mixed $value, string $context, string $before): array
    {
        if ($field->kind === 'boolean') {
            $said = mb_strtolower(is_scalar($value) ? (string) $value : '').' '.mb_strtolower($context);
            $negative = (bool) preg_match('/\b(no|not|non|false|without|never|isn\'t|aren\'t|un\w+)\b/u', $said);

            return [$negative ? Operator::IS_FALSE : Operator::IS_TRUE, null, false];
        }

        if ($field->isNumeric() || $field->isDate()) {
            return [
                $this->rules->inferOperator($context, $field) ?? ($field->isDate() ? Operator::ON : Operator::EQUALS),
                $value,
                false,
            ];
        }

        // "not by Grace", "except podcasts", "without Ada"
        $negated = (bool) preg_match('/\b(not|except|without|excluding|non)(\s+(by|from|in|with|a|an|the|for|of))?\s*$/u', mb_strtolower($before));

        // A field with a fixed vocabulary matches exactly; free text matches
        // as a substring ("by Ada" finds "Ada Lovelace").
        return [$field->kind === 'enum' || $field->values !== null ? Operator::EQUALS : Operator::CONTAINS, $value, $negated];
    }

    /**
     * Widen a value span over the glue words before it and the field's own
     * name next to it, without crossing into another answer's span.
     *
     * @param  array<int, array{start: int, end: int}>|null  $spans
     * @param  array<int, array{start: int, end: int}>  $claimed
     * @return array<int, array{start: int, end: int}>|null
     */
    private function absorb(string $query, ?array $spans, FieldSchema $field, array $claimed): ?array
    {
        if ($spans === null) {
            return null;
        }

        $tokens = $field->matchTokens();
        $owned = fn (string $word): bool => in_array($word, self::GLUE, true)
            || in_array($word, $tokens, true)
            || in_array(rtrim($word, 's'), $tokens, true);

        $start = $spans[0]['start'];
        $end = end($spans)['end'];
        $floor = 0;
        $ceiling = strlen($query);

        foreach ($claimed as $taken) {
            if ($taken['end'] <= $start) {
                $floor = max($floor, $taken['end']);
            } elseif ($taken['start'] >= $end) {
                $ceiling = min($ceiling, $taken['start']);
            }
        }

        while (preg_match('/(\S+)\s*$/u', substr($query, $floor, $start - $floor), $m, PREG_OFFSET_CAPTURE) && $owned(mb_strtolower($m[1][0]))) {
            $start = $floor + $m[1][1];
        }

        if (preg_match('/^\s*(\S+)/u', substr($query, $end, $ceiling - $end), $m) && in_array(mb_strtolower(rtrim($m[1], '.,;')), [...$tokens, ...array_map(fn ($t) => $t.'s', $tokens)], true)) {
            $end += strlen($m[0]);
        }

        return [['start' => $start, 'end' => $end], ...array_slice($spans, 1, max(0, count($spans) - 2))];
    }

    /** @return array<int, array{start: int, end: int}>|null */
    private function spans(array $answer, string $query): ?array
    {
        $raw = isset($answer['span']) ? [$answer['span']] : ($answer['spans'] ?? []);
        $spans = [];

        foreach (is_array($raw) ? $raw : [] as $span) {
            if (! is_array($span) || count($span) !== 2 || ! is_int($span[0]) || ! is_int($span[1])) {
                continue;
            }

            // Clairic counts characters; the normalizer works in bytes.
            $start = strlen(mb_substr($query, 0, $span[0]));
            $end = strlen(mb_substr($query, 0, $span[1]));

            if ($end > $start && $end <= strlen($query)) {
                $spans[] = ['start' => $start, 'end' => $end];
            }
        }

        return $spans ?: null;
    }

    /**
     * Computed answers (numbers, dates) can arrive without a span. Find the
     * number in the query that this value came from: the one that expands
     * to the same value, else the first one no other answer claimed.
     *
     * @param  array<int, array{start: int, end: int}>  $claimed
     * @return array<int, array{start: int, end: int}>|null
     */
    private function inferredSpan(string $query, mixed $value, array $claimed): ?array
    {
        if (! preg_match_all('/\d[\d,.]*\s*(?:[kKmM]\b|%)?/u', $query, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $free = [];

        foreach ($matches[0] as [$text, $offset]) {
            $text = rtrim($text);
            $span = ['start' => $offset, 'end' => $offset + strlen($text)];

            foreach ($claimed as $taken) {
                if ($span['start'] < $taken['end'] && $taken['start'] < $span['end']) {
                    continue 2;
                }
            }

            if (is_numeric($value) && abs($this->expand($text) - (float) $value) < 0.000001) {
                return [$span];
            }

            $free[] = $span;
        }

        return $free === [] ? null : [$free[0]];
    }

    private function expand(string $text): float
    {
        $number = (float) str_replace(',', '', (string) preg_replace('/[^\d.,]/', '', $text));

        return match (true) {
            (bool) preg_match('/k$/i', $text) => $number * 1_000,
            (bool) preg_match('/m$/i', $text) => $number * 1_000_000,
            default => $number,
        };
    }

    /**
     * The stretch of the query that belongs to a span: the span itself plus
     * the few words before it, stopping at the previous answer's span.
     *
     * @param  array<int, array{start: int, end: int}>|null  $spans
     * @param  array<int, array{start: int, end: int}>  $claimed
     * @return array{0: string, 1: string} the words before the span, and those words plus the span
     */
    private function context(string $query, ?array $spans, array $claimed): array
    {
        if ($spans === null) {
            return ['', $query];
        }

        $start = $spans[0]['start'];
        $end = $spans[0]['end'];
        $floor = 0;

        foreach ($claimed as $taken) {
            if ($taken['end'] <= $start) {
                $floor = max($floor, $taken['end']);
            }
        }

        $before = preg_split('/\s+/u', trim(substr($query, $floor, $start - $floor)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $before = implode(' ', array_slice($before, -self::CONTEXT_WORDS));

        return [$before, trim($before.' '.substr($query, $start, $end - $start))];
    }
}
