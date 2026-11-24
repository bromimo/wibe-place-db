<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Фабрика комнат.
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /** Состояние по умолчанию.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('Комната ###'),
            'price' => 460,
            'sort' => 0,
        ];
    }
}
