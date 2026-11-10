<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Engine
    |--------------------------------------------------------------------------
    | The rules engine always runs: no network, no keys. An AI engine can
    | refine its reading.
    |
    | rules:       rules only.
    | auto:        Cloudflare System One (Clairic) when credentials exist,
    |              otherwise rules only.
    | system-one:  Cloudflare System One. Falls back to rules on failure.
    | ai:          a chat model through laravel/ai. Needs provider and model.
    |
    | There is no automatic fallback from one AI engine to another. Add your
    | own engine with SearchChips::extend('name', fn ($app) => new MyParser).
    */
    'engine' => env('SEARCH_CHIPS_ENGINE', 'auto'),

    'ai' => [
        'cloudflare' => [
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            'api_token' => env('CLOUDFLARE_API_TOKEN'),
            'model' => env('SEARCH_CHIPS_CF_MODEL', '@cf/cloudflare/clairic'),
            // Full endpoint override, for AI Gateway or a proxy.
            'url' => env('SEARCH_CHIPS_CF_URL'),
            // auto: use meirdick/laravel-cf-workersai when it is installed and has a
            // configured provider. direct: always call the REST API with the keys above.
            'transport' => env('SEARCH_CHIPS_CF_TRANSPORT', 'auto'),
            // The laravel/ai provider name for that package. Found by driver when null.
            'provider' => env('SEARCH_CHIPS_CF_PROVIDER'),
            'presence_threshold' => 0.6,    // an answer needs 1 - p_null at or above this
            'confidence_threshold' => 0.6,  // an answer below this is dropped
            'suggest_below' => 0.85,        // an answer below this is a suggested chip: shown, not applied
            'guess_p_null' => 0.65,         // a confident guess with p_null at or below this is also a suggested chip
            'max_fields' => 24,
            'timeout' => 8,
        ],
        'chat' => [
            'provider' => env('SEARCH_CHIPS_CHAT_PROVIDER'),
            'model' => env('SEARCH_CHIPS_CHAT_MODEL'),
            'timeout' => 8,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | An alias listed here is reachable from the UI components and the JSON
    | routes. Requests carry the alias, never a class name.
    |
    |   'posts' => App\Models\Post::class,
    |   'posts' => [
    |       'model' => App\Models\Post::class,
    |       'only' => ['title', 'type', 'views'],     // allow-list of fields
    |       'exclude' => ['internal_note'],
    |       'search' => ['title', 'body'],            // fields the leftover words search
    |       'orderable' => ['published_at', 'views'],
    |       'relations' => ['author'],                // allow-list of relation filters
    |       'fields' => ['rate' => ['unit' => 'percent', 'scale' => 0.01, 'aliases' => ['engagement']]],
    |       'engine' => 'rules',                      // per-model engine
    |       'authorize' => 'viewAny',                 // Gate ability, receives the model class
    |       'columns' => ['id', 'title'],             // row payload of the JSON routes
    |       'resource' => App\Http\Resources\PostResource::class,
    |   ],
    |
    | A model can also declare its own fields: add a
    | searchChips(ChipSchema $chips) method. Options that need a closure
    | ('query', 'authorize') go through SearchChips::register() in a provider.
    */
    'models' => [],

    // Register every model in app/Models under a slug alias. This makes all
    // of them searchable through the routes. Leave off outside prototypes.
    'auto_discover' => env('SEARCH_CHIPS_AUTO_DISCOVER', false),

    'discovery' => [
        // Read a few distinct values of short text columns so that words
        // like "podcasts" resolve without configuration. The values are
        // real row data and are sent to the AI engine. Off by default.
        'sample_values' => env('SEARCH_CHIPS_SAMPLE_VALUES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | The JSON routes serve the Blade and Inertia components. The Livewire
    | component does not use them. For a public search page remove 'auth'.
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'search-chips',
        'middleware' => ['web', 'auth', 'throttle:search-chips'],
        'rate_limit' => 120, // requests per minute, per user or IP
    ],

    // Gate ability or closure checked for every alias, before the per-model
    // 'authorize' option. Applies to the routes and the Livewire component.
    'authorize' => null,

    // Extra words to ignore in the keyword search, on top of the built-in
    // filler words ("show", "me", "written", "with", ...).
    'stopwords' => [],

    'limits' => [
        'max_filters' => 10,
        'max_query_length' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Scout
    |--------------------------------------------------------------------------
    | A model that uses Scout's Searchable trait sends its keyword text
    | through Scout.
    |
    | filters: send the chips to the engine as filters when it can apply
    |          them all, so the engine pages and counts the result. The
    |          collection and database engines apply every chip. Meilisearch
    |          and Typesense need the fields in the index settings; run
    |          `php artisan search-chips:scout {alias}` to print them.
    |          Otherwise, and for query(), the matching keys join the chip
    |          filters in one Eloquent query.
    | limit:   how many keys or rows one search takes from the engine.
    */
    'scout' => [
        'enabled' => true,
        'filters' => true,
        'limit' => 1000,
    ],

    'cache' => [
        'store' => env('SEARCH_CHIPS_CACHE_STORE'),
        // Cache AI parses. The rules engine is never cached.
        'enabled' => env('SEARCH_CHIPS_CACHE', true),
        'ttl' => 300,
        // Keep discovered schemas until `php artisan search-chips:clear`.
        // Turn on in production; run search-chips:cache on deploy.
        'schema' => env('SEARCH_CHIPS_SCHEMA_CACHE', false),
    ],
];
