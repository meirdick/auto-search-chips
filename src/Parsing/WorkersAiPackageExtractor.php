<?php

namespace Meirdick\SearchChips\Parsing;

use Illuminate\Support\Carbon;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\WorkersAi\Extraction;
use Meirdick\WorkersAi\Extraction\DateField;
use Meirdick\WorkersAi\Extraction\ListField;
use Meirdick\WorkersAi\Extraction\Number;
use Meirdick\WorkersAi\Extraction\Text;
use Meirdick\WorkersAi\WorkersAiKey;

/**
 * Sends the Clairic request through meirdick/laravel-cf-workersai when that
 * package is installed and one of its providers is configured. The package
 * brings retries, failover and AI Gateway routing. It is never required.
 */
final class WorkersAiPackageExtractor implements WorkersAiExtractor
{
    public function available(): bool
    {
        return class_exists(Extraction::class) && $this->provider() !== null;
    }

    public function extract(string $query, ModelSchema $schema, array $config): array
    {
        $pending = Extraction::of($query)
            ->fields($this->fields($schema, (int) ($config['max_fields'] ?? 24)))
            ->referenceDate(Carbon::now()->toDateString())
            ->provider((string) ($config['provider'] ?? $this->provider()))
            ->timeout((int) ($config['timeout'] ?? 8));

        if (filled($config['model'] ?? null)) {
            $pending->model((string) $config['model']);
        }

        $answers = [];

        foreach ($pending->extract()->collect() as $name => $answer) {
            $answers[$name] = array_filter([
                'type' => $answer->type(),
                'value' => $answer->value(),
                'guess' => $answer->guess(),
                'confidence' => $answer->confidence(),
                'p_null' => $answer->pNull(),
                'span' => $answer->span(),
                'spans' => $answer->span() === null ? $answer->spans() : null,
            ], fn ($value) => $value !== null);
        }

        return $answers;
    }

    /** The name of the first laravel/ai provider that uses this package's driver. */
    private function provider(): ?string
    {
        if (filled($name = config('search-chips.ai.cloudflare.provider'))) {
            return (string) $name;
        }

        foreach ((array) config('ai.providers', []) as $name => $provider) {
            if (in_array($provider['driver'] ?? null, [WorkersAiKey::PRIMARY, WorkersAiKey::ALIAS], true) && filled($provider['key'] ?? null)) {
                return (string) $name;
            }
        }

        return null;
    }

    /** The same field questions as the direct transport, as the package's field objects. */
    private function fields(ModelSchema $schema, int $max): array
    {
        $fields = [];

        foreach ($schema->fields as $field) {
            if (count($fields) >= $max) {
                break;
            }

            if ($field->kind === 'text' && $field->relation === null && $field->values === null && in_array($field->name, $schema->searchable, true)) {
                continue;
            }

            $label = $field->displayLabel();

            $fields[$field->name] = match ($field->kind) {
                'number' => new Number(instructions: "Threshold or value for {$label}. Return the number only."),
                'date' => DateField::date("Date constraint for {$label}."),
                'datetime' => DateField::dateTime("Date or time constraint for {$label}."),
                'boolean' => new Text(instructions: "The words that say whether {$label} is wanted or not wanted. Null if not mentioned."),
                default => new Text(instructions: $field->values
                    ? "{$label}. Allowed values: ".implode(', ', array_map('strval', [...$field->values, ...array_keys($field->synonyms)])).'. Return the matching words from the query.'
                    : "Value for {$label}, copied from the query."),
            };
        }

        if ($schema->searchable !== [] && ! isset($fields[SystemOneParser::KEYWORDS])) {
            $fields[SystemOneParser::KEYWORDS] = ListField::of('text', SystemOneParser::KEYWORDS_INSTRUCTIONS);
        }

        return $fields;
    }
}
