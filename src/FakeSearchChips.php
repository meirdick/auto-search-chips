<?php

namespace Meirdick\SearchChips;

use PHPUnit\Framework\Assert;

/**
 * Swap in via SearchChips::fake() for tests: records every parse, lets you
 * stub engine results per alias, and falls back to the real deterministic
 * rules engine so chip behavior stays verifiable without any network.
 */
class FakeSearchChips extends SearchChipsManager
{
    /** @var array<int, array{0: string, 1: string}> [alias, query] */
    protected array $recorded = [];

    /** @var array<string, ParseResult|\Closure> */
    protected array $stubbed = [];

    public function parse(string $query, string $model, bool $refine = true): ParseResult
    {
        $alias = $this->resolve($model)->alias;

        $this->recorded[] = [$alias, $query];

        if (isset($this->stubbed[$alias])) {
            return $this->stubbed[$alias] instanceof \Closure
                ? ($this->stubbed[$alias])($query, $alias)
                : $this->stubbed[$alias];
        }

        return parent::parse($query, $alias, $refine);
    }

    /**
     * Stub the parse result for an alias, with a fixed result or a closure
     * receiving (query, alias).
     */
    public function shouldReturn(string $model, ParseResult|\Closure $result): static
    {
        $this->stubbed[$this->resolve($model)->alias] = $result;

        return $this;
    }

    public function assertParsed(string $query, ?string $model = null): void
    {
        $alias = $model === null ? null : $this->resolve($model)->alias;
        $matched = false;

        foreach ($this->recorded as [$recordedAlias, $recordedQuery]) {
            if ($recordedQuery === $query && ($alias === null || $recordedAlias === $alias)) {
                $matched = true;
            }
        }

        $target = $alias ? " for alias [{$alias}]" : '';

        Assert::assertTrue($matched, "No search-chips parse was recorded for [{$query}]{$target}.");
    }

    public function assertNothingParsed(): void
    {
        Assert::assertEmpty($this->recorded, 'Search chips parses were recorded when none were expected.');
    }

    /** @return array<int, array{0: string, 1: string}> */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
