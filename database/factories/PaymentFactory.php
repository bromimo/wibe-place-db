<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Stay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Фабрика оплат.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Состояние по умолчанию.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'amount' => 460,
            'paid_at' => today(),
            'note' => null,
        ];
    }
}
