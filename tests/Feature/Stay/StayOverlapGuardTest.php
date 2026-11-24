<?php

namespace Tests\Feature\Stay;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Dto\Stay\StayDto;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use App\Actions\Stay\MoveStayAction;
use App\Actions\Stay\CreateStayAction;
use App\Actions\Stay\DeleteStayAction;
use App\Actions\Stay\ResizeStayAction;
use App\Actions\Stay\UpdateStayAction;
use App\Actions\Stay\CheckInStayAction;
use App\Exceptions\StayLockedException;
use App\Actions\Stay\CheckOutStayAction;
use App\Exceptions\StayOverlapException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты защищённого пути изменения заселений: фактический интервал, свежая модель, выселение. */
class StayOverlapGuardTest extends TestCase
{
    use RefreshDatabase;

    private Bed $bed;

    /** Фиксирует сегодня 24 ноября 2026 и создаёт койку в комнате за 460. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 12:00');
        $this->bed = Bed::factory()->create(['room_id' => Room::factory()->create(['price' => 460])->id]);
    }

    /** Создаёт заселение на койке теста.
     * @param string $from День заезда.
     * @param string $to День выезда.
     * @param StayStatus $status Состояние.
     * @param Bed|null $bed Койка; по умолчанию койка теста.
     * @return Stay
     */
    private function stay(string $from, string $to, StayStatus $status = StayStatus::Living, ?Bed $bed = null): Stay
    {
        return Stay::factory()->create([
            'bed_id' => ($bed ?? $this->bed)->id,
            'date_from' => $from,
            'date_to' => $to,
            'status' => $status,
        ]);
    }

    /** DTO заселения на койке теста.
     * @param string $from День заезда.
     * @param string $to День выезда.
     * @param StayStatus $status Состояние.
     * @param int|null $userId Гость; по умолчанию новый.
     * @return StayDto
     */
    private function dto(string $from, string $to, StayStatus $status, ?int $userId = null): StayDto
    {
        return StayDto::from([
            'bed_id' => $this->bed->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'date_from' => $from,
            'date_to' => $to,
            'status' => $status->value,
            'tariff' => 'standard',
        ]);
    }

    /** Фактический конец: проживающий с прошедшим выездом занимает койку по сегодня; остальные — по date_to. */
    public function test_effective_end(): void
    {
        $past = CarbonImmutable::parse('2026-11-15');
        $todayEnd = CarbonImmutable::parse('2026-11-24');

        $this->assertSame('2026-11-25', Stay::effectiveEnd(StayStatus::Living, $past)->toDateString());
        $this->assertSame('2026-11-15', Stay::effectiveEnd(StayStatus::Booked, $past)->toDateString());
        $this->assertSame('2026-11-15', Stay::effectiveEnd(StayStatus::CheckedOut, $past)->toDateString());
        $this->assertSame('2026-11-24', Stay::effectiveEnd(StayStatus::Living, $todayEnd)->toDateString());
    }

    /** Сценарий ревью: бронь A 10→15, законное B 20→26; «Заселить» A отклоняется, A не меняется. */
    public function test_check_in_overdue_booking_onto_later_stay_rejected(): void
    {
        $a = $this->stay('2026-11-10', '2026-11-15', StayStatus::Booked);
        $b = (new CreateStayAction())->run($this->dto('2026-11-20', '2026-11-26', StayStatus::Living));

        try {
            (new CheckInStayAction())->run($a);
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException $e) {
            $this->assertStringContainsString($b->user->full_name, $e->getMessage());
        }

        $a->refresh();
        $this->assertSame(StayStatus::Booked, $a->status);
        $this->assertSame('2026-11-10', $a->date_from->toDateString());
        $this->assertSame('2026-11-15', $a->date_to->toDateString());
    }

    /** «Заселить» без конфликта работает и для просроченной брони на свободной койке. */
    public function test_check_in_overdue_booking_on_free_bed(): void
    {
        $a = $this->stay('2026-11-10', '2026-11-15', StayStatus::Booked);

        $result = (new CheckInStayAction())->run($a);

        $this->assertSame(StayStatus::Living, $result->status);
        $this->assertSame(StayStatus::Living, $a->fresh()->status);
    }

    /** Создание проживающего задним числом проверяет продление по сегодня. */
    public function test_create_backdated_living_rejected(): void
    {
        $this->stay('2026-11-20', '2026-11-26');

        $this->expectException(StayOverlapException::class);

        try {
            (new CreateStayAction())->run($this->dto('2026-11-10', '2026-11-15', StayStatus::Living));
        } finally {
            $this->assertSame(1, Stay::query()->count());
        }
    }

    /** Та же бронь задним числом не держит койку сверх дат и создаётся. */
    public function test_create_backdated_booking_allowed(): void
    {
        $this->stay('2026-11-20', '2026-11-26');

        (new CreateStayAction())->run($this->dto('2026-11-10', '2026-11-15', StayStatus::Booked));

        $this->assertSame(2, Stay::query()->count());
    }

    /** Правка выселившегося в «проживает» отклоняется, если продление по сегодня задевает соседа. */
    public function test_update_checked_out_to_living_rejected(): void
    {
        $a = $this->stay('2026-11-10', '2026-11-15', StayStatus::CheckedOut);
        $this->stay('2026-11-20', '2026-11-26');

        try {
            (new UpdateStayAction())->run($a, $this->dto('2026-11-10', '2026-11-15', StayStatus::Living, $a->user_id));
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException) {
            $this->assertSame(StayStatus::CheckedOut, $a->fresh()->status);
        }
    }

    /** Правка брони в «проживает» отклоняется, если продление по сегодня задевает соседа. */
    public function test_update_booked_to_living_rejected(): void
    {
        $a = $this->stay('2026-11-10', '2026-11-15', StayStatus::Booked);
        $this->stay('2026-11-20', '2026-11-26');

        try {
            (new UpdateStayAction())->run($a, $this->dto('2026-11-10', '2026-11-15', StayStatus::Living, $a->user_id));
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException) {
            $this->assertSame(StayStatus::Booked, $a->fresh()->status);
        }
    }

    /** Выселение просроченного при следующем гостье на койке обрезает выезд днём его заезда. */
    public function test_check_out_overdue_clips_to_next_stay(): void
    {
        $a = $this->stay('2026-11-10', '2026-11-15');
        $this->stay('2026-11-20', '2026-11-26', StayStatus::Booked);
        $this->stay('2026-11-22', '2026-11-23', StayStatus::Booked, Bed::factory()->create());

        $action = new CheckOutStayAction();
        $result = $action->run($a);

        $this->assertSame(StayStatus::CheckedOut, $result->status);
        $this->assertSame('2026-11-20', $result->date_to->toDateString());
        $this->assertSame('2026-11-20', $a->fresh()->date_to->toDateString());
        $this->assertSame('2026-11-20', $action->clippedTo()?->toDateString());
    }

    /** Выселение просроченного без следующего гостя продлевает выезд до сегодня, обрезки нет. */
    public function test_check_out_overdue_without_next_stay(): void
    {
        $a = $this->stay('2026-11-10', '2026-11-15');
        $this->stay('2026-11-01', '2026-11-10', StayStatus::CheckedOut);

        $action = new CheckOutStayAction();
        $result = $action->run($a);

        $this->assertSame('2026-11-24', $result->date_to->toDateString());
        $this->assertNull($action->clippedTo());
    }

    /** Устаревшая копия: перенос через одну, растягивание через другую не обходит проверку новой койки. */
    public function test_stale_copy_resize_after_move_rejected(): void
    {
        $other = Bed::factory()->create(['room_id' => $this->bed->room_id]);
        $stay = $this->stay('2026-11-24', '2026-11-26');
        $stale = Stay::query()->findOrFail($stay->id);
        $this->stay('2026-11-27', '2026-11-30', StayStatus::Booked, $other);

        (new MoveStayAction())->run($stay, $other->id, $stay->date_from);

        try {
            (new ResizeStayAction())->run($stale, CarbonImmutable::parse('2026-11-29'));
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException) {
            $fresh = $stay->fresh();
            $this->assertSame($other->id, $fresh->bed_id);
            $this->assertSame('2026-11-26', $fresh->date_to->toDateString());
        }
    }

    /** Устаревшая копия: растягивание через одну, перенос через другую проверяет новую длительность. */
    public function test_stale_copy_move_after_resize_rejected(): void
    {
        $other = Bed::factory()->create(['room_id' => $this->bed->room_id]);
        $stay = $this->stay('2026-11-24', '2026-11-26');
        $stale = Stay::query()->findOrFail($stay->id);
        $this->stay('2026-11-27', '2026-11-30', StayStatus::Booked, $other);

        (new ResizeStayAction())->run($stay, CarbonImmutable::parse('2026-11-29'));

        try {
            (new MoveStayAction())->run($stale, $other->id, $stale->date_from);
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException) {
            $fresh = $stay->fresh();
            $this->assertSame($this->bed->id, $fresh->bed_id);
            $this->assertSame('2026-11-29', $fresh->date_to->toDateString());
        }
    }

    /** Устаревшая копия выселившегося не переносится: состояние берётся из свежей записи. */
    public function test_stale_copy_of_checked_out_cannot_be_moved(): void
    {
        $stay = $this->stay('2026-11-20', '2026-11-26');
        $stale = Stay::query()->findOrFail($stay->id);

        (new CheckOutStayAction())->run($stay);

        $this->expectException(StayLockedException::class);

        (new MoveStayAction())->run($stale, $this->bed->id, CarbonImmutable::parse('2026-11-28'));
    }

    /** Удаление по устаревшей копии удаляет запись. */
    public function test_delete_with_stale_copy(): void
    {
        $other = Bed::factory()->create(['room_id' => $this->bed->room_id]);
        $stay = $this->stay('2026-11-24', '2026-11-26');
        $stale = Stay::query()->findOrFail($stay->id);

        (new MoveStayAction())->run($stay, $other->id, $stay->date_from);
        (new DeleteStayAction())->run($stale);

        $this->assertSame(0, Stay::query()->count());
    }

    /** Пересечение, возникшее со временем: просроченный 18→22 и бронь 23→26 на одной койке (прямое создание моделей).
     * @param StayStatus $status Состояние первого заселения.
     * @return Stay
     */
    private function overlapByTime(StayStatus $status = StayStatus::Living): Stay
    {
        $this->stay('2026-11-23', '2026-11-26', StayStatus::Booked);

        return $this->stay('2026-11-18', '2026-11-22', $status);
    }

    /** Правка без смены койки, дат и состояния сохраняется и в возникшем со временем пересечении. */
    public function test_update_same_occupancy_in_time_overlap_saves(): void
    {
        $stay = $this->overlapByTime();
        $dto = $this->dto('2026-11-18', '2026-11-22', StayStatus::Living, $stay->user_id);
        $dto->notes = 'Оплатит завтра';

        $result = (new UpdateStayAction())->run($stay, $dto);

        $this->assertSame('Оплатит завтра', $result->notes);
        $this->assertSame('2026-11-22', $result->date_to->toDateString());
    }

    /** Смена даты выезда в возникшем со временем пересечении проверяется как прежде. */
    public function test_update_date_change_in_time_overlap_rejected(): void
    {
        $stay = $this->overlapByTime();

        try {
            (new UpdateStayAction())->run($stay, $this->dto('2026-11-18', '2026-11-25', StayStatus::Living, $stay->user_id));
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException) {
            $this->assertSame('2026-11-22', $stay->fresh()->date_to->toDateString());
        }
    }

    /** Выселившийся или бронь в том же положении при смене состояния на «проживает» отклоняются. */
    public function test_update_to_living_in_time_overlap_position_rejected(): void
    {
        foreach ([StayStatus::CheckedOut, StayStatus::Booked] as $status) {
            Stay::query()->delete();
            $stay = $this->overlapByTime($status);

            try {
                (new UpdateStayAction())->run($stay, $this->dto('2026-11-18', '2026-11-22', StayStatus::Living, $stay->user_id));
                $this->fail('Ожидалось StayOverlapException для ' . $status->value);
            } catch (StayOverlapException) {
                $this->assertSame($status, $stay->fresh()->status);
            }
        }
    }

    /** «Выселить» в возникшем со временем пересечении обрезает выезд днём заезда брони. */
    public function test_check_out_in_time_overlap_clips(): void
    {
        $stay = $this->overlapByTime();

        $result = (new CheckOutStayAction())->run($stay);

        $this->assertSame('2026-11-23', $result->date_to->toDateString());
    }
}
