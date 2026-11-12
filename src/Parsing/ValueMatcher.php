<?php

namespace Meirdick\SearchChips\Parsing;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Meirdick\SearchChips\Data\FieldSchema;
use Meirdick\WorkersAi\Extraction;
use Meirdick\WorkersAi\Providers\ClefClassification;
use Throwable;

/**
 * Maps a word to one of a field's allowed values with a Cloudflare Clef
 * decision model: "movies" to the value "video". Clef picks among listed
 * options and returns a probability for each, so it can also answer "none".
 *
 * This step is optional. When it is off, has no route, or fails, the word
 * stays unmatched and the caller keeps its own reading.
 *
 * https://developers.cloudflare.com/workers-ai/models/clef-flash/
 */
final class ValueMatcher
{
    private const NONE = 'none';

    public function __construct(private readonly array $config) {}

    /**
     * @param  array<string, array{field: FieldSchema, word: string}>  $words  keyed by field name
     * @return array<string, array{value: mixed, confidence: float}> the words that name a value
     */
    public function match(string $query, array $words): array
    {
        if ($words === [] || ! ($this->config['match_values'] ?? true)) {
            return [];
        }

        $questions = [];
        $options = [];

        foreach (array_keys($words) as $i => $name) {
            ['field' => $field, 'word' => $word] = $words[$name];
            $criteria = [];

            foreach (array_values($field->values ?? []) as $n => $value) {
                $options["q{$i}"]["v{$n}"] = $value;
                $criteria["v{$n}"] = (string) ($field->valueLabels[$value] ?? $value);
            }

            $criteria[self::NONE] = 'The word means none of the other options.';
            $questions["q{$i}"] = [
                'name' => $name,
                'instructions' => "In this search, the word \"{$word}\" may name a {$field->displayLabel()}. Which option does \"{$word}\" mean?",
                'criteria' => $criteria,
            ];
        }

        try {
            $answers = $this->usesPackage() ? $this->askPackage($query, $questions) : $this->askDirectly($query, $questions);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $threshold = (float) ($this->config['match_threshold'] ?? 0.7);
        $matches = [];

        foreach ($questions as $id => $question) {
            [$choice, $probability] = $answers[$id] ?? [self::NONE, 0.0];

            if ($choice !== self::NONE && isset($options[$id][$choice]) && $probability >= $threshold) {
                $matches[$question['name']] = ['value' => $options[$id][$choice], 'confidence' => $probability];
            }
        }

        return $matches;
    }

    private function usesPackage(): bool
    {
        return ($this->config['transport'] ?? 'auto') !== 'direct'
            && class_exists(Extraction::class)
            && class_exists(Classification::class)
            && ClefClassification::available()
            && (new WorkersAiPackageExtractor)->provider() !== null;
    }

    /** @return array<string, array{0: string, 1: float}> the chosen option and its probability, by question id */
    private function askPackage(string $query, array $questions): array
    {
        $pending = Classification::of(['search' => $query]);

        foreach ($questions as $id => $question) {
            $pending->question($id, new Choice($question['instructions'], $question['criteria']));
        }

        $response = $pending
            ->timeout((int) ($this->config['timeout'] ?? 8))
            ->classify(
                provider: (string) ($this->config['provider'] ?? (new WorkersAiPackageExtractor)->provider()),
                model: $this->model(),
            );

        $answers = [];

        foreach (array_keys($questions) as $id) {
            if (($answer = $response->answers[$id] ?? null) instanceof ChoiceAnswer) {
                $answers[$id] = [$answer->choice, $answer->probabilityOf($answer->choice)];
            }
        }

        return $answers;
    }

    /** @return array<string, array{0: string, 1: float}> */
    private function askDirectly(string $query, array $questions): array
    {
        if (($endpoint = $this->endpoint()) === null) {
            return [];
        }

        $response = Http::acceptJson()
            ->withToken((string) $this->config['api_token'])
            ->timeout((int) ($this->config['timeout'] ?? 8))
            ->post($endpoint, [
                'model' => basename($this->model()), // required by the model's request schema
                'state' => ['search' => $query],
                'questions' => array_map(fn (array $question) => [
                    'type' => 'choice',
                    'instructions' => $question['instructions'],
                    'criteria' => $question['criteria'],
                ], $questions),
            ])->throw()->json();

        $answers = [];

        foreach ((array) ($response['result']['answers'] ?? $response['answers'] ?? []) as $id => $answer) {
            if (is_array($answer) && is_string($choice = $answer['choice'] ?? null)) {
                $answers[$id] = [$choice, (float) ($answer['probabilities'][$choice] ?? 0)];
            }
        }

        return $answers;
    }

    private function model(): string
    {
        return ltrim((string) ($this->config['match_model'] ?? '@cf/cloudflare/clef-flash'), '/');
    }

    /** Null when no direct route exists: a `url` override serves Clairic only. */
    private function endpoint(): ?string
    {
        if (filled($this->config['match_url'] ?? null)) {
            return (string) $this->config['match_url'];
        }

        if (filled($this->config['url'] ?? null) || blank($this->config['account_id'] ?? null) || blank($this->config['api_token'] ?? null)) {
            return null;
        }

        return 'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode((string) $this->config['account_id']).'/ai/run/'.$this->model();
    }
}
