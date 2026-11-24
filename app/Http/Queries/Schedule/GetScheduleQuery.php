<?php

namespace App\Http\Queries\Schedule;

use App\Models\Room;
use App\Models\Stay;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Данные окна шахматки: дни, месяцы, комнаты, койки и полосы заселений. */
class GetScheduleQuery
{
    private const WEEKDAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    private const MONTHS = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];

    /** Собирает данные окна из $days дней, начиная с $start.
     * @param CarbonInterface $start Первый день окна.
     * @param int $days Число дней окна.
     * @return array<string, mixed>
     */
    public function __invoke(CarbonInterface $start, int $days = 31): array
    {
        $base = CarbonImmutable::parse($start->toDateString(), 'UTC');
        $todayIndex = $this->index($base, today()->toDateString());

        $rooms = Room::query()
            ->with('beds')
            ->orderBy('number')
            ->get();
        $stays = $this->loadStays($rooms, $base, $days)->groupBy('bed_id');

        return [
            'start' => $base->toDateString(),
            'days' => $this->buildDays($base, $days, $todayIndex),
            'months' => $this->buildMonths($base, $days),
            'today_index' => $todayIndex >= 0 && $todayIndex < $days ? $todayIndex : null,
            'rooms' => $rooms->map(fn (Room $room) => $this->buildRoom($room, $stays, $base, $days, $todayIndex))->all(),
        ];
    }

    /** Заселения коек, пересекающие окно, с гостем и суммой оплат.
     * @param Collection<int, Room> $rooms Комнаты с койками.
     * @param CarbonImmutable $base Первый день окна.
     * @param int $days Число дней окна.
     * @return Collection<int, Stay>
     */
    private function loadStays(Collection $rooms, CarbonImmutable $base, int $days): Collection
    {
        $bedIds = $rooms->flatMap(fn (Room $room) => $room->beds->pluck('id'))->all();

        if ($bedIds === []) {
            return new Collection();
        }

        $first = $base->toDateString();
        $last = $base->addDays($days - 1)->toDateString();
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();

        return Stay::query()
            ->with('user')
            ->withSum('payments', 'amount')
            ->whereIn('bed_id', $bedIds)
            ->whereDate('date_from', '<=', $last)
            ->where(function ($query) use ($first, $today, $tomorrow) {
                $query->whereDate('date_to', '>=', $first)
                    ->orWhere(function ($overdue) use ($first, $today, $tomorrow) {
                        $overdue->where('status', StayStatus::Living->value)
                            ->whereDate('date_to', '<', $today)
                            ->whereRaw('? >= ?', [$tomorrow, $first]);
                    });
            })
            ->orderBy('date_from')
            ->orderBy('id')
            ->get();
    }

    /** Календарный индекс даты относительно первого дня окна.
     * @param CarbonImmutable $base Первый день окна (UTC).
     * @param string $date Дата Y-m-d.
     * @return int
     */
    private function index(CarbonImmutable $base, string $date): int
    {
        return (int) $base->diffInDays(CarbonImmutable::parse($date, 'UTC'));
    }

    /** Список дней окна.
     * @param CarbonImmutable $base Первый день окна.
     * @param int $days Число дней окна.
     * @param int $todayIndex Индекс сегодняшнего дня.
     * @return array<int, array<string, mixed>>
     */
    private function buildDays(CarbonImmutable $base, int $days, int $todayIndex): array
    {
        $result = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $base->addDays($i);

            $result[] = [
                'date' => $date->toDateString(),
                'day' => $date->day,
                'weekday' => self::WEEKDAYS[$date->dayOfWeekIso],
                'weekend' => $date->dayOfWeekIso >= 6,
                'today' => $i === $todayIndex,
            ];
        }

        return $result;
    }

    /** Подписи месяцев с числом дней каждого в окне.
     * @param CarbonImmutable $base Первый день окна.
     * @param int $days Число дней окна.
     * @return array<int, array{label: string, span: int}>
     */
    private function buildMonths(CarbonImmutable $base, int $days): array
    {
        $months = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $base->addDays($i);
            $label = self::MONTHS[$date->month] . ' ' . $date->year;
            $lastKey = array_key_last($months);

            if ($lastKey !== null && $months[$lastKey]['label'] === $label) {
                $months[$lastKey]['span']++;
            } else {
                $months[] = ['label' => $label, 'span' => 1];
            }
        }

        return $months;
    }

    /** Данные одной комнаты.
     * @param Room $room Комната.
     * @param Collection<int, Collection<int, Stay>> $stays Заселения, сгруппированные по койке.
     * @param CarbonImmutable $base Первый день окна.
     * @param int $days Число дней окна.
     * @param int $todayIndex Индекс сегодняшнего дня.
     * @return array<string, mixed>
     */
    private function buildRoom(Room $room, Collection $stays, CarbonImmutable $base, int $days, int $todayIndex): array
    {
        $occupied = array_fill(0, $days + 1, 0);
        $beds = [];

        foreach ($room->beds as $bed) {
            $bedStays = [];

            foreach ($stays->get($bed->id, []) as $stay) {
                $bedStays[] = $this->buildStay($stay, $base, $days, $todayIndex);
                $this->markOccupied($occupied, $stay, $base, $days);
            }

            $beds[] = ['id' => $bed->id, 'name' => $bed->name, 'stays' => $bedStays];
        }

        $free = [];
        $running = 0;
        $total = $room->beds->count();

        for ($i = 0; $i < $days; $i++) {
            $running += $occupied[$i];
            $free[] = max(0, $total - $running);
        }

        return [
            'id' => $room->id,
            'name' => $room->name,
            'price' => (int) $room->price,
            'free' => $free,
            'beds' => $beds,
        ];
    }

    /** Отмечает занятые дни в разностном массиве.
     * @param array<int, int> $occupied Разностный массив занятости.
     * @param Stay $stay Заселение.
     * @param CarbonImmutable $base Первый день окна.
     * @param int $days Число дней окна.
     * @return void
     */
    private function markOccupied(array &$occupied, Stay $stay, CarbonImmutable $base, int $days): void
    {
        $from = max(0, $this->index($base, $stay->date_from->toDateString()));
        $to = min($days, $this->index($base, $stay->effectiveDateTo()->toDateString()));

        if ($to > $from) {
            $occupied[$from]++;
            $occupied[$to]--;
        }
    }

    /** Данные полосы заселения.
     * @param Stay $stay Заселение.
     * @param CarbonImmutable $base Первый день окна.
     * @param int $days Число дней окна.
     * @param int $todayIndex Индекс сегодняшнего дня.
     * @return array<string, mixed>
     */
    private function buildStay(Stay $stay, CarbonImmutable $base, int $days, int $todayIndex): array
    {
        $rawStart = $this->index($base, $stay->date_from->toDateString()) + 0.5;
        $rawEnd = $this->index($base, $stay->date_to->toDateString()) + 0.5;
        $overdueEnd = $stay->isOverdue() ? (float) min($days, max(0, $todayIndex + 1)) : null;

        return [
            'id' => $stay->id,
            'guest' => $stay->user->full_name,
            'status' => $stay->status->value,
            'date_from' => $stay->date_from->toDateString(),
            'date_to' => $stay->date_to->toDateString(),
            'start' => (float) min($days, max(0, $rawStart)),
            'end' => (float) min($days, max(0, $rawEnd)),
            'overdue_end' => $overdueEnd,
            'clipped_left' => $rawStart < 0,
            'clipped_right' => $rawEnd > $days,
            'paid' => $stay->paid(),
            'debt' => $stay->debt(),
            'draggable' => $stay->status !== StayStatus::CheckedOut,
        ];
    }
}
