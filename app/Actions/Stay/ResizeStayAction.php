<?php

namespace App\Actions\Stay;

use App\Models\Stay;
use App\Dto\Stay\StayDto;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Exceptions\StayLockedException;
use App\Exceptions\StayOverlapException;
use App\Exceptions\InvalidStayDataException;

/** Изменение даты выезда заселения. */
class ResizeStayAction
{
    /** Меняет дату выезда; стоимость производна от дат.
     * @param Stay $stay Заселение.
     * @param CarbonInterface $dateTo Новый день выезда.
     * @throws StayLockedException
     * @throws StayOverlapException
     * @throws InvalidStayDataException
     * @return Stay
     */
    public function run(Stay $stay, CarbonInterface $dateTo): Stay
    {
        if ($stay->status === StayStatus::CheckedOut) {
            throw new StayLockedException();
        }

        $dto = new StayDto(
            bed_id: $stay->bed_id,
            user_id: $stay->user_id,
            date_from: $stay->date_from,
            date_to: CarbonImmutable::parse($dateTo->toDateString()),
            status: $stay->status,
            tariff: $stay->tariff,
            manual_total: $stay->manual_total,
            notes: $stay->notes,
        );

        return (new UpdateStayAction())->run($stay, $dto);
    }
}
