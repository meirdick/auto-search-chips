<?php

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\PendingSearch;
use Workbench\App\Models\Post;

Route::view('/', 'demo', ['stack' => 'livewire']);
Route::view('/blade', 'demo', ['stack' => 'blade']);

// JSON for a demo page on another site: how a sentence was read, and the first rows.
// `calls` holds each HTTP call the package made for this answer (the Clairic call), so the page can show it.
$answer = function (Closure $make) {
    $calls = [];
    Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$calls) {
        $calls[] = [
            'model' => '@cf/cloudflare/clairic',
            'status' => $event->response->status(),
            'ms' => (int) round(($event->response->transferStats?->getTransferTime() ?? 0) * 1000),
            'request' => $event->request->data(),
            'response' => $event->response->json(),
        ];
    });
    Event::listen(ConnectionFailed::class, function (ConnectionFailed $event) use (&$calls) {
        $calls[] = ['model' => '@cf/cloudflare/clairic', 'status' => 0, 'ms' => 0, 'request' => $event->request->data(), 'response' => null];
    });

    /** @var PendingSearch $search */
    $search = $make();
    $props = $search->toProps();
    unset($props['fields']);
    $query = $search->query();

    return response()->json($props + [
        'calls' => $calls,
        'sql' => $query->toRawSql(),
        'total' => Post::count(),
        'rows' => $query->with('author')->limit(8)->get()->map(fn (Post $post) => [
            'title' => $post->title,
            'type' => $post->type,
            'author' => $post->author?->name,
            'views' => $post->views,
            'date' => $post->published_at?->toDateString(),
        ]),
    ]);
};

Route::get('/api/search', fn (Request $request) => $answer(
    fn () => SearchChips::for('posts')->search(mb_substr(trim((string) $request->query('q', '')), 0, 200))
));

// The same answer for a chip set the visitor edited. The package checks every chip again.
Route::get('/api/apply', function (Request $request) use ($answer) {
    $state = json_decode((string) $request->query('state', ''), true);
    $state = is_array($state) ? $state : [];

    return $answer(fn () => SearchChips::for('posts')->applyChips(
        array_slice(array_values(array_filter((array) ($state['chips'] ?? []), 'is_array')), 0, 12),
        mb_substr((string) ($state['leftover'] ?? ''), 0, 200),
        is_array($state['sort'] ?? null) ? $state['sort'] : null,
    ));
});

Route::get('/up', fn () => response('ok'));
