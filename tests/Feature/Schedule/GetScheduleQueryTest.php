<?php

namespace Tests\Feature\Schedule;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Payment;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Http\Queries\Schedule\GetScheduleQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты выборки данных окна шахматки. */
class GetScheduleQueryTest extends TestCase
{
    use RefreshDatabase;

    /** Фиксирует текущее время на 24 ноября 2026. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 12:00');
    }

    /** Выполняет выборку для окна.
     * @param string $start Первый день окна.
     * @param int $days Число дней.
     * @return array<string, mixed>
     */
    private function schedule(string $start = '2026-11-23', int $days = 31): array
    {
        return app(GetScheduleQuery::class)(CarbonImmutable::parse($start), $days);
    }

    /** Создаёт заселение на новой койке (или на переданной).
     * @param string $from Дата заезда.
     * @param string $to Дата выезда.
     * @param StayStatus $status Статус.
     * @param Bed|null $bed Койка.
     * @return Stay
     */
    private function makeStay(string $from, string $to, StayStatus $status = StayStatus::Living, ?Bed $bed = null): Stay
    {
        return Stay::factory()->create([
            'bed_id' => ($bed ?? Bed::factory()->create())->id,
            'date_from' => $from,
            'date_to' => $to,
            'status' => $status,
        ]);
    }

    /** Возвращает первую полосу первой койки первой комнаты.
     * @param array<string, mixed> $data Данные шахматки.
     * @return array<string, mixed>
     */
    private function firstBar(array $data): array
    {
        return $data['rooms'][0]['beds'][0]['stays'][0];
    }

    /** Дни, выходные, месяцы и сегодняшний индекс. */
    public function test_days_and_months(): void
    {
        $data = $this->schedule();

        $this->assertSame('2026-11-23', $data['start']);
        $this->assertCount(31, $data['days']);
        $this->assertSame(['date' => '2026-11-23', 'day' => 23, 'weekday' => 'Пн', 'weekend' => false, 'today' => false], $data['days'][0]);
        $this->assertTrue($data['days'][5]['weekend']);
        $this->assertTrue($data['days'][6]['weekend']);
        $this->assertFalse($data['days'][7]['weekend']);
        $this->assertTrue($data['days'][1]['today']);
        $this->assertSame([['label' => 'Ноябрь 2026', 'span' => 8], ['label' => 'Декабрь 2026', 'span' => 23]], $data['months']);
        $this->assertSame(1, $data['today_index']);
    }

    /** Сегодня вне окна. */
    public function test_today_outside_window(): void
    {
        $this->assertNull($this->schedule('2027-01-01')['today_index']);
    }

    /** Порядок комнат и коек по sort. */
    public function test_ordering(): void
    {
        $second = Room::factory()->create(['name' => 'B', 'sort' => 2]);
        $first = Room::factory()->create(['name' => 'A', 'sort' => 1]);
        Bed::factory()->create(['room_id' => $first->id, 'name' => 'a2', 'sort' => 2]);
        Bed::factory()->create(['room_id' => $first->id, 'name' => 'a1', 'sort' => 1]);

        $data = $this->schedule();

        $this->assertSame(['A', 'B'], array_column($data['rooms'], 'name'));
        $this->assertSame(['a1', 'a2'], array_column($data['rooms'][0]['beds'], 'name'));
        $this->assertSame($second->id, $data['rooms'][1]['id']);
    }

    /** Полоса внутри окна. */
    public function test_bar_offsets(): void
    {
        $this->makeStay('2026-11-24', '2026-11-27');

        $bar = $this->firstBar($this->schedule());

        $this->assertSame(1.5, $bar['start']);
        $this->assertSame(4.5, $bar['end']);
        $this->assertFalse($bar['clipped_left']);
        $this->assertFalse($bar['clipped_right']);
    }

    /** Полоса обрезана слева. */
    public function test_bar_clipped_left(): void
    {
        $this->makeStay('2026-11-20', '2026-11-24');

        $bar = $this->firstBar($this->schedule());

        $this->assertSame(0.0, $bar['start']);
        $this->assertSame(1.5, $bar['end']);
        $this->assertTrue($bar['clipped_left']);
    }

    /** Полоса обрезана справа. */
    public function test_bar_clipped_right(): void
    {
        $this->makeStay('2026-12-20', '2027-01-10', StayStatus::Booked);

        $bar = $this->firstBar($this->schedule());

        $this->assertSame(31.0, $bar['end']);
        $this->assertTrue($bar['clipped_right']);
    }

    /** Полоса обрезана с обеих сторон. */
    public function test_bar_clipped_both_sides(): void
    {
        $this->makeStay('2026-11-01', '2027-01-31');

        $bar = $this->firstBar($this->schedule());

        $this->assertSame(0.0, $bar['start']);
        $this->assertSame(31.0, $bar['end']);
        $this->assertTrue($bar['clipped_left']);
        $this->assertTrue($bar['clipped_right']);
    }

    /** Заселения вне окна не попадают в выборку. */
    public function test_stay_outside_window_excluded(): void
    {
        $bed = Bed::factory()->create();
        $this->makeStay('2026-11-01', '2026-11-10', StayStatus::CheckedOut, $bed);
        $this->makeStay('2027-01-05', '2027-01-09', StayStatus::Booked, $bed);

        $this->assertSame([], $this->schedule()['rooms'][0]['beds'][0]['stays']);
    }

    /** Заселение, выезжающее в первый день окна, видно. */
    public function test_stay_ending_on_first_day_included(): void
    {
        $this->makeStay('2026-11-20', '2026-11-23', StayStatus::CheckedOut);

        $bar = $this->firstBar($this->schedule());

        $this->assertSame(0.0, $bar['start']);
        $this->assertSame(0.5, $bar['end']);
    }

    /** Оплачено и долг на полосе. */
    public function test_money_on_bar(): void
    {
        $stay = $this->makeStay('2026-11-24', '2026-11-27');
        Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 1000]);

        $bar = $this->firstBar($this->schedule());

        $this->assertSame(1000, $bar['paid']);
        $this->assertSame(380, $bar['debt']);
        $this->assertSame($stay->id, $bar['id']);
        $this->assertSame('living', $bar['status']);
        $this->assertSame($stay->user->full_name, $bar['guest']);
    }

    /** Свободные места по дням. */
    public function test_free_beds(): void
    {
        $room = Room::factory()->create();
        $beds = Bed::factory()->count(5)->create(['room_id' => $room->id]);
        $this->makeStay('2026-11-23', '2026-11-24', StayStatus::CheckedOut, $beds[0]);
        $this->makeStay('2026-11-24', '2026-11-27', StayStatus::Living, $beds[1]);
        $this->makeStay('2026-11-24', '2026-11-25', StayStatus::Booked, $beds[2]);

        $free = $this->schedule()['rooms'][0]['free'];

        $this->assertCount(31, $free);
        $this->assertSame(4, $free[0]);
        $this->assertSame(3, $free[1]);
        $this->assertSame(4, $free[2]);
        $this->assertSame(5, $free[4]);
    }

    /** Просроченное заселение дотягивается до сегодня и занимает койку. */
    public function test_overdue_bar_and_free(): void
    {
        $bed = Bed::factory()->create();
        $this->makeStay('2026-11-20', '2026-11-23', StayStatus::Living, $bed);

        $room = $this->schedule()['rooms'][0];

        $this->assertSame(2.0, $room['beds'][0]['stays'][0]['overdue_end']);
        $this->assertSame(0, $room['free'][0]);
        $this->assertSame(0, $room['free'][1]);
        $this->assertSame(1, $room['free'][2]);
    }

    /** Просроченное заселение до начала окна всё равно выбирается. */
    public function test_overdue_before_window_selected(): void
    {
        $this->makeStay('2026-11-10', '2026-11-15', StayStatus::Living);

        $bar = $this->firstBar($this->schedule());

        $this->assertSame(2.0, $bar['overdue_end']);
        $this->assertSame(0.0, $bar['end']);
    }

    /** Выселенное заселение нельзя перетаскивать. */
    public function test_checked_out_not_draggable(): void
    {
        $this->makeStay('2026-11-24', '2026-11-27', StayStatus::CheckedOut);

        $this->assertFalse($this->firstBar($this->schedule())['draggable']);
    }

    /** Пустой хостел и комната без коек. */
    public function test_empty_hostel_and_room_without_beds(): void
    {
        $this->assertSame([], $this->schedule()['rooms']);

        Room::factory()->create();
        $room = $this->schedule()['rooms'][0];

        $this->assertSame([], $room['beds']);
        $this->assertSame(array_fill(0, 31, 0), $room['free']);
    }

    /** Индексы не смещаются при переходе на летнее время. */
    public function test_dst_change_does_not_shift_indexes(): void
    {
        $this->makeStay('2027-03-27', '2027-03-30');

        $data = $this->schedule('2027-03-20');
        $bar = $this->firstBar($data);

        $this->assertCount(31, $data['days']);
        $this->assertSame('2027-04-19', $data['days'][30]['date']);
        $this->assertSame(7.5, $bar['start']);
        $this->assertSame(10.5, $bar['end']);
    }

    /** Число запросов не зависит от числа комнат и коек. */
    public function test_query_count_is_constant(): void
    {
        foreach (range(1, 3) as $roomNumber) {
            $room = Room::factory()->create(['sort' => $roomNumber]);

            foreach (Bed::factory()->count(5)->create(['room_id' => $room->id]) as $bed) {
                $this->makeStay('2026-11-24', '2026-11-26', StayStatus::Living, $bed);
                $second = $this->makeStay('2026-11-28', '2026-11-30', StayStatus::Booked, $bed);
                Payment::factory()->create(['stay_id' => $second->id]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->schedule();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $count);
    }
}
