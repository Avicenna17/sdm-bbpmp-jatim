<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PersonFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'person_key' => hash('sha256', fake()->unique()->uuid()),
            'canonical_name' => $name,
            'normalized_name' => mb_strtoupper($name),
            'nip' => null,
        ];
    }
}
