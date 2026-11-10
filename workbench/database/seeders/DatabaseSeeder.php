<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Author;
use Workbench\App\Models\Post;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $authors = collect(['Ada Lovelace', 'Grace Hopper', 'Alan Turing', 'Maya Chen'])
            ->map(fn (string $name) => Author::create(['name' => $name]));

        $topics = ['Rockets explained', 'Garden notes', 'Budget travel', 'Sourdough basics', 'Night photography', 'Home espresso', 'Trail running', 'Chess openings'];
        $types = ['video', 'article', 'podcast'];

        mt_srand(7); // the same demo data on every build

        foreach (range(1, 60) as $i) {
            Post::create([
                'author_id' => $authors[$i % 4]->id,
                'type' => $types[$i % 3],
                'title' => $topics[$i % 8].' #'.$i,
                'views' => mt_rand(50, 80_000),
                'engagement_rate' => mt_rand(5, 150) / 1000,
                'is_featured' => $i % 7 === 0,
                'published_at' => now()->subDays(mt_rand(0, 400)),
            ]);
        }
    }
}
