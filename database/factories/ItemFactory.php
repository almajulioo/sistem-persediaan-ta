<?php

namespace Database\Factories;

use App\Enums\SatuanBarang;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Item>
 */
class ItemFactory extends Factory
{
    /**
     * `stock_current` sengaja tidak masuk $fillable pada model agar stok hanya
     * berubah lewat StockLedger. Untuk menyiapkan skenario uji, factory
     * mengisinya langsung dengan forceFill.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Item
    {
        return (new Item)->forceFill($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $min = fake()->numberBetween(5, 20);

        return [
            'item_code' => 'BRG-'.Str::upper(fake()->unique()->bothify('??##')),
            'item_name' => fake()->unique()->words(3, true),
            'unit' => fake()->randomElement(SatuanBarang::cases()),
            'stock_current' => fake()->numberBetween($min + 1, $min * 5),
            'stock_min' => $min,
            'stock_max' => $min * 10,
            'is_active' => true,
        ];
    }

    /**
     * Stok berada pada atau di bawah batas minimum.
     */
    public function menipis(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_current' => max(1, (int) $attributes['stock_min']),
        ]);
    }

    public function habis(): static
    {
        return $this->state(fn (array $attributes) => ['stock_current' => 0]);
    }

    public function arsip(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Tanpa batas atas.
     */
    public function tanpaBatasMaksimum(): static
    {
        return $this->state(fn (array $attributes) => ['stock_max' => 0]);
    }
}
