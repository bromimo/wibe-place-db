<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Stay;
use App\Models\Payment;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты расчётов заселения: ночи, стоимость, долг, просрочка. */
class StayCalculationTest extends TestCase
{
    use RefreshDatabase;

    /** Фиксирует текущее время на 27 ноября 2026. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-27 12:00');
    }

    /** Создаёт заселение с оплатой.
     * @param array<string, mixed> $attributes Атрибуты заселения.
     * @param int|null $paid Сумма оплаты.
     * @return Stay
     */
    private function makeStay(array $attributes = [], ?int $paid = null): Stay
    {
        $stay = Stay::factory()->create($attributes);

        if ($paid !== null) {
            Payment::factory()->create(['stay_id' => $stay->id, 'amount' => $paid]);
        }

        return $stay;
    }

    /** Стандартный тариф: ночи * цена. */
    public function test_standard_cost(): void
    {
        $stay = $this->makeStay(['date_from' => '2026-11-24', 'date_to' => '2026-11-27', 'price_per_night' => 460], 1000);

        $this->assertSame(3, $stay->nights());
        $this->assertSame(1380, $stay->cost());
        $this->assertSame(1000, $stay->paid());
        $this->assertSame(380, $stay->debt());
    }

    /** Ручной тариф: стоимость равна manual_total. */
    public function test_manual_cost(): void
    {
        $stay = $this->makeStay([
            'tariff' => StayTariff::Manual,
            'manual_total' => 2000,
            'date_from' => '2026-11-20',
            'date_to' => '2026-11-25',
        ], 2000);

        $this->assertSame(5, $stay->nights());
        $this->assertSame(2000, $stay->cost());
        $this->assertSame(0, $stay->debt());
    }

    /** Переплата не даёт отрицательный долг. */
    public function test_overpayment_gives_zero_debt(): void
    {
        $stay = $this->makeStay([], 500);

        $this->assertSame(460, $stay->cost());
        $this->assertSame(0, $stay->debt());
    }

    /** Ручной тариф с нулевой суммой. */
    public function test_manual_zero_total(): void
    {
        $stay = $this->makeStay(['tariff' => StayTariff::Manual, 'manual_total' => 0]);

        $this->assertSame(0, $stay->cost());
        $this->assertSame(0, $stay->debt());
    }

    /** Нулевая цена комнаты. */
    public function test_zero_room_price(): void
    {
        $stay = $this->makeStay(['date_from' => '2026-11-24', 'date_to' => '2026-11-27', 'price_per_night' => 0]);

        $this->assertSame(0, $stay->cost());
    }

    /** Переход на летнее и зимнее время не искажает число ночей. */
    public function test_nights_across_dst_change(): void
    {
        $autumn = $this->makeStay(['date_from' => '2026-10-24', 'date_to' => '2026-10-26']);
        $spring = $this->makeStay(['date_from' => '2027-03-27', 'date_to' => '2027-03-29']);

        $this->assertSame(2, $autumn->nights());
        $this->assertSame(2, $spring->nights());
    }

    /** Проживающий после даты выезда просрочен. */
    public function test_overdue_living(): void
    {
        $stay = $this->makeStay(['date_from' => '2026-11-22', 'date_to' => '2026-11-25']);

        $this->assertTrue($stay->isOverdue());
        $this->assertSame('2026-11-28', $stay->effectiveDateTo()->toDateString());
    }

    /** В день выезда заселение не просрочено. */
    public function test_checkout_day_is_not_overdue(): void
    {
        $stay = $this->makeStay(['date_from' => '2026-11-22', 'date_to' => '2026-11-27']);

        $this->assertFalse($stay->isOverdue());
        $this->assertSame('2026-11-27', $stay->effectiveDateTo()->toDateString());
    }

    /** Бронь и выселенный никогда не просрочены. */
    public function test_booked_and_checked_out_never_overdue(): void
    {
        $booked = $this->makeStay(['status' => StayStatus::Booked, 'date_from' => '2026-11-22', 'date_to' => '2026-11-25']);
        $out = $this->makeStay(['status' => StayStatus::CheckedOut, 'date_from' => '2026-11-22', 'date_to' => '2026-11-25']);

        $this->assertFalse($booked->isOverdue());
        $this->assertFalse($out->isOverdue());
    }

    /** Предзагруженная сумма оплат используется без запроса. */
    public function test_paid_uses_preloaded_sum(): void
    {
        $this->makeStay([], 300);
        $stay = Stay::withSum('payments', 'amount')->first();

        DB::enableQueryLog();
        $paid = $stay->paid();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(300, $paid);
        $this->assertCount(0, $queries);
    }
}
