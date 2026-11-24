<?php

namespace App\Http\Queries\Stay;

use App\Models\Stay;
use App\Enums\StayStatus;
use Carbon\CarbonInterface;

/** Поиск заселения гостя на любой койке, пересекающегося с интервалом дат. */
class FindGuestOverlappingStayQuery
{
    /** Первое заселение гостя, пересекающееся с [from, to), в любом состоянии и на любой койке.
     * Просроченное проживающее заселение длится до сегодняшнего дня включительно.
     * @param int $userId Гость.
     * @param CarbonInterface $from Дата заезда.
     * @param CarbonInterface $to Дата выезда.
     * @param int|null $exceptStayId Заселение, которое не учитывается.
     * @return Stay|null
     */
    public function __invoke(int $userId, CarbonInterface $from, CarbonInterface $to, ?int $exceptStayId = null): ?Stay
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();

        return Stay::query()
            ->with('bed')
            ->where('user_id', $userId)
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
