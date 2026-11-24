<?php

namespace App\Actions\Stay;

use App\Models\Stay;
use App\Enums\StayStatus;

/** Выселение гостя. */
class CheckOutStayAction
{
    /** Переводит проживание в выселение; дата выезда: сегодня, но не раньше следующего дня после заезда.
     * Из других состояний ничего не меняет.
     * @param Stay $stay Заселение.
     * @return Stay
     */
    public function run(Stay $stay): Stay
    {
        if ($stay->status !== StayStatus::Living) {
            return $stay;
        }

        $minimum = $stay->date_from->addDay();
        $dateTo = today()->gt($minimum) ? today() : $minimum;

        $stay->update([
            'status' => StayStatus::CheckedOut,
            'date_to' => $dateTo->toDateString(),
        ]);

        return $stay;
    }
}
