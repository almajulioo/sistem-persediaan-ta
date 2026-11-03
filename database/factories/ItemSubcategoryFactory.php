<?php

namespace Database\Factories;

use App\Enums\KategoriBarang;
use App\Models\ItemSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ItemSubcategory>
 */
class ItemSubcategoryFactory extends Factory
{
    protected $model = ItemSubcategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subcategory_code' => fake()->unique()->numerify('101030####'),
            'subcategory_name' => mb_strtoupper(fake()->unique()->words(2, true)),
            'category' => fake()->randomElement(KategoriBarang::cases()),
        ];
    }

    public function atk(): static
    {
        return $this->state(fn (array $attributes) => ['category' => KategoriBarang::ATK]);
    }

    public function artk(): static
    {
        return $this->state(fn (array $attributes) => ['category' => KategoriBarang::ARTK]);
    }
}
