<?php

namespace Meirdick\SearchChips\Facades;

use Illuminate\Support\Facades\Facade;
use Meirdick\SearchChips\Applying\EloquentApplicator;
use Meirdick\SearchChips\FakeSearchChips;
use Meirdick\SearchChips\Parsing\RulesParser;
use Meirdick\SearchChips\Registry\ModelRegistry;
use Meirdick\SearchChips\SearchChipsManager;

/**
 * @method static \Meirdick\SearchChips\ModelHandle for(string $model)
 * @method static \Meirdick\SearchChips\Registry\RegisteredModel register(string $modelClass, ?string $alias = null, array $options = [])
 * @method static array discover(?string $directory = null, ?string $namespace = null)
 * @method static static extend(string $name, \Closure $factory)
 * @method static \Meirdick\SearchChips\Data\ModelSchema schema(string $model)
 * @method static ?string aliasFor(string $modelClass)
 * @method static bool refinable(string $model)
 * @method static \Meirdick\SearchChips\ParseResult parse(string $query, string $model, bool $refine = true)
 * @method static \Meirdick\SearchChips\PendingSearch search(string $query, string $model, bool $refine = true)
 * @method static \Meirdick\SearchChips\PendingSearch applyChips(string $model, array $chips, string $leftover = '', ?array $sort = null)
 * @method static \Meirdick\SearchChips\Registry\RegisteredModel authorize(string $alias)
 * @method static array models()
 * @method static void assertParsed(string $query, ?string $model = null)
 * @method static void assertNothingParsed()
 * @method static \Meirdick\SearchChips\FakeSearchChips shouldReturn(string $model, \Meirdick\SearchChips\ParseResult|\Closure $result)
 *
 * @see SearchChipsManager
 */
class SearchChips extends Facade
{
    public static function fake(): FakeSearchChips
    {
        $fake = new FakeSearchChips(
            static::$app,
            static::$app->make(ModelRegistry::class),
            static::$app->make(RulesParser::class),
            static::$app->make(EloquentApplicator::class),
        );

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return SearchChipsManager::class;
    }
}
