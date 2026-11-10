<?php

namespace Meirdick\SearchChips\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Livewire\Livewire;

class InstallCommand extends Command
{
    protected $signature = 'search-chips:install
        {--stack= : livewire, blade, react, vue or svelte. Detected when omitted}
        {--force : Overwrite component files that already exist}';

    protected $description = 'Install the Search Chips config and the UI component for your frontend stack';

    private const STACKS = ['livewire', 'blade', 'react', 'vue', 'svelte'];

    private const SOURCE_LINE = "@source '../../vendor/meirdick/auto-search-chips/resources/views/**/*.blade.php';";

    public function handle(Filesystem $files): int
    {
        $stack = $this->option('stack') ?: $this->detectStack($files);

        if (! in_array($stack, self::STACKS, true)) {
            $this->components->error("Unknown stack [{$stack}]. Use one of: ".implode(', ', self::STACKS).'.');

            return self::FAILURE;
        }

        $this->components->info("Installing Search Chips for the {$stack} stack.");

        $this->callSilently('vendor:publish', ['--tag' => 'search-chips-config']);
        $this->components->task('Config at config/search-chips.php');

        match ($stack) {
            'livewire', 'blade' => $this->addTailwindSource($files),
            default => $this->copyComponents($files, $stack),
        };

        $this->newLine();
        $this->components->bulletList($this->nextSteps($stack));

        return self::SUCCESS;
    }

    private function detectStack(Filesystem $files): string
    {
        $package = $files->exists(base_path('package.json'))
            ? (array) json_decode($files->get(base_path('package.json')), true)
            : [];

        $npm = [...($package['dependencies'] ?? []), ...($package['devDependencies'] ?? [])];

        return match (true) {
            isset($npm['@inertiajs/react']) => 'react',
            isset($npm['@inertiajs/vue3']) => 'vue',
            isset($npm['@inertiajs/svelte']) => 'svelte',
            class_exists(Livewire::class) => 'livewire',
            default => 'blade',
        };
    }

    /** The Inertia components are source files in the app, like the starter kit's own components. */
    private function copyComponents(Filesystem $files, string $stack): void
    {
        $target = resource_path('js/components/search-chips');
        $files->ensureDirectoryExists($target);

        $sources = [
            __DIR__.'/../../resources/js/core.ts',
            ...$files->files(__DIR__.'/../../stubs/'.$stack),
        ];

        foreach ($sources as $source) {
            $name = basename((string) $source);
            $path = $target.'/'.$name;

            if ($files->exists($path) && ! $this->option('force')) {
                $this->components->warn("Kept existing resources/js/components/search-chips/{$name} (use --force to overwrite).");

                continue;
            }

            $files->copy((string) $source, $path);
            $this->components->task("resources/js/components/search-chips/{$name}");
        }
    }

    /** Tailwind 4 does not scan vendor/. Without this line the Blade views render unstyled. */
    private function addTailwindSource(Filesystem $files): void
    {
        $css = resource_path('css/app.css');

        if (! $files->exists($css) || ! str_contains($contents = $files->get($css), 'tailwindcss')) {
            $this->components->warn('No Tailwind 4 entry at resources/css/app.css. Add this line to your Tailwind CSS file:');
            $this->line('  '.self::SOURCE_LINE);

            return;
        }

        if (str_contains($contents, 'auto-search-chips/resources/views')) {
            $this->components->task('Tailwind already scans the package views');

            return;
        }

        // After the last @import or @source line, so the directive order stays valid.
        $lines = explode("\n", $contents);
        $last = -1;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*@(import|source)\b/', $line)) {
                $last = $index;
            }
        }

        array_splice($lines, $last + 1, 0, [self::SOURCE_LINE]);
        $files->put($css, implode("\n", $lines));

        $this->components->task('Tailwind scans the package views (resources/css/app.css)');
    }

    /** @return array<int, string> */
    private function nextSteps(string $stack): array
    {
        $register = 'Register a model in a service provider: <comment>SearchChips::register(Post::class, \'posts\');</comment>';
        $check = 'Check what is filterable: <comment>php artisan search-chips:schema posts</comment>';

        return match ($stack) {
            'livewire' => [
                $register,
                'Add the search box: <comment><livewire:search-chips for="posts" /></comment>',
                'In your list component: <comment>#[On(\'search-chips-updated\')]</comment>, then <comment>SearchChips::for(\'posts\')->applyChips($filters, $leftover, $sort)->paginate()</comment>',
                $check,
            ],
            'blade' => [
                $register,
                'Add the search box: <comment><x-search-chips::search for="posts" /></comment> (needs Alpine.js)',
                'Listen for the <comment>search-chips:updated</comment> browser event to reload your results.',
                $check,
            ],
            default => [
                $register,
                'In the controller: <comment>$search = SearchChips::for(\'posts\')->fromRequest($request);</comment>',
                'Pass <comment>\'posts\' => $search->paginate()</comment> and <comment>\'searchChips\' => $search->toProps()</comment> to the page.',
                'In the page: <comment><SearchChips for="posts" '.($stack === 'vue' ? ':initial="searchChips" :reload="[\'posts\', \'searchChips\']"' : 'initial={searchChips} reload={[\'posts\', \'searchChips\']}').' /></comment>',
                $check,
            ],
        };
    }
}
