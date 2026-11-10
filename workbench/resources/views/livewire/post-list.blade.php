<div>
    @if ($engine)
        <p class="mt-2 px-1 text-xs text-zinc-400">Last reading by: <strong>{{ $engine }}</strong></p>
    @endif
    <ul class="mt-4 divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
        @forelse ($posts as $post)
            <li wire:key="post-{{ $post->id }}" class="flex justify-between gap-4 px-4 py-2 text-sm">
                <span>{{ $post->title }} <span class="text-zinc-400">by {{ $post->author->name }}</span></span>
                <span class="shrink-0 text-zinc-500">{{ $post->type }} · {{ number_format($post->views) }} views</span>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-zinc-500">No posts match.</li>
        @endforelse
    </ul>
</div>
