<?php

namespace Meirdick\SearchChips\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\TasksQuery;
use Meirdick\SearchChips\Tests\Fixtures\IndexedPost;
use Throwable;

/**
 * Points Scout at one engine for a test and fills its index. Meilisearch
 * and Typesense run when MEILISEARCH_HOST or TYPESENSE_HOST is set and
 * the server answers; otherwise the test is skipped.
 */
final class ScoutEngines
{
    public const ALL = ['collection', 'database', 'meilisearch', 'typesense'];

    /** Index settings as `php artisan search-chips:scout` prints them. engagement_rate is left out on purpose. */
    public const FILTERABLE = ['title', 'type', 'views', 'is_featured', 'published_at', 'body', 'internal_note'];

    public const SORTABLE = ['views', 'published_at'];

    /** Configure Scout for the engine, or return why it cannot run. */
    public static function use(string $engine): ?string
    {
        config()->set('scout.driver', $engine);
        config()->set('scout.prefix', 'search_chips_'.Str::lower(Str::random(8)).'_');

        return match ($engine) {
            'meilisearch' => self::meilisearch(),
            'typesense' => self::typesense(),
            default => null,
        };
    }

    /**
     * Insert rows without syncing one by one, then index them in chunks.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function seed(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('posts')->insert(array_map(fn ($row) => $row + [
                'body' => null,
                'engagement_rate' => 0,
                'is_featured' => false,
                'published_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }

        IndexedPost::query()->searchable();

        if (config('scout.driver') === 'meilisearch') {
            self::waitForMeilisearch();
        }
    }

    public static function flush(): void
    {
        if (! in_array(config('scout.driver'), ['meilisearch', 'typesense'], true)) {
            return;
        }

        try {
            app(EngineManager::class)->engine()->deleteIndex((new IndexedPost)->searchableAs());
        } catch (Throwable) {
            // The index was never created.
        }
    }

    private static function meilisearch(): ?string
    {
        $host = env('MEILISEARCH_HOST');

        if (! $host || ! self::reachable(rtrim($host, '/').'/health')) {
            return 'Meilisearch is not reachable (set MEILISEARCH_HOST).';
        }

        config()->set('scout.meilisearch.host', $host);
        config()->set('scout.meilisearch.key', env('MEILISEARCH_KEY'));
        config()->set('scout.meilisearch.index-settings', [
            IndexedPost::class => [
                'searchableAttributes' => ['title'],
                'filterableAttributes' => self::FILTERABLE,
                'sortableAttributes' => self::SORTABLE,
                'pagination' => ['maxTotalHits' => 10_000],
            ],
        ]);

        app()->forgetInstance(MeilisearchClient::class);
        app()->forgetInstance(EngineManager::class);

        $engine = app(EngineManager::class)->engine('meilisearch');
        $index = (new IndexedPost)->searchableAs();

        $engine->createIndex($index, ['primaryKey' => 'id']);
        $engine->updateIndexSettings($index, config('scout.meilisearch.index-settings')[IndexedPost::class]);
        self::waitForMeilisearch();

        return null;
    }

    private static function typesense(): ?string
    {
        $host = env('TYPESENSE_HOST');
        $port = env('TYPESENSE_PORT', '8108');
        $protocol = env('TYPESENSE_PROTOCOL', 'http');

        if (! $host || ! self::reachable("{$protocol}://{$host}:{$port}/health")) {
            return 'Typesense is not reachable (set TYPESENSE_HOST).';
        }

        $node = ['host' => $host, 'port' => $port, 'path' => '', 'protocol' => $protocol];

        config()->set('scout.typesense.client-settings', [
            'api_key' => env('TYPESENSE_API_KEY', 'xyz'),
            'nodes' => [$node],
            'nearest_node' => $node,
            'connection_timeout_seconds' => 2,
            'healthcheck_interval_seconds' => 30,
            'num_retries' => 1,
            'retry_interval_seconds' => 1,
        ]);
        config()->set('scout.typesense.model-settings', [
            IndexedPost::class => [
                'collection-schema' => [
                    'fields' => [
                        ['name' => 'id', 'type' => 'string'],
                        ['name' => 'title', 'type' => 'string'],
                        ['name' => 'type', 'type' => 'string'],
                        ['name' => 'views', 'type' => 'int64'],
                        ['name' => 'is_featured', 'type' => 'bool'],
                        ['name' => 'published_at', 'type' => 'int64', 'optional' => true],
                        ['name' => 'body', 'type' => 'string', 'optional' => true],
                        ['name' => 'internal_note', 'type' => 'string', 'optional' => true],
                    ],
                ],
                'search-parameters' => ['query_by' => 'title'],
            ],
        ]);

        app()->forgetInstance(EngineManager::class);

        return null;
    }

    private static function waitForMeilisearch(): void
    {
        $client = app(MeilisearchClient::class);
        $query = (new TasksQuery)->setIndexUids([(new IndexedPost)->searchableAs()])->setStatuses(['enqueued', 'processing']);

        for ($i = 0; $i < 200 && $client->getTasks($query)->getTotal() > 0; $i++) {
            usleep(50_000);
        }
    }

    private static function reachable(string $url): bool
    {
        try {
            return Http::timeout(2)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
