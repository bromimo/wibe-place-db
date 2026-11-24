<?php

namespace App\Actions\Stay;

use App\Models\Stay;
use App\Enums\StayStatus;

/** Заселение гостя с брони. */
class CheckInStayAction
{
    /** Переводит бронь в проживание; из других состояний ничего не меняет.
     * @param Stay $stay Заселение.
     * @return Stay
     */
    public function run(Stay $stay): Stay
    {
        if ($stay->status === StayStatus::Booked) {
            $stay->update(['status' => StayStatus::Living]);
        }

        return $stay;
    }
}
