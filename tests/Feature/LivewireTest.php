<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Livewire\Search;
use Meirdick\SearchChips\Tests\Fixtures\Author;
use Meirdick\SearchChips\Tests\Fixtures\DeclaredPost;

beforeEach(function () {
    SearchChips::register(DeclaredPost::class, 'posts');
    DeclaredPost::create(['type' => 'video', 'title' => 'Launch', 'views' => 50_000, 'published_at' => now()]);
    DeclaredPost::create(['type' => 'article', 'title' => 'Notes', 'views' => 10, 'published_at' => now()]);
});

it('turns typing into chips and announces them', function () {
    Livewire::test(Search::class, ['for' => 'posts'])
        ->set('query', 'videos above 10k views')
        ->assertSet('count', 1)
        ->assertCount('chips', 2)
        ->assertSee('Type is Video')
        ->assertDispatched('search-chips-updated', fn ($event, $params) => $params['alias'] === 'posts'
            && count($params['filters']) === 2
            && $params['count'] === 1);
});

it('removes one chip without parsing again', function () {
    $component = Livewire::test(Search::class, ['for' => 'posts'])->set('query', 'videos above 10k views');
    $views = collect($component->get('chips'))->firstWhere('field', 'views')['id'];

    Http::fake();

    $component->call('remove', $views)
        ->assertCount('chips', 1)
        ->assertSet('count', 1)
        ->assertSet('edited', true);

    Http::assertNothingSent();
});

it('clears everything and reports the full count', function () {
    Livewire::test(Search::class, ['for' => 'posts'])
        ->set('query', 'videos')
        ->call('clear')
        ->assertSet('query', '')
        ->assertSet('chips', [])
        ->assertSet('count', 2)
        ->assertDispatched('search-chips-updated', fn ($event, $params) => $params['filters'] === [] && $params['count'] === 2);
});

it('asks the browser for an AI refine and applies it', function () {
    config()->set('search-chips.engine', 'system-one');
    config()->set('search-chips.ai.cloudflare.account_id', 'account');
    config()->set('search-chips.ai.cloudflare.api_token', 'token');

    Http::fake(['*/ai/run/*' => Http::response(['result' => ['answers' => [
        'type' => ['type' => 'text', 'value' => 'podcast', 'confidence' => 0.99, 'p_null' => 0.01, 'span' => [0, 5]],
    ]]])]);

    $component = Livewire::test(Search::class, ['for' => 'posts'])->set('query', 'shows about rockets');

    Http::assertNothingSent(); // phase 1 is rules only

    $component->call('refine', 'shows about rockets')->assertSee('Type is Podcast');
});

it('ignores a refine that arrives after the user edited the chips', function () {
    config()->set('search-chips.engine', 'system-one');
    config()->set('search-chips.ai.cloudflare.account_id', 'account');
    config()->set('search-chips.ai.cloudflare.api_token', 'token');
    Http::fake();

    $component = Livewire::test(Search::class, ['for' => 'posts'])->set('query', 'videos above 10k views');
    $component->call('remove', $component->get('chips')[0]['id'])->call('refine', 'videos above 10k views');

    Http::assertNothingSent();
});

it('locks the alias', function () {
    Livewire::test(Search::class, ['for' => 'posts'])->set('alias', 'users');
})->throws(Exception::class);

it('enforces the model ability', function () {
    SearchChips::register(DeclaredPost::class, 'guarded', ['authorize' => 'search-guarded']);
    Gate::define('search-guarded', fn () => false);

    Livewire::test(Search::class, ['for' => 'guarded'])->assertForbidden();
});

it('does not mount for a model that was never registered', function () {
    SearchChips::for(Author::class);

    Livewire::test(Search::class, ['for' => 'authors'])->assertNotFound();
});

it('renders through the short tag', function () {
    expect(Blade::render('<livewire:search-chips for="posts" placeholder="Find posts" />'))
        ->toContain('Find posts');
});

it('changes filters from the panel controls', function () {
    Livewire::test(Search::class, ['for' => 'posts', 'panel' => true])
        ->assertSee('Views')
        ->call('setFilter', 'type', 'equals', 'video')
        ->assertSet('count', 1)
        ->assertSee('Type is Video')
        ->call('setFilter', 'views', 'gte', '60000')
        ->assertSet('count', 0)
        ->call('unsetFilter', 'views')
        ->assertSet('count', 1)
        ->call('setFilter', 'title', 'contains', '<script>')
        ->assertSet('count', 0);
});
