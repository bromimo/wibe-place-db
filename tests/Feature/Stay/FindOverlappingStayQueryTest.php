<?php

namespace Tests\Feature\Stay;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Stay;
use App\Models\User;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use App\Exceptions\StayOverlapException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Queries\Stay\FindOverlappingStayQuery;

/** Тесты проверки пересечения заселений на койке. */
class FindOverlappingStayQueryTest extends TestCase
{
    use RefreshDatabase;

    /** Фиксирует текущее время на 27 ноября 2026. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-27 12:00');
    }

    /** Создаёт заселение на койке в ноябре 2026.
     * @param int $from День заезда.
     * @param int $to День выезда.
     * @param StayStatus $status Статус.
     * @return Stay
     */
    private function makeStay(int $from = 24, int $to = 27, StayStatus $status = StayStatus::Living): Stay
    {
        return Stay::factory()->create([
            'date_from' => sprintf('2026-11-%02d', $from),
            'date_to' => sprintf('2026-11-%02d', $to),
            'status' => $status,
        ]);
    }

    /** Ищет пересечение кандидата с ноябрьскими датами.
     * @param int $bedId Койка.
     * @param int $from День заезда.
     * @param int $to День выезда.
     * @param int|null $except Исключаемое заселение.
     * @return Stay|null
     */
    private function find(int $bedId, int $from, int $to, ?int $except = null): ?Stay
    {
        return app(FindOverlappingStayQuery::class)(
            $bedId,
            CarbonImmutable::parse(sprintf('2026-11-%02d 15:30', $from)),
            CarbonImmutable::parse(sprintf('2026-11-%02d 08:00', $to)),
            $except,
        );
    }

    /** Кандидат внутри существующего заселения. */
    public function test_overlap_inside(): void
    {
        $stay = $this->makeStay();

        $this->assertTrue($stay->is($this->find($stay->bed_id, 25, 26)));
    }

    /** Кандидат пересекает начало. */
    public function test_overlap_partial_left(): void
    {
        $stay = $this->makeStay();

        $this->assertTrue($stay->is($this->find($stay->bed_id, 22, 25)));
    }

    /** Кандидат пересекает конец. */
    public function test_overlap_partial_right(): void
    {
        $stay = $this->makeStay();

        $this->assertTrue($stay->is($this->find($stay->bed_id, 26, 29)));
    }

    /** Кандидат охватывает существующее. */
    public function test_overlap_enclosing(): void
    {
        $stay = $this->makeStay();

        $this->assertTrue($stay->is($this->find($stay->bed_id, 20, 30)));
    }

    /** Кандидат заканчивается в день заезда существующего. */
    public function test_touching_before(): void
    {
        $stay = $this->makeStay();

        $this->assertNull($this->find($stay->bed_id, 22, 24));
    }

    /** Кандидат начинается в день выезда существующего. */
    public function test_touching_after(): void
    {
        $stay = $this->makeStay();

        $this->assertNull($this->find($stay->bed_id, 27, 29));
    }

    /** Другая койка не конфликтует. */
    public function test_other_bed(): void
    {
        $this->makeStay();
        $otherBed = Bed::factory()->create();

        $this->assertNull($this->find($otherBed->id, 25, 26));
    }

    /** Исключённое заселение не считается конфликтом. */
    public function test_except_self(): void
    {
        $stay = $this->makeStay();

        $this->assertNull($this->find($stay->bed_id, 25, 26, $stay->id));
    }

    /** Выселенное заселение продолжает занимать даты. */
    public function test_checked_out_still_blocks(): void
    {
        $stay = $this->makeStay(24, 27, StayStatus::CheckedOut);

        $this->assertTrue($stay->is($this->find($stay->bed_id, 25, 26)));
    }

    /** Просроченное проживающее занимает койку до сегодняшнего дня включительно. */
    public function test_overdue_living_blocks_until_today(): void
    {
        $stay = $this->makeStay(20, 25);

        $this->assertTrue($stay->is($this->find($stay->bed_id, 26, 28)));
    }

    /** Просроченное проживающее освобождает койку с завтрашнего дня. */
    public function test_overdue_living_frees_tomorrow(): void
    {
        $stay = $this->makeStay(20, 25);

        $this->assertNull($this->find($stay->bed_id, 28, 30));
    }

    /** Просроченная бронь даты не продлевает. */
    public function test_overdue_booked_does_not_extend(): void
    {
        $stay = $this->makeStay(20, 25, StayStatus::Booked);

        $this->assertNull($this->find($stay->bed_id, 26, 28));
    }

    /** Текст исключения содержит ФИО и даты конфликтующего заселения. */
    public function test_exception_message(): void
    {
        $user = User::factory()->create();
        $stay = $this->makeStay();
        $stay->update(['user_id' => $user->id]);

        $exception = new StayOverlapException($stay->refresh());

        $this->assertSame("Койка занята: {$user->full_name} с 24.11 по 27.11", $exception->getMessage());
    }

    /** Конфликт с просроченным: текст говорит, что гость не выселен и до какого дня был срок. */
    public function test_exception_message_for_overdue(): void
    {
        $user = User::factory()->create();
        $stay = $this->makeStay(20, 22);
        $stay->update(['user_id' => $user->id]);

        $exception = new StayOverlapException($stay->refresh());

        $this->assertSame("Койка занята: {$user->full_name} с 20.11, не выселен (срок до 22.11)", $exception->getMessage());
    }
}
