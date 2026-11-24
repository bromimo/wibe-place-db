<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Dto\Stay\StayDto;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Exceptions\StayLockedException;
use App\Exceptions\StayChangedException;
use App\Exceptions\StayOverlapException;
use App\Exceptions\InvalidStayDataException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Изменение даты выезда заселения. */
class ResizeStayAction
{
    /** Меняет дату выезда на текущей койке заселения; койка, заезд и состояние берутся из свежей записи.
     * @param Stay $stay Заселение (может быть устаревшей копией).
     * @param CarbonInterface $dateTo Новый день выезда.
     * @throws StayLockedException
     * @throws StayOverlapException
     * @throws InvalidStayDataException
     * @throws StayChangedException
     * @throws ModelNotFoundException
     * @return Stay
     */
    public function run(Stay $stay, CarbonInterface $dateTo): Stay
    {
        $to = CarbonImmutable::parse($dateTo->toDateString());

        return (new GuardStayChangeAction())->run($stay, null, function (Bed $bed, Stay $fresh) use ($to): Stay {
            if ($fresh->status === StayStatus::CheckedOut) {
                throw new StayLockedException();
            }

            $dto = new StayDto(
                bed_id: $bed->id,
                user_id: $fresh->user_id,
                date_from: $fresh->date_from,
                date_to: $to,
                status: $fresh->status,
                tariff: $fresh->tariff,
                manual_total: $fresh->manual_total,
                notes: $fresh->notes,
            );

            return (new UpdateStayAction())->apply($bed, $fresh, $dto);
        });
    }
}
