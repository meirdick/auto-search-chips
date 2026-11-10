<?php

namespace Meirdick\SearchChips\Parsing;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\AnonymousAgent;
use Meirdick\SearchChips\Contracts\Parser;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\Operator;
use Meirdick\SearchChips\Data\ParsedQuery;
use Meirdick\SearchChips\Exceptions\EngineUnavailableException;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Generalist extraction through laravel/ai structured output. Provider and
 * model are explicit; laravel/ai defaults are never used silently.
 *
 * The model quotes the words each filter came from. Offsets are found here,
 * in code: chat models do not count characters reliably.
 */
final class ChatLlmParser implements Parser
{
    public const INSTRUCTIONS = <<<'TXT'
        You turn a search query into structured filters for a database table.
        Use only the fields and operators in the supplied schema.
        For each filter, `text` is the exact words of the query that the filter came from, copied character for character, including the comparator words ("above 10k").
        Use `value` for one value and `values` for a list or a between range (two items).
        Write dates as YYYY-MM-DD. Write numbers as plain digits.
        Words that describe no field go to `leftover`. Never invent a filter the query does not state.
        TXT;

    public function __construct(private readonly array $config) {}

    public function available(): bool
    {
        return class_exists(AnonymousAgent::class)
            && filled($this->config['provider'] ?? null)
            && filled($this->config['model'] ?? null);
    }

    public function parse(string $query, ModelSchema $schema): ParsedQuery
    {
        $fields = array_keys($schema->fields);

        if ($fields === []) {
            return new ParsedQuery([], $query, null, 'ai');
        }

        try {
            $response = agent(
                instructions: self::INSTRUCTIONS,
                schema: fn (JsonSchema $json) => [
                    'filters' => $json->array()->items($json->object([
                        'field' => $json->string()->enum($fields)->required(),
                        'operator' => $json->string()->enum(Operator::all())->required(),
                        'value' => $json->string()->nullable(),
                        'values' => $json->array()->items($json->string())->nullable(),
                        'negated' => $json->boolean()->required(),
                        'confidence' => $json->number()->min(0)->max(1)->required(),
                        'text' => $json->string()->required(),
                    ]))->required(),
                    'leftover' => $json->string()->required(),
                ],
            )->prompt(
                'Today: '.now()->toDateString()."\nQuery: {$query}\n\nSchema:\n".json_encode($this->describe($schema)),
                provider: $this->config['provider'],
                model: $this->config['model'],
                timeout: (int) ($this->config['timeout'] ?? 8),
            );
        } catch (Throwable $exception) {
            throw new EngineUnavailableException('Chat parser failed: '.$exception->getMessage(), previous: $exception);
        }

        $filters = [];
        $cursor = [];

        foreach ($response['filters'] ?? [] as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $values = $filter['values'] ?? null;
            $text = (string) ($filter['text'] ?? '');
            unset($filter['values'], $filter['text']);

            $filters[] = [
                ...$filter,
                'value' => is_array($values) && $values !== [] ? array_values($values) : ($filter['value'] ?? null),
                'spans' => $this->locate($query, $text, $cursor),
                'source' => 'ai',
            ];
        }

        return new ParsedQuery($filters, (string) ($response['leftover'] ?? ''), null, 'ai');
    }

    /**
     * Byte span of the quoted words in the query. A repeated quote takes the
     * next occurrence. Null when the model quoted words the query lacks;
     * the normalizer then drops that filter.
     *
     * @param  array<string, int>  $cursor
     * @return array<int, array{start: int, end: int}>|null
     */
    private function locate(string $query, string $text, array &$cursor): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $key = mb_strtolower($text);
        $start = stripos($query, $text, $cursor[$key] ?? 0);

        if ($start === false) {
            return null;
        }

        $cursor[$key] = $start + strlen($text);

        return [['start' => $start, 'end' => $start + strlen($text)]];
    }

    /** The schema as the model sees it: names, kinds, vocabulary. No row data. */
    private function describe(ModelSchema $schema): array
    {
        $fields = [];

        foreach ($schema->fields as $field) {
            $fields[$field->name] = array_filter([
                'kind' => $field->kind,
                'label' => $field->displayLabel(),
                'aliases' => $field->aliases,
                'values' => $field->values,
                'unit' => $field->unit,
                'operators' => Operator::forKind($field->kind),
            ], fn ($value) => $value !== null && $value !== []);
        }

        return ['fields' => $fields, 'sortable' => $schema->orderable];
    }
}
