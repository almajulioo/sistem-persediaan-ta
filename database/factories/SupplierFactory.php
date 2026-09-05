<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bentuk = fake()->randomElement(['CV', 'PT', 'UD', 'Toko']);

        return [
            'supplier_name' => $bentuk.' '.fake()->unique()->company(),
            'address' => fake()->streetAddress().', Bandar Lampung',
            'phone' => '0721-'.fake()->numerify('######'),
            'email' => fake()->unique()->companyEmail(),
            'contact_person' => fake()->name(),
            'npwp' => fake()->numerify('##.###.###.#-###.###'),
            'is_active' => true,
        ];
    }

    public function arsip(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Penyedia yang baru didaftarkan dengan data seadanya — hanya nama dan
     * alamat, yang memang dua-duanya wajib.
     */
    public function minim(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => null,
            'email' => null,
            'contact_person' => null,
            'npwp' => null,
        ]);
    }
}
