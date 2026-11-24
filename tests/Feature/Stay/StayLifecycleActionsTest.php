<?php

namespace Tests\Feature\Stay;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Payment;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use App\Actions\Stay\MoveStayAction;
use App\Actions\Stay\DeleteStayAction;
use App\Actions\Stay\ResizeStayAction;
use App\Actions\Stay\CheckInStayAction;
use App\Exceptions\StayLockedException;
use App\Actions\Stay\CheckOutStayAction;
use App\Exceptions\StayOverlapException;
use App\Actions\Stay\SyncStayPaymentsAction;
use App\Exceptions\InvalidStayDataException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Тесты переноса, выезда, выселения, удаления и оплат заселения. */
class StayLifecycleActionsTest extends TestCase
{
    use RefreshDatabase;

    private Bed $bed;

    /** Фиксирует сегодня 27 ноября и создаёт койку в комнате за 460. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-27 12:00');
        $this->bed = Bed::factory()->create(['room_id' => Room::factory()->create(['price' => 460])->id]);
    }

    /** Создаёт заселение на койке теста.
     * @param string $from День заезда.
     * @param string $to День выезда.
     * @param array<string, mixed> $overrides Переопределения полей.
     * @return Stay
     */
    private function stay(string $from, string $to, array $overrides = []): Stay
    {
        return Stay::factory()->create(array_merge([
            'bed_id' => $this->bed->id,
            'date_from' => $from,
            'date_to' => $to,
        ], $overrides));
    }

    /** Перенос сохраняет длительность. */
    public function test_move_keeps_length(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');

        $moved = (new MoveStayAction())->run($stay, $this->bed->id, today()->setDate(2026, 11, 30));

        $this->assertSame('2026-11-30', $moved->date_from->toDateString());
        $this->assertSame('2026-12-03', $moved->date_to->toDateString());
    }

    /** Перенос в другую комнату берёт цену новой комнаты. */
    public function test_move_to_other_room_takes_new_price(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');
        $other = Bed::factory()->create(['room_id' => Room::factory()->create(['price' => 600])->id]);

        $moved = (new MoveStayAction())->run($stay, $other->id, $stay->date_from);

        $this->assertSame($other->id, $moved->bed_id);
        $this->assertSame(600, $moved->price_per_night);
    }

    /** Перенос на занятые даты отклоняется, запись прежняя. */
    public function test_move_onto_occupied_rejected_and_unchanged(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');
        $this->stay('2026-11-30', '2026-12-03');

        try {
            (new MoveStayAction())->run($stay, $this->bed->id, today()->setDate(2026, 12, 1));
            $this->fail('Ожидалось StayOverlapException');
        } catch (StayOverlapException) {
            $fresh = $stay->fresh();
            $this->assertSame('2026-11-24', $fresh->date_from->toDateString());
            $this->assertSame('2026-11-27', $fresh->date_to->toDateString());
        }
    }

    /** Выселившегося переносить нельзя. */
    public function test_move_checked_out_rejected(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27', ['status' => StayStatus::CheckedOut]);

        $this->expectException(StayLockedException::class);
        $this->expectExceptionMessage('Выселившегося гостя нельзя переносить');

        (new MoveStayAction())->run($stay, $this->bed->id, today()->setDate(2026, 11, 30));
    }

    /** Перенос на несуществующую койку отклоняется, запись прежняя. */
    public function test_move_to_missing_bed(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');

        try {
            (new MoveStayAction())->run($stay, 999999, today()->setDate(2026, 11, 30));
            $this->fail('Ожидалось ModelNotFoundException');
        } catch (ModelNotFoundException) {
            $fresh = $stay->fresh();
            $this->assertSame($this->bed->id, $fresh->bed_id);
            $this->assertSame('2026-11-24', $fresh->date_from->toDateString());
        }
    }

    /** Продление пересчитывает ночи и стоимость. */
    public function test_resize_extends_and_recalculates(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');

        $resized = (new ResizeStayAction())->run($stay, today()->setDate(2026, 11, 30));

        $this->assertSame(6, $resized->nights());
        $this->assertSame(2760, $resized->cost());
    }

    /** Продление в соседа отклоняется. */
    public function test_resize_into_neighbour_rejected(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');
        $this->stay('2026-11-28', '2026-12-01');

        $this->expectException(StayOverlapException::class);

        (new ResizeStayAction())->run($stay, today()->setDate(2026, 11, 30));
    }

    /** Выезд не позже заезда отклоняется. */
    public function test_resize_before_start_rejected(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');

        $this->expectException(InvalidStayDataException::class);

        (new ResizeStayAction())->run($stay, today()->setDate(2026, 11, 24));
    }

    /** Выселившегося менять нельзя. */
    public function test_resize_checked_out_rejected(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27', ['status' => StayStatus::CheckedOut]);

        $this->expectException(StayLockedException::class);

        (new ResizeStayAction())->run($stay, today()->setDate(2026, 11, 30));
    }

    /** Заселение брони не меняет даты. */
    public function test_check_in(): void
    {
        $stay = $this->stay('2026-11-28', '2026-11-30', ['status' => StayStatus::Booked]);

        $result = (new CheckInStayAction())->run($stay);

        $this->assertSame(StayStatus::Living, $result->fresh()->status);
        $this->assertSame('2026-11-28', $result->fresh()->date_from->toDateString());
        $this->assertSame('2026-11-30', $result->fresh()->date_to->toDateString());
    }

    /** Ранний выезд укорачивает проживание. */
    public function test_check_out_early_shortens(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-30');

        $result = (new CheckOutStayAction())->run($stay)->fresh();

        $this->assertSame(StayStatus::CheckedOut, $result->status);
        $this->assertSame('2026-11-27', $result->date_to->toDateString());
        $this->assertSame(1380, $result->cost());
    }

    /** Выселение просроченного продлевает до сегодня. */
    public function test_check_out_overdue_extends(): void
    {
        $stay = $this->stay('2026-11-20', '2026-11-25');

        $result = (new CheckOutStayAction())->run($stay)->fresh();

        $this->assertSame('2026-11-27', $result->date_to->toDateString());
        $this->assertSame(3220, $result->cost());
    }

    /** Выселение в день заезда оставляет одну ночь. */
    public function test_check_out_on_arrival_day_keeps_one_night(): void
    {
        $stay = $this->stay('2026-11-27', '2026-11-30');

        $result = (new CheckOutStayAction())->run($stay)->fresh();

        $this->assertSame('2026-11-28', $result->date_to->toDateString());
    }

    /** Ручная сумма при выселении не меняется. */
    public function test_check_out_manual_keeps_total(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-30', [
            'tariff' => StayTariff::Manual,
            'price_per_night' => null,
            'manual_total' => 2000,
        ]);

        $result = (new CheckOutStayAction())->run($stay)->fresh();

        $this->assertSame(2000, $result->manual_total);
        $this->assertSame(2000, $result->cost());
    }

    /** Бронь выселением не затрагивается. */
    public function test_check_out_ignores_booked(): void
    {
        $stay = $this->stay('2026-11-28', '2026-11-30', ['status' => StayStatus::Booked]);

        $result = (new CheckOutStayAction())->run($stay)->fresh();

        $this->assertSame(StayStatus::Booked, $result->status);
        $this->assertSame('2026-11-30', $result->date_to->toDateString());
    }

    /** Удаление заселения удаляет его оплаты. */
    public function test_delete_removes_payments(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');
        Payment::factory()->count(2)->create(['stay_id' => $stay->id]);

        (new DeleteStayAction())->run($stay);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Stay::query()->count());
    }

    /** Синхронизация создаёт, обновляет и удаляет оплаты. */
    public function test_sync_payments_creates_updates_deletes(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');
        $a = Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 100]);
        $b = Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 200]);

        (new SyncStayPaymentsAction())->run($stay, [
            ['id' => $a->id, 'amount' => 150, 'paid_at' => '2026-11-25', 'note' => 'правка'],
            ['id' => null, 'amount' => 300, 'paid_at' => '2026-11-26', 'note' => null],
        ], [$a->id, $b->id]);

        $payments = $stay->payments()->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame($a->id, $payments[0]->id);
        $this->assertSame(150, $payments[0]->amount);
        $this->assertSame('правка', $payments[0]->note);
        $this->assertSame(300, $payments[1]->amount);
        $this->assertSame(450, $stay->fresh()->paid());
    }

    /** Неположительная сумма отклоняется, оплаты прежние. */
    public function test_sync_payments_rejects_non_positive(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');
        $a = Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 100]);

        try {
            (new SyncStayPaymentsAction())->run($stay, [
                ['id' => $a->id, 'amount' => 500, 'paid_at' => '2026-11-25', 'note' => null],
                ['id' => null, 'amount' => 0, 'paid_at' => '2026-11-26', 'note' => null],
            ], [$a->id]);
            $this->fail('Ожидалось InvalidStayDataException');
        } catch (InvalidStayDataException $e) {
            $this->assertSame('Сумма оплаты должна быть больше нуля', $e->getMessage());
            $this->assertSame(100, $a->fresh()->amount);
            $this->assertSame(1, $stay->payments()->count());
        }
    }

    /** Удаляются только оплаты, бывшие в форме при открытии; добавленная тем временем другим администратором остаётся. */
    public function test_sync_payments_keeps_payment_added_meanwhile(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');
        $opened = Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 100]);
        $removed = Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 200]);
        $meanwhile = Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 300]);

        (new SyncStayPaymentsAction())->run($stay, [
            ['id' => $opened->id, 'amount' => 100, 'paid_at' => '2026-11-25', 'note' => null],
        ], [$opened->id, $removed->id]);

        $this->assertNotNull($opened->fresh());
        $this->assertNull($removed->fresh());
        $this->assertNotNull($meanwhile->fresh());
        $this->assertSame(400, $stay->fresh()->paid());
    }

    /** Оплата другого заселения не затрагивается. */
    public function test_sync_payments_ignores_foreign_id(): void
    {
        $stay = $this->stay('2026-11-24', '2026-11-27');
        $foreign = Payment::factory()->create(['amount' => 777]);

        (new SyncStayPaymentsAction())->run($stay, [
            ['id' => $foreign->id, 'amount' => 1, 'paid_at' => '2026-11-25', 'note' => null],
        ], [$foreign->id]);

        $this->assertSame(777, $foreign->fresh()->amount);
        $this->assertSame($foreign->stay_id, $foreign->fresh()->stay_id);
        $this->assertSame(0, $stay->payments()->count());
    }
}
