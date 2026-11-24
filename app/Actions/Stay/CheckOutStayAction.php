<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use App\Exceptions\StayChangedException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Выселение гостя. */
class CheckOutStayAction
{
    /** Дата, которой был ограничен выезд при последнем вызове, или null. */
    private ?CarbonImmutable $clippedTo = null;

    /** Переводит проживание в выселение; дата выезда: сегодня, но не раньше следующего дня после заезда.
     * Если на койке позже заезда этого гостя начинается другое заселение раньше такой даты, выезд
     * ограничивается днём заезда ближайшего из них (см. clippedTo()). Из других состояний ничего не меняет.
     * @param Stay $stay Заселение (может быть устаревшей копией).
     * @throws StayChangedException
     * @throws ModelNotFoundException
     * @return Stay
     */
    public function run(Stay $stay): Stay
    {
        $this->clippedTo = null;

        return (new GuardStayChangeAction())->run($stay, null, function (Bed $bed, Stay $fresh): Stay {
            if ($fresh->status !== StayStatus::Living) {
                return $fresh;
            }

            $minimum = $fresh->date_from->addDay();
            $dateTo = today()->gt($minimum) ? today()->toImmutable() : $minimum;
            $next = $this->nextStayStart($bed, $fresh, $dateTo);

            if ($next !== null) {
                $dateTo = $next;
                $this->clippedTo = $next;
            }

            $fresh->update([
                'status' => StayStatus::CheckedOut,
                'date_to' => $dateTo->toDateString(),
            ]);

            return $fresh->refresh();
        });
    }

    /** Дата, которой был ограничен выезд при последнем вызове run(), или null, если ограничения не было.
     * @return CarbonImmutable|null
     */
    public function clippedTo(): ?CarbonImmutable
    {
        return $this->clippedTo;
    }

    /** Самый ранний заезд другого заселения на койке строго между заездом гостя и целевой датой выезда.
     * @param Bed $bed Заблокированная койка.
     * @param Stay $stay Выселяемое заселение.
     * @param CarbonImmutable $dateTo Целевая дата выезда.
     * @return CarbonImmutable|null
     */
    private function nextStayStart(Bed $bed, Stay $stay, CarbonImmutable $dateTo): ?CarbonImmutable
    {
        $next = Stay::query()
            ->where('bed_id', $bed->id)
            ->where('id', '!=', $stay->id)
            ->whereDate('date_from', '>', $stay->date_from->toDateString())
            ->whereDate('date_from', '<', $dateTo->toDateString())
            ->orderBy('date_from')
            ->first();

        return $next?->date_from;
    }
}
