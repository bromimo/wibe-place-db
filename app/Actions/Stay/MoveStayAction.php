<?php

namespace App\Actions\Stay;

use App\Models\Stay;
use App\Dto\Stay\StayDto;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Exceptions\StayLockedException;
use App\Exceptions\StayOverlapException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Перенос заселения на другую койку и дату с сохранением длительности. */
class MoveStayAction
{
    /** Переносит заселение; цену определяет UpdateStayAction (смена комнаты берёт цену новой).
     * @param Stay $stay Переносимое заселение.
     * @param int $bedId Целевая койка.
     * @param CarbonInterface $dateFrom Новый день заезда.
     * @throws StayLockedException
     * @throws StayOverlapException
     * @throws ModelNotFoundException
     * @return Stay
     */
    public function run(Stay $stay, int $bedId, CarbonInterface $dateFrom): Stay
    {
        if ($stay->status === StayStatus::CheckedOut) {
            throw new StayLockedException();
        }

        $from = CarbonImmutable::parse($dateFrom->toDateString());

        $dto = new StayDto(
            bed_id: $bedId,
            user_id: $stay->user_id,
            date_from: $from,
            date_to: $from->addDays($stay->nights()),
            status: $stay->status,
            tariff: $stay->tariff,
            manual_total: $stay->manual_total,
            notes: $stay->notes,
        );

        return (new UpdateStayAction())->run($stay, $dto);
    }
}
