<?php

namespace Database\Factories;

use App\Models\Bed;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Фабрика коек.
 *
 * @extends Factory<Bed>
 */
class BedFactory extends Factory
{
    /**
     * Состояние по умолчанию.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'name' => fake()->unique()->numerify('#.#'),
            'sort' => 0,
        ];
    }
}
