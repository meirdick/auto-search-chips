<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Search Chips demo</title>
    {{-- The demo styles itself in the browser so the package needs no build step. --}}
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <main class="mx-auto max-w-4xl px-4 py-12">
        <h1 class="text-xl font-semibold">Search Chips demo</h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-zinc-500">
            Try:
            @foreach (['videos by Ada above 10k views', 'featured podcasts last month', 'latest articles about rockets', 'chess movies by hopper with more then 100 views', 'most viewed shows not by Grace'] as $example)
                <a href="{{ url()->current() }}?q={{ urlencode($example) }}" class="rounded-md border border-zinc-200 px-2 py-0.5 font-mono text-xs text-zinc-700 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300">{{ $example }}</a>
            @endforeach
        </p>
        <p class="mt-2 text-sm">
            AI engine:
            @if (\Meirdick\SearchChips\Facades\SearchChips::refinable('posts'))
                <span class="font-medium text-emerald-700">Cloudflare System One (Clairic), live</span>
            @else
                <span class="font-medium text-zinc-500">none configured, rules only</span>
            @endif
        </p>
        <nav class="mt-4 flex gap-4 text-sm">
            <a href="/" @class(['underline', 'font-semibold' => $stack === 'livewire'])>Livewire</a>
            <a href="/blade" @class(['underline', 'font-semibold' => $stack === 'blade'])>Blade + Alpine</a>
        </nav>

        <div class="mt-6">
            @if ($stack === 'livewire')
                <livewire:search-chips for="posts" placeholder="Search posts…" panel />
                <livewire:post-list />
            @else
                <x-search-chips::search for="posts" placeholder="Search posts…" with-results>
                    <ul class="mt-4 divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                        <template x-for="post in results" :key="post.id">
                            <li class="flex justify-between px-4 py-2 text-sm">
                                <span x-text="post.title"></span>
                                <span class="text-zinc-500" x-text="post.type + ' · ' + post.views.toLocaleString() + ' views'"></span>
                            </li>
                        </template>
                    </ul>
                    <p class="mt-2 px-1 text-xs text-zinc-400" x-show="engine">Last reading by: <strong x-text="engine"></strong></p>
                </x-search-chips::search>
                <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
            @endif
        </div>
    </main>
</body>
</html>
