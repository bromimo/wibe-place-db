<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\Room;
use App\Models\User;
use App\Dto\Stay\StayDto;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Illuminate\Database\Seeder;
use App\Actions\Stay\CreateStayAction;
use App\Actions\Stay\SyncStayPaymentsAction;

/** Демо-данные графика заселения: комнаты, койки, гости и заселения относительно сегодняшнего дня. */
class DemoSeeder extends Seeder
{
    /** Комнаты: название, цена за ночь, число коек. */
    private const ROOMS = [
        ['1 комната', 460, 5],
        ['2 комната', 600, 1],
        ['3 комната', 460, 8],
        ['4 комната', 460, 3],
    ];

    /** Гости: фамилия и имя; последний — с длинным именем для проверки обрезки текста. */
    private const GUESTS = [
        ['Марченко', 'Максим'],
        ['Безуглый', 'Максим'],
        ['Коваленко', 'Виктор'],
        ['Шевченко', 'Иван'],
        ['Бондаренко', 'Олена'],
        ['Ткаченко', 'Андрій'],
        ['Кравченко', 'Наталія'],
        ['Олійник', 'Сергій'],
        ['Мельник', 'Оксана'],
        ['Поліщук', 'Дмитро'],
        ['Лисенко', 'Ірина'],
        ['Костянтинопольський-Задніпровський', 'Олександр'],
    ];

    /** Создаёт демо-данные.
     * @return void
     */
    public function run(): void
    {
        $beds = $this->createRooms();
        $guests = $this->createGuests();

        // Проживают, оплачено полностью.
        $this->stay($beds['1.1'], $guests[2], -3, 2, StayStatus::Living, payments: [2300]);
        $this->stay($beds['3.4'], $guests[7], 0, 7, StayStatus::Living, payments: [3220]);
        $this->stay($beds['4.1'], $guests[8], -1, 3, StayStatus::Living, payments: [1000, 840]);

        // Проживает, оплачено частично — есть долг.
        $this->stay($beds['1.3'], $guests[1], -1, 4, StayStatus::Living, payments: [1000]);

        // Ручной тариф.
        $this->stay($beds['2.1'], $guests[4], -2, 5, StayStatus::Living, manualTotal: 3000, payments: [1500]);
        $this->stay($beds['4.2'], $guests[9], 6, 9, StayStatus::Booked, manualTotal: 1200);

        // Выселились вчера.
        $this->stay($beds['1.5'], $guests[5], -4, -1, StayStatus::CheckedOut, payments: [1380]);
        $this->stay($beds['3.2'], $guests[6], -3, -1, StayStatus::CheckedOut, payments: [920]);

        // Брони на будущие дни; одна — на одну ночь у гостя с длинным именем.
        $this->stay($beds['1.5'], $guests[3], 1, 4, StayStatus::Booked);
        $this->stay($beds['1.1'], $guests[11], 3, 4, StayStatus::Booked);
        $this->stay($beds['3.3'], $guests[10], 2, 6, StayStatus::Booked, payments: [500]);
        $this->stay($beds['3.5'], $guests[3], 10, 14, StayStatus::Booked);

        // Просроченное: проживает, выезд был два дня назад; койка занята по сегодня.
        $this->stay($beds['1.4'], $guests[0], -6, -2, StayStatus::Living, payments: [1840]);
        $this->stay($beds['1.4'], $guests[10], 3, 7, StayStatus::Booked);

        // Длинное, на 40 дней — выходит за окно графика.
        $this->stay($beds['3.1'], $guests[11], -5, 35, StayStatus::Living, payments: [5000]);

        // Встык на одной койке: один выезжает сегодня, другой сегодня заезжает.
        $this->stay($beds['1.2'], $guests[0], -2, 0, StayStatus::Living, payments: [920]);
        $this->stay($beds['1.2'], $guests[2], 0, 3, StayStatus::Booked, payments: [460]);
    }

    /** Создаёт комнаты с койками «{номер комнаты}.{номер}».
     * @return array<string, Bed>
     */
    private function createRooms(): array
    {
        $beds = [];

        foreach (self::ROOMS as $index => [$name, $price, $bedCount]) {
            $number = $index + 1;
            $room = Room::query()->create(['name' => $name, 'price' => $price, 'sort' => $number]);

            for ($bed = 1; $bed <= $bedCount; $bed++) {
                $beds["{$number}.{$bed}"] = $room->beds()->create(['name' => "{$number}.{$bed}", 'sort' => $bed]);
            }
        }

        return $beds;
    }

    /** Создаёт гостей.
     * @return array<int, User>
     */
    private function createGuests(): array
    {
        $guests = [];

        foreach (self::GUESTS as [$lastName, $firstName]) {
            $guests[] = User::factory()->create(['last_name' => $lastName, 'first_name' => $firstName]);
        }

        return $guests;
    }

    /** Создаёт заселение через действие (оно не допускает пересечений) и его оплаты.
     * @param Bed $bed Койка.
     * @param User $guest Гость.
     * @param int $from День заезда относительно сегодня.
     * @param int $to День выезда относительно сегодня.
     * @param StayStatus $status Состояние.
     * @param int|null $manualTotal Сумма ручного тарифа; null — «наш» тариф.
     * @param array<int, int> $payments Суммы оплат: внесены в день заезда, у будущих броней — сегодня.
     * @return void
     */
    private function stay(Bed $bed, User $guest, int $from, int $to, StayStatus $status, ?int $manualTotal = null, array $payments = []): void
    {
        $dateFrom = today()->addDays($from)->toImmutable();

        $stay = (new CreateStayAction())->run(StayDto::from([
            'bed_id' => $bed->id,
            'user_id' => $guest->id,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => today()->addDays($to)->toDateString(),
            'status' => $status->value,
            'tariff' => ($manualTotal === null ? StayTariff::Standard : StayTariff::Manual)->value,
            'manual_total' => $manualTotal,
        ]));

        $paidAt = $dateFrom->gt(today()) ? today()->toDateString() : $dateFrom->toDateString();
        $rows = array_map(fn (int $amount): array => ['id' => null, 'amount' => $amount, 'paid_at' => $paidAt, 'note' => null], $payments);

        (new SyncStayPaymentsAction())->run($stay, $rows);
    }
}
