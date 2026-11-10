<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\PendingSearch;
use Workbench\App\Models\Post;

Route::view('/', 'demo', ['stack' => 'livewire']);
Route::view('/blade', 'demo', ['stack' => 'blade']);

// JSON for a demo page on another site: how a sentence was read, and the first rows.
$answer = function (PendingSearch $search) {
    $props = $search->toProps();
    unset($props['fields']);

    return response()->json($props + [
        'total' => Post::count(),
        'rows' => $search->query()->with('author')->limit(8)->get()->map(fn (Post $post) => [
            'title' => $post->title,
            'type' => $post->type,
            'author' => $post->author?->name,
            'views' => $post->views,
            'date' => $post->published_at?->toDateString(),
        ]),
    ]);
};

Route::get('/api/search', fn (Request $request) => $answer(
    SearchChips::for('posts')->search(mb_substr(trim((string) $request->query('q', '')), 0, 200))
));

// The same answer for a chip set the visitor edited. The package checks every chip again.
Route::get('/api/apply', function (Request $request) use ($answer) {
    $state = json_decode((string) $request->query('state', ''), true);
    $state = is_array($state) ? $state : [];

    return $answer(SearchChips::for('posts')->applyChips(
        array_slice(array_values(array_filter((array) ($state['chips'] ?? []), 'is_array')), 0, 12),
        mb_substr((string) ($state['leftover'] ?? ''), 0, 200),
        is_array($state['sort'] ?? null) ? $state['sort'] : null,
    ));
});

Route::get('/up', fn () => response('ok'));
