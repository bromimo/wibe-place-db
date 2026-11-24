<?php

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Stay;
use App\Models\User;
use App\Models\Payment;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Проверка моделей хостела: связи, касты, каскады. */
class HostelModelsTest extends TestCase
{
    use RefreshDatabase;

    /** Связи и касты работают, часовой пояс — Киев. */
    public function test_relations_and_casts(): void
    {
        $stay = Stay::factory()->create(['date_from' => '2026-11-24', 'date_to' => '2026-11-26']);
        Payment::factory()->for($stay)->create(['amount' => 300]);

        $this->assertInstanceOf(CarbonImmutable::class, $stay->date_from);
        $this->assertSame(StayStatus::Living, $stay->status);
        $this->assertSame(StayTariff::Standard, $stay->tariff);
        $this->assertTrue($stay->bed->room->beds->contains($stay->bed));
        $this->assertSame(1, $stay->user->stays()->count());
        $this->assertSame(300, (int) $stay->payments()->sum('amount'));
        $this->assertSame('Europe/Kyiv', config('app.timezone'));
    }

    /** Полное имя гостя: фамилия и имя. */
    public function test_user_full_name(): void
    {
        $user = User::factory()->create(['last_name' => 'Марченко', 'first_name' => 'Максим']);

        $this->assertSame('Марченко Максим', $user->full_name);
    }

    /** Удаление заселения удаляет его оплаты. */
    public function test_deleting_stay_removes_payments(): void
    {
        $stay = Stay::factory()->create();
        Payment::factory()->for($stay)->count(2)->create();

        $stay->delete();

        $this->assertSame(0, Payment::count());
    }
}
