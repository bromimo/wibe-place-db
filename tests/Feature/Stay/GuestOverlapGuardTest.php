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
use App\Actions\Stay\ResizeStayAction;
use App\Actions\Stay\UpdateStayAction;
use App\Actions\Stay\CheckInStayAction;
use App\Actions\Stay\CheckOutStayAction;
use App\Exceptions\StayOverlapException;
use App\Exceptions\GuestOverlapException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Queries\Stay\FindGuestOverlappingStayQuery;

/** Тесты правила «один гость не занимает несколько коек одновременно». */
class GuestOverlapGuardTest extends TestCase
{
    use RefreshDatabase;

    private Bed $bed;

    private Bed $other;

    private User $guest;

    /** Фиксирует сегодня 24 ноября 2026, создаёт койки 1.1. и 2.1. и гостя. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 12:00');
        $this->bed = Bed::factory()->create(['room_id' => Room::factory()->create(['number' => 1, 'price' => 460])->id, 'number' => 1]);
        $this->other = Bed::factory()->create(['room_id' => Room::factory()->create(['number' => 2, 'price' => 460])->id, 'number' => 1]);
        $this->guest = User::factory()->create();
    }

    /** Создаёт заселение напрямую моделью, без проверок.
     * @param Bed $bed Койка.
     * @param string $from День заезда.
     * @param string $to День выезда.
     * @param StayStatus $status Состояние.
     * @param User|null $guest Гость; по умолчанию гость теста.
     * @return Stay
     */
    private function stay(Bed $bed, string $from, string $to, StayStatus $status = StayStatus::Living, ?User $guest = null): Stay
    {
        return Stay::factory()->create([
            'bed_id' => $bed->id,
            'user_id' => ($guest ?? $this->guest)->id,
            'date_from' => $from,
            'date_to' => $to,
            'status' => $status,
        ]);
    }

    /** DTO заселения.
     * @param Bed $bed Койка.
     * @param string $from День заезда.
     * @param string $to День выезда.
     * @param StayStatus $status Состояние.
     * @param User|null $guest Гость; по умолчанию гость теста.
     * @return StayDto
     */
    private function dto(Bed $bed, string $from, string $to, StayStatus $status = StayStatus::Living, ?User $guest = null): StayDto
    {
        return StayDto::from([
            'bed_id' => $bed->id,
            'user_id' => ($guest ?? $this->guest)->id,
            'date_from' => $from,
            'date_to' => $to,
            'status' => $status->value,
            'tariff' => 'standard',
        ]);
    }

    /** Создание на другой койке отклоняется при пересечении с бронью, проживанием и выселением гостя. */
    public function test_create_rejected_when_guest_has_overlapping_stay(): void
    {
        foreach ([StayStatus::Booked, StayStatus::Living, StayStatus::CheckedOut] as $status) {
            Stay::query()->delete();
            $this->stay($this->other, '2026-11-20', '2026-11-26', $status);

            try {
                (new CreateStayAction())->run($this->dto($this->bed, '2026-11-24', '2026-11-27'));
                $this->fail('Ожидалось GuestOverlapException для ' . $status->value);
            } catch (GuestOverlapException $e) {
                $this->assertSame('Гость уже занимает койку 2.1.: с 20.11 по 26.11', $e->getMessage());
            }

            $this->assertSame(1, Stay::query()->count());
        }
    }

    /** Другой гость в те же даты на другой койке создаётся. */
    public function test_create_other_guest_allowed(): void
    {
        $this->stay($this->other, '2026-11-20', '2026-11-26');

        (new CreateStayAction())->run($this->dto($this->bed, '2026-11-24', '2026-11-27', guest: User::factory()->create()));

        $this->assertSame(2, Stay::query()->count());
    }

    /** Переход с койки на койку в один день разрешён в обе стороны. */
    public function test_same_day_handover_between_beds_allowed(): void
    {
        $this->stay($this->other, '2026-11-20', '2026-11-24', StayStatus::CheckedOut);
        $this->stay($this->other, '2026-11-28', '2026-11-30', StayStatus::Booked);

        (new CreateStayAction())->run($this->dto($this->bed, '2026-11-24', '2026-11-28'));

        $this->assertSame(3, Stay::query()->count());
    }

    /** При двух нарушениях текст про занятую койку важнее текста про гостя. */
    public function test_bed_conflict_reported_first(): void
    {
        $this->stay($this->bed, '2026-11-23', '2026-11-26', guest: User::factory()->create());
        $this->stay($this->other, '2026-11-23', '2026-11-26');

        $this->expectException(StayOverlapException::class);

        (new CreateStayAction())->run($this->dto($this->bed, '2026-11-24', '2026-11-25'));
    }

    /** Смена гостя на того, кто в эти дни живёт на другой койке, отклоняется. */
    public function test_update_changing_guest_onto_conflicting_guest_rejected(): void
    {
        $owner = User::factory()->create();
        $stay = $this->stay($this->bed, '2026-11-24', '2026-11-27', guest: $owner);
        $this->stay($this->other, '2026-11-25', '2026-11-28', StayStatus::Booked);

        try {
            (new UpdateStayAction())->run($stay, $this->dto($this->bed, '2026-11-24', '2026-11-27'));
            $this->fail('Ожидалось GuestOverlapException');
        } catch (GuestOverlapException) {
            $this->assertSame($owner->id, $stay->fresh()->user_id);
        }
    }

    /** Смена дат, задевающая другое заселение того же гостя, отклоняется. */
    public function test_update_dates_into_own_stay_rejected(): void
    {
        $stay = $this->stay($this->bed, '2026-11-24', '2026-11-26');
        $this->stay($this->other, '2026-11-27', '2026-11-30', StayStatus::Booked);

        try {
            (new UpdateStayAction())->run($stay, $this->dto($this->bed, '2026-11-24', '2026-11-28'));
            $this->fail('Ожидалось GuestOverlapException');
        } catch (GuestOverlapException) {
            $this->assertSame('2026-11-26', $stay->fresh()->date_to->toDateString());
        }
    }

    /** Правка самого заселения не считает его пересечением с собой. */
    public function test_update_own_dates_allowed(): void
    {
        $stay = $this->stay($this->bed, '2026-11-24', '2026-11-26');

        $result = (new UpdateStayAction())->run($stay, $this->dto($this->bed, '2026-11-24', '2026-11-28'));

        $this->assertSame('2026-11-28', $result->date_to->toDateString());
    }

    /** Перенос на другую койку в даты другого заселения гостя отклоняется. */
    public function test_move_into_own_stay_rejected(): void
    {
        $third = Bed::factory()->create(['room_id' => $this->other->room_id]);
        $stay = $this->stay($this->bed, '2026-11-24', '2026-11-26');
        $this->stay($this->other, '2026-11-28', '2026-11-30', StayStatus::Booked);

        try {
            (new MoveStayAction())->run($stay, $third->id, CarbonImmutable::parse('2026-11-27'));
            $this->fail('Ожидалось GuestOverlapException');
        } catch (GuestOverlapException) {
            $fresh = $stay->fresh();
            $this->assertSame($this->bed->id, $fresh->bed_id);
            $this->assertSame('2026-11-24', $fresh->date_from->toDateString());
        }
    }

    /** Растягивание в даты другого заселения гостя отклоняется. */
    public function test_resize_into_own_stay_rejected(): void
    {
        $stay = $this->stay($this->bed, '2026-11-24', '2026-11-26');
        $this->stay($this->other, '2026-11-28', '2026-11-30', StayStatus::Booked);

        try {
            (new ResizeStayAction())->run($stay, CarbonImmutable::parse('2026-11-29'));
            $this->fail('Ожидалось GuestOverlapException');
        } catch (GuestOverlapException) {
            $this->assertSame('2026-11-26', $stay->fresh()->date_to->toDateString());
        }
    }

    /** «Заселить» старую бронь отклоняется: продление по сегодня задевает более позднее заселение гостя. */
    public function test_check_in_old_booking_into_own_later_stay_rejected(): void
    {
        $booking = $this->stay($this->bed, '2026-11-10', '2026-11-15', StayStatus::Booked);
        $this->stay($this->other, '2026-11-20', '2026-11-26', StayStatus::Booked);

        try {
            (new CheckInStayAction())->run($booking);
            $this->fail('Ожидалось GuestOverlapException');
        } catch (GuestOverlapException $e) {
            $this->assertSame('Гость уже занимает койку 2.1.: с 20.11 по 26.11', $e->getMessage());
        }

        $this->assertSame(StayStatus::Booked, $booking->fresh()->status);
    }

    /** Просроченное проживание гостя не даёт поселить его сегодня и отпускает с завтрашнего дня. */
    public function test_overdue_living_blocks_today_and_frees_tomorrow(): void
    {
        $this->stay($this->other, '2026-11-18', '2026-11-22');

        try {
            (new CreateStayAction())->run($this->dto($this->bed, '2026-11-24', '2026-11-26'));
            $this->fail('Ожидалось GuestOverlapException');
        } catch (GuestOverlapException $e) {
            $this->assertSame('Гость уже занимает койку 2.1.: с 18.11, не выселен (срок до 22.11)', $e->getMessage());
        }

        (new CreateStayAction())->run($this->dto($this->bed, '2026-11-25', '2026-11-27', StayStatus::Booked));

        $this->assertSame(2, Stay::query()->count());
    }

    /** Правка без смены койки, дат, состояния и гостя сохраняется и при уже существующем нарушении. */
    public function test_unchanged_occupancy_saves_in_existing_violation(): void
    {
        $stay = $this->stay($this->bed, '2026-11-20', '2026-11-26');
        $this->stay($this->other, '2026-11-22', '2026-11-25');
        $dto = $this->dto($this->bed, '2026-11-20', '2026-11-26');
        $dto->notes = 'Оплатит завтра';

        $result = (new UpdateStayAction())->run($stay, $dto);

        $this->assertSame('Оплатит завтра', $result->notes);
    }

    /** Смена дат в уже существующем нарушении проверяется. */
    public function test_date_change_in_existing_violation_rejected(): void
    {
        $stay = $this->stay($this->bed, '2026-11-20', '2026-11-26');
        $this->stay($this->other, '2026-11-22', '2026-11-25');

        $this->expectException(GuestOverlapException::class);

        (new UpdateStayAction())->run($stay, $this->dto($this->bed, '2026-11-20', '2026-11-27'));
    }

    /** Выселение обрезает выезд днём заезда гостя на другую койку, если он раньше следующего на этой койке. */
    public function test_check_out_clips_to_guests_own_next_stay(): void
    {
        $stay = $this->stay($this->bed, '2026-11-10', '2026-11-15');
        $this->stay($this->other, '2026-11-20', '2026-11-22', StayStatus::Booked);
        $this->stay($this->bed, '2026-11-22', '2026-11-26', StayStatus::Booked, User::factory()->create());
        $this->stay($this->other, '2026-11-18', '2026-11-19', StayStatus::Booked, User::factory()->create());

        $action = new CheckOutStayAction();
        $result = $action->run($stay);

        $this->assertSame(StayStatus::CheckedOut, $result->status);
        $this->assertSame('2026-11-20', $result->date_to->toDateString());
        $this->assertSame('2026-11-20', $action->clippedTo()?->toDateString());
    }

    /** Выборка: только заселения этого гостя, без исключённого, по полуоткрытым интервалам. */
    public function test_query_matches_only_this_guest(): void
    {
        $stay = $this->stay($this->other, '2026-11-24', '2026-11-27');
        $this->stay($this->bed, '2026-11-24', '2026-11-27', guest: User::factory()->create());
        $query = new FindGuestOverlappingStayQuery();

        $this->assertTrue($stay->is($query($this->guest->id, CarbonImmutable::parse('2026-11-26'), CarbonImmutable::parse('2026-11-28'))));
        $this->assertNull($query($this->guest->id, CarbonImmutable::parse('2026-11-27'), CarbonImmutable::parse('2026-11-28')));
        $this->assertNull($query($this->guest->id, CarbonImmutable::parse('2026-11-22'), CarbonImmutable::parse('2026-11-24')));
        $this->assertNull($query($this->guest->id, CarbonImmutable::parse('2026-11-25'), CarbonImmutable::parse('2026-11-26'), $stay->id));
    }
}
