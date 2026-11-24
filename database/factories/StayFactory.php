<?php

namespace Database\Factories;

use App\Enums\StayStatus;
use App\Enums\StayTariff;
use App\Models\Bed;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Фабрика заселений.
 *
 * @extends Factory<Stay>
 */
class StayFactory extends Factory
{
    /**
     * Состояние по умолчанию.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bed_id' => Bed::factory(),
            'user_id' => User::factory(),
            'date_from' => today(),
            'date_to' => today()->addDay(),
            'status' => StayStatus::Living,
            'tariff' => StayTariff::Standard,
            'price_per_night' => 460,
            'manual_total' => null,
            'notes' => null,
        ];
    }
}
