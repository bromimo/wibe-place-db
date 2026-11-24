<?php

namespace Tests\Feature\Filament;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Models\Admin;
use Livewire\Livewire;
use App\Models\Payment;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use App\Filament\Pages\Schedule;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты раскладки окна заселения и быстрого приёма оплаты. */
class ScheduleStayWindowTest extends TestCase
{
    use RefreshDatabase;

    /** Фиксирует текущее время на 24 ноября 2026 и входит администратором. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 12:00');

        $admin = Admin::query()->create([
            'first_name' => 'Тест',
            'last_name' => 'Админ',
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($admin, 'admin');
    }

    /** Создаёт койку в комнате с ценой 460.
     * @return Bed
     */
    private function makeBed(): Bed
    {
        $room = Room::factory()->create(['number' => 1, 'name' => '1 кімната', 'price' => 460]);

        return Bed::factory()->create(['room_id' => $room->id, 'number' => 1]);
    }

    /** Создаёт заселение гостя «Тарас Шевченко» с телефоном.
     * @param Bed $bed Койка.
     * @param string $from Дата заезда.
     * @param string $to Дата выезда.
     * @param StayStatus $status Статус.
     * @param array<string, mixed> $overrides Переопределения полей.
     * @return Stay
     */
    private function makeStay(Bed $bed, string $from, string $to, StayStatus $status = StayStatus::Living, array $overrides = []): Stay
    {
        $guest = User::factory()->create(['last_name' => 'Шевченко', 'first_name' => 'Тарас', 'phone' => '+380501112233']);

        return Stay::factory()->create(array_merge([
            'user_id' => $guest->id,
            'bed_id' => $bed->id,
            'date_from' => $from,
            'date_to' => $to,
            'status' => $status,
            'price_per_night' => 460,
        ], $overrides));
    }

    /** Открывает окно правки заселения.
     * @param Stay $stay Заселение.
     * @return Testable
     */
    private function editStay(Stay $stay): Testable
    {
        return Livewire::test(Schedule::class)->mountAction('editStay', ['stay' => $stay->id]);
    }

    /** Кнопка «Оплата» в строке быстрого приёма оплаты.
     * @return TestAction
     */
    private function takePayment(): TestAction
    {
        return TestAction::make('takePayment')->schemaComponent('quick_payment');
    }

    /** Окно правки: заголовок с номером и статусом, «Фамилия Имя», телефон, ночи, стоимость, деньги, кнопки. */
    public function test_edit_window_rows(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');
        Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 300]);

        $this->editStay($stay)
            ->assertMountedActionModalSee([
                "Заселение № {$stay->id}", 'Статус: Проживает', '+380501112233',
                'Ночей', 'Тариф', 'Стоимость', '₴ 920', '2 × ₴ 460',
                'Оплачено: ₴ 300', 'Остаток: ₴ 620', 'Принять оплату', 'Оплата', 'Выезд', 'Детали заселения',
                'Закрыть', 'Сохранить изменения', 'Удалить',
            ])
            ->assertMountedActionModalSeeHtml([
                'sch-stay-guest-name">Шевченко Тарас<', 'sch-stay-guest-phone">+380501112233<',
                'sch-stay-nights-value">2<', 'sch-stay-status-living',
            ])
            ->assertMountedActionModalDontSee('Заселить');
    }

    /** Пустой телефон показывается прочерком; бронь: «Заселить» вместо «Выезд», синий статус. */
    public function test_booking_window(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-28', '2026-11-30', StayStatus::Booked);
        $stay->user->update(['phone' => null]);

        $this->editStay($stay)
            ->assertMountedActionModalSee(['Статус: Бронь', 'Заселить', '—'])
            ->assertMountedActionModalSeeHtml('sch-stay-status-booked')
            ->assertMountedActionModalDontSee('Выезд');
    }

    /** Выселившийся: серый статус, нет ни «Выезд», ни «Заселить»; нулевой остаток серый. */
    public function test_checked_out_window(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-20', '2026-11-22', StayStatus::CheckedOut);
        Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 920]);

        $this->editStay($stay)
            ->assertMountedActionModalSee(['Статус: Выселился', 'Остаток: ₴ 0'])
            ->assertMountedActionModalSeeHtml(['sch-stay-status-checked_out', 'sch-stay-debt sch-stay-zero'])
            ->assertMountedActionModalDontSee(['Выезд', 'Заселить']);
    }

    /** Ночи и стоимость пересчитываются при смене дат; неполные или неверные даты дают прочерк. */
    public function test_nights_and_cost_follow_dates(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');

        $this->editStay($stay)
            ->setActionData(['date_to' => '2026-11-29'])
            ->assertMountedActionModalSeeHtml('sch-stay-nights-value">5<')
            ->assertMountedActionModalSee(['₴ 2300', '5 × ₴ 460', 'Остаток: ₴ 2300'])
            ->setActionData(['date_to' => '2026-11-23'])
            ->assertMountedActionModalSeeHtml('sch-stay-nights-value">—<')
            ->setActionData(['date_to' => null])
            ->assertMountedActionModalSeeHtml('sch-stay-nights-value">—<');
    }

    /** Ручной тариф: сумма вводится в поле «Стоимость», остаток считается от неё, сохраняется. */
    public function test_manual_tariff_total_editable(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26', StayStatus::Living, [
            'tariff' => StayTariff::Manual,
            'manual_total' => 1500,
        ]);

        $this->editStay($stay)
            ->assertActionDataSet(['manual_total' => 1500])
            ->assertMountedActionModalSee('Остаток: ₴ 1500')
            ->assertMountedActionModalDontSee('× ₴')
            ->setActionData(['manual_total' => 1800])
            ->assertMountedActionModalSee('Остаток: ₴ 1800')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1800, $stay->refresh()->manual_total);
    }

    /** «Оплата» записывает оплату сразу; окно открыто, правка другого поля не теряется, «Сохранить» оплату не удаляет. */
    public function test_quick_payment_keeps_window_and_edits(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');

        $component = $this->editStay($stay)
            ->setActionData(['notes' => 'Поздний выезд', 'quick_payment' => '500'])
            ->callAction($this->takePayment())
            ->assertNotified('Оплата принята: ₴ 500')
            ->assertActionMounted('editStay')
            ->assertActionDataSet(['notes' => 'Поздний выезд', 'quick_payment' => null])
            ->assertSee(['Оплачено: ₴ 500', 'Остаток: ₴ 420'])
            ->assertSeeHtml('<span class="sch-paid">500</span>');

        $payment = Payment::query()->sole();

        $this->assertSame(500, $payment->amount);
        $this->assertSame('2026-11-24', $payment->paid_at->toDateString());
        $this->assertContains($payment->id, $component->get('mountedActions.0.data.opened_payment_ids'));
        $this->assertContains($payment->id, array_column($component->get('mountedActions.0.data.payments'), 'id'));
        $this->assertNull($stay->refresh()->notes);

        $component->callMountedAction()->assertHasNoActionErrors()->assertActionNotMounted('editStay');

        $this->assertNotNull($payment->fresh());
        $this->assertSame(500, (int) $stay->payments()->sum('amount'));
        $this->assertSame('Поздний выезд', $stay->refresh()->notes);
    }

    /** Пустая, нулевая или нечисловая сумма: уведомление об ошибке, оплата не записывается, окно открыто. */
    public function test_quick_payment_rejects_non_positive(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');

        foreach (['', '0', '-100', 'abc'] as $amount) {
            $this->editStay($stay)
                ->setActionData(['quick_payment' => $amount])
                ->callAction($this->takePayment())
                ->assertNotified('Сумма оплаты должна быть больше нуля')
                ->assertActionMounted('editStay');
        }

        $this->assertSame(0, Payment::query()->count());
    }

    /** Поле быстрой оплаты не мешает сохранению и не попадает в данные заселения. */
    public function test_unsent_quick_amount_does_not_block_save(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');

        $this->editStay($stay)
            ->setActionData(['quick_payment' => '0', 'notes' => 'Правка'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('Правка', $stay->refresh()->notes);
        $this->assertSame(0, Payment::query()->count());
    }

    /** Окно создания: заголовок, ночи и стоимость; без строк денег, оплаты и деталей. */
    public function test_create_window_rows(): void
    {
        $bed = $this->makeBed();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->assertMountedActionModalSee(['Новое заселение', 'Гость', 'Койка', 'Ночей', '₴ 460', '1 × ₴ 460', 'Отмена', 'Создать'])
            ->assertMountedActionModalSeeHtml('sch-stay-nights-value">1<')
            ->assertMountedActionModalDontSee(['Оплачено', 'Принять оплату', 'Детали заселения']);
    }
}
