<?php

namespace App\Http\Queries\Stay;

use App\Models\Stay;
use App\Enums\StayStatus;
use Carbon\CarbonInterface;

/** Поиск заселения на койке, пересекающегося с интервалом дат. */
class FindOverlappingStayQuery
{
    /** Первое заселение на койке, пересекающееся с [from, to).
     * Просроченное проживающее заселение занимает койку до сегодняшнего дня включительно.
     * @param int $bedId Койка.
     * @param CarbonInterface $from Дата заезда.
     * @param CarbonInterface $to Дата выезда.
     * @param int|null $exceptStayId Заселение, которое не учитывается.
     * @return Stay|null
     */
    public function __invoke(int $bedId, CarbonInterface $from, CarbonInterface $to, ?int $exceptStayId = null): ?Stay
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();

        return Stay::query()
            ->with('user')
            ->where('bed_id', $bedId)
            ->when($exceptStayId !== null, fn ($query) => $query->where('id', '!=', $exceptStayId))
            ->whereDate('date_from', '<', $toDate)
            ->where(function ($query) use ($fromDate, $today, $tomorrow) {
                $query->whereDate('date_to', '>', $fromDate)
                    ->orWhere(function ($overdue) use ($fromDate, $today, $tomorrow) {
                        $overdue->where('status', StayStatus::Living->value)
                            ->whereDate('date_to', '<', $today)
                            ->whereRaw('? > ?', [$tomorrow, $fromDate]);
                    });
            })
            ->orderBy('date_from')
            ->first();
    }
}
