<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Enums\StayStatus;
use App\Exceptions\StayChangedException;
use App\Exceptions\StayOverlapException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Заселение гостя с брони. */
class CheckInStayAction
{
    /** Переводит бронь в проживание, если фактический интервал проживающего не задевает соседей;
     * из других состояний ничего не меняет.
     * @param Stay $stay Заселение (может быть устаревшей копией).
     * @throws StayOverlapException
     * @throws StayChangedException
     * @throws ModelNotFoundException
     * @return Stay
     */
    public function run(Stay $stay): Stay
    {
        $guard = new GuardStayChangeAction();

        return $guard->run($stay, null, function (Bed $bed, Stay $fresh) use ($guard): Stay {
            if ($fresh->status !== StayStatus::Booked) {
                return $fresh;
            }

            $guard->assertBedFree($bed, $fresh->date_from, $fresh->date_to, StayStatus::Living, $fresh->id);

            $fresh->update(['status' => StayStatus::Living]);

            return $fresh;
        });
    }
}
