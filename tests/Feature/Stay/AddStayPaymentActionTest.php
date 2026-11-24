<?php

namespace Tests\Feature\Stay;

use Tests\TestCase;
use App\Models\Stay;
use App\Models\Payment;
use App\Actions\Stay\AddStayPaymentAction;
use App\Exceptions\InvalidStayDataException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Тесты быстрого приёма оплаты по заселению. */
class AddStayPaymentActionTest extends TestCase
{
    use RefreshDatabase;

    /** Фиксирует сегодня 24 ноября 2026. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 12:00');
    }

    /** Оплата создаётся одна, на сегодня, без примечания. */
    public function test_creates_one_payment_today(): void
    {
        $stay = Stay::factory()->create();

        $payment = (new AddStayPaymentAction())->run($stay, 700);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame($stay->id, $payment->stay_id);
        $this->assertSame(700, $payment->refresh()->amount);
        $this->assertSame('2026-11-24', $payment->paid_at->toDateString());
        $this->assertNull($payment->note);
    }

    /** Ноль и отрицательная сумма отклоняются, оплата не создаётся. */
    public function test_rejects_non_positive_amount(): void
    {
        $stay = Stay::factory()->create();

        foreach ([0, -5] as $amount) {
            try {
                (new AddStayPaymentAction())->run($stay, $amount);

                $this->fail('Ожидалось исключение');
            } catch (InvalidStayDataException $e) {
                $this->assertSame('Сумма оплаты должна быть больше нуля', $e->getMessage());
            }
        }

        $this->assertSame(0, Payment::query()->count());
    }

    /** Удалённое заселение: исключение, оплата не создаётся. */
    public function test_missing_stay_throws(): void
    {
        $stay = Stay::factory()->create();
        Stay::query()->whereKey($stay->id)->delete();

        $this->expectException(ModelNotFoundException::class);

        try {
            (new AddStayPaymentAction())->run($stay, 100);
        } finally {
            $this->assertSame(0, Payment::query()->count());
        }
    }
}
