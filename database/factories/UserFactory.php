<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Фабрика гостей.
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Состояние по умолчанию.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'phone' => fake()->numerify('+380#########'),
        ];
    }
}
