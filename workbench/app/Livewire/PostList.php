<?php

namespace Workbench\App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Meirdick\SearchChips\Facades\SearchChips;

/** The results list of the demo: it listens to the search box and re-queries. */
class PostList extends Component
{
    public array $filters = [];

    public string $leftover = '';

    public ?array $sort = null;

    public ?string $engine = null;

    #[On('search-chips-updated')]
    public function filter(array $filters, string $leftover, ?array $sort, ?string $engine = null): void
    {
        [$this->filters, $this->leftover, $this->sort, $this->engine] = [$filters, $leftover, $sort, $engine];
    }

    public function render(): View
    {
        return view('livewire.post-list', [
            'posts' => SearchChips::for('posts')
                ->applyChips($this->filters, $this->leftover, $this->sort)
                ->query()
                ->with('author')
                ->limit(25)
                ->get(),
        ]);
    }
}
