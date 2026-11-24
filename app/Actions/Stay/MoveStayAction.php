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
use App\Exceptions\GuestOverlapException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Перенос заселения на другую койку и дату с сохранением длительности. */
class MoveStayAction
{
    /** Переносит заселение; состояние и длительность берутся из записи, перечитанной под блокировкой.
     * Цену определяет UpdateStayAction (смена комнаты берёт цену новой).
     * @param Stay $stay Переносимое заселение (может быть устаревшей копией).
     * @param int $bedId Целевая койка.
     * @param CarbonInterface $dateFrom Новый день заезда.
     * @throws StayLockedException
     * @throws StayOverlapException
     * @throws GuestOverlapException
     * @throws StayChangedException
     * @throws ModelNotFoundException
     * @return Stay
     */
    public function run(Stay $stay, int $bedId, CarbonInterface $dateFrom): Stay
    {
        $from = CarbonImmutable::parse($dateFrom->toDateString());

        return (new GuardStayChangeAction())->run($stay, $bedId, function (Bed $bed, Stay $fresh) use ($from): Stay {
            if ($fresh->status === StayStatus::CheckedOut) {
                throw new StayLockedException();
            }

            $dto = new StayDto(
                bed_id: $bed->id,
                user_id: $fresh->user_id,
                date_from: $from,
                date_to: $from->addDays($fresh->nights()),
                status: $fresh->status,
                tariff: $fresh->tariff,
                manual_total: $fresh->manual_total,
                notes: $fresh->notes,
            );

            return (new UpdateStayAction())->apply($bed, $fresh, $dto);
        });
    }
}
