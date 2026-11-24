<?php

namespace App\Actions\Stay;

use App\Models\Stay;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use App\Exceptions\InvalidStayDataException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Быстрый приём оплаты по заселению. */
class AddStayPaymentAction
{
    /** Создаёт одну оплату заселения сегодняшним днём без примечания.
     * @param Stay $stay Заселение (может быть устаревшей копией).
     * @param int $amount Сумма в гривнах, не меньше 1.
     * @throws InvalidStayDataException
     * @throws ModelNotFoundException
     * @return Payment
     */
    public function run(Stay $stay, int $amount): Payment
    {
        if ($amount <= 0) {
            throw new InvalidStayDataException('Сумма оплаты должна быть больше нуля');
        }

        return DB::transaction(function () use ($stay, $amount): Payment {
            $fresh = Stay::query()->lockForUpdate()->findOrFail($stay->id);

            return $fresh->payments()->create([
                'amount' => $amount,
                'paid_at' => today()->toDateString(),
                'note' => null,
            ]);
        });
    }
}
