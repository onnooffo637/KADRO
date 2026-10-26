<?php

namespace Database\Factories;

use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SeriesFactory extends Factory
{
    protected $model = Series::class;

    public function definition(): array
    {
        $title = 'Fictional Series '.fake()->unique()->numberBetween(10000, 9999999);

        return [
            'slug' => Str::slug($title),
            'primary_title' => $title,
            'format' => 'manhwa',
            'original_language' => 'ko',
            'publication_status' => 'draft',
            'completion_status' => 'ongoing',
            'synopsis' => 'A fictional story created solely for local schema testing.',
        ];
    }
}
