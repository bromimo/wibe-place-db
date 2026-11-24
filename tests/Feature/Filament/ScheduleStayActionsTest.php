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
use App\Filament\Schemas\StayForm;
use Filament\Forms\Components\Repeater;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты окон создания и правки заселения на графике. */
class ScheduleStayActionsTest extends TestCase
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

    /** Создаёт койку «1.1» в комнате «1 комната» с ценой 460.
     * @return Bed
     */
    private function makeBed(): Bed
    {
        $room = Room::factory()->create(['name' => '1 комната', 'price' => 460]);

        return Bed::factory()->create(['room_id' => $room->id, 'name' => '1.1']);
    }

    /** Создаёт заселение на койке.
     * @param Bed $bed Койка.
     * @param string $from Дата заезда.
     * @param string $to Дата выезда.
     * @param StayStatus $status Статус.
     * @return Stay
     */
    private function makeStay(Bed $bed, string $from, string $to, StayStatus $status = StayStatus::Living): Stay
    {
        return Stay::factory()->create([
            'bed_id' => $bed->id,
            'date_from' => $from,
            'date_to' => $to,
            'status' => $status,
            'price_per_night' => 460,
        ]);
    }

    /** Открывает окно правки заселения.
     * @param Stay $stay Заселение.
     * @return Testable
     */
    private function editStay(Stay $stay): Testable
    {
        return Livewire::test(Schedule::class)->mountAction('editStay', ['stay' => $stay->id]);
    }

    /** Действие подвала окна правки.
     * @param Stay $stay Заселение.
     * @param string $name Имя действия.
     * @return array<int, TestAction|string>
     */
    private function footerAction(Stay $stay, string $name): array
    {
        return [TestAction::make('editStay')->arguments(['stay' => $stay->id]), $name];
    }

    /** Заезд сегодня: выезд завтра, состояние «проживает», тариф «наш». */
    public function test_create_defaults_for_today(): void
    {
        $bed = $this->makeBed();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->assertActionMounted('createStay')
            ->assertActionDataSet([
                'bed_id' => $bed->id,
                'date_from' => '2026-11-24',
                'date_to' => '2026-11-25',
                'status' => 'living',
                'tariff' => 'standard',
            ])
            ->assertMountedActionModalSee('Стоимость: 1 × 460 = 460');
    }

    /** Заезд в будущем создаётся бронью. */
    public function test_create_defaults_for_future_are_booked(): void
    {
        $bed = $this->makeBed();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-30'])
            ->assertActionDataSet(['date_to' => '2026-12-01', 'status' => 'booked']);
    }

    /** Несуществующая койка или кривая дата: уведомление, окно не открывается. */
    public function test_create_with_invalid_arguments_does_not_crash(): void
    {
        $bed = $this->makeBed();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => 999999, 'date' => '2026-11-24'])
            ->assertOk()
            ->assertActionNotMounted('createStay')
            ->assertNotified('Не удалось открыть форму заселения');

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-13-45'])
            ->assertOk()
            ->assertActionNotMounted('createStay')
            ->assertNotified('Не удалось открыть форму заселения');
    }

    /** Сохранение создаёт заселение с ценой комнаты и показывает его на сетке. */
    public function test_create_stay_saves(): void
    {
        $bed = $this->makeBed();
        $guest = User::factory()->create();

        $component = Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->setActionData(['user_id' => $guest->id, 'date_to' => '2026-11-27'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionNotMounted('createStay');

        $this->assertSame(1, Stay::query()->count());

        $stay = Stay::query()->firstOrFail();

        $this->assertSame(460, $stay->price_per_night);
        $this->assertSame($guest->id, $stay->user_id);
        $this->assertSame('2026-11-27', $stay->date_to->toDateString());
        $this->assertSame(StayStatus::Living, $stay->status);

        $component->assertSeeHtml('data-stay-id="' . $stay->id . '"');
    }

    /** Ручной тариф без суммы не проходит валидацию. */
    public function test_create_manual_requires_total(): void
    {
        $bed = $this->makeBed();
        $guest = User::factory()->create();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->setActionData(['user_id' => $guest->id, 'tariff' => StayTariff::Manual->value])
            ->callMountedAction()
            ->assertHasActionErrors(['manual_total' => 'required']);

        $this->assertSame(0, Stay::query()->count());
    }

    /** Ручной тариф с суммой 0 принимается формой и сохраняется как 0. */
    public function test_create_manual_zero_total_via_form(): void
    {
        $bed = $this->makeBed();
        $guest = User::factory()->create();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->setActionData(['user_id' => $guest->id, 'tariff' => StayTariff::Manual->value, 'manual_total' => '0'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionNotMounted('createStay');

        $stay = Stay::query()->firstOrFail();

        $this->assertSame(StayTariff::Manual, $stay->tariff);
        $this->assertSame(0, $stay->manual_total);
        $this->assertSame(0, $stay->cost());
    }

    /** Даты формы ограничены годами 2000–2100: опечатка в годе даёт ошибку поля, а не «вечную» просрочку. */
    public function test_create_dates_outside_years_rejected(): void
    {
        $bed = $this->makeBed();
        $guest = User::factory()->create();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->setActionData(['user_id' => $guest->id, 'date_from' => '0202-11-24', 'date_to' => '2101-01-01'])
            ->callMountedAction()
            ->assertHasActionErrors(['date_from', 'date_to'])
            ->assertActionMounted('createStay');

        $this->assertSame(0, Stay::query()->count());
    }

    /** Подделанные гость и койка дают ошибки полей, а не 500/404. */
    public function test_forged_guest_and_bed_rejected(): void
    {
        $bed = $this->makeBed();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->setActionData(['user_id' => 999999, 'bed_id' => 999999])
            ->callMountedAction()
            ->assertHasActionErrors(['user_id', 'bed_id'])
            ->assertActionMounted('createStay');

        $this->assertSame(0, Stay::query()->count());
    }

    /** Строка стоимости: цена заселения в той же комнате, цена новой комнаты при смене, ночи через переход времени. */
    public function test_cost_line_uses_price_rule_and_nights(): void
    {
        $bed = $this->makeBed();
        $other = Bed::factory()->create(['room_id' => Room::factory()->create(['price' => 600])->id]);
        $stay = Stay::factory()->create([
            'bed_id' => $bed->id,
            'date_from' => '2026-10-24',
            'date_to' => '2026-10-26',
            'status' => StayStatus::CheckedOut,
            'price_per_night' => 400,
        ]);

        $this->editStay($stay)
            ->assertMountedActionModalSee('Стоимость: 2 × 400 = 800')
            ->setActionData(['bed_id' => $other->id])
            ->assertMountedActionModalSee('Стоимость: 2 × 600 = 1200');
    }

    /** Поиск гостя находит по строке «Фамилия Имя» и по части телефона. */
    public function test_guest_search_matches_full_name(): void
    {
        $guest = User::factory()->create(['last_name' => 'Марченко', 'first_name' => 'Максим', 'phone' => '+380501112233']);
        User::factory()->create(['last_name' => 'Марченко', 'first_name' => 'Ольга', 'phone' => '+380671234567']);

        $this->assertSame([$guest->id => 'Марченко Максим'], StayForm::searchGuests('Марченко Максим'));
        $this->assertSame([$guest->id => 'Марченко Максим'], StayForm::searchGuests('  Максим   Марченко '));
        $this->assertSame([$guest->id => 'Марченко Максим'], StayForm::searchGuests('Марченко 1112'));
        $this->assertCount(2, StayForm::searchGuests('Марченко'));
    }

    /** Пересечение: уведомление с причиной, окно остаётся открытым с данными. */
    public function test_create_overlap_shows_notification_and_keeps_modal(): void
    {
        $bed = $this->makeBed();
        $existing = $this->makeStay($bed, '2026-11-23', '2026-11-26');
        $guest = User::factory()->create();

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->setActionData(['user_id' => $guest->id, 'notes' => 'Поздний заезд'])
            ->callMountedAction()
            ->assertNotified("Койка занята: {$existing->user->full_name} с 23.11 по 26.11")
            ->assertActionMounted('createStay')
            ->assertActionDataSet(['user_id' => $guest->id, 'notes' => 'Поздний заезд']);

        $this->assertSame(1, Stay::query()->count());
    }

    /** Новый клиент создаётся прямо в окне и подставляется в поле гостя. */
    public function test_create_with_new_guest(): void
    {
        $bed = $this->makeBed();

        $component = Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->mountAction(TestAction::make('createOption')->schemaComponent('user_id'))
            ->setActionData(['last_name' => 'Петренко', 'first_name' => 'Олег', 'phone' => '+380501112233'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $guest = User::query()->where('last_name', 'Петренко')->firstOrFail();

        $component
            ->assertActionDataSet(['user_id' => $guest->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($guest->id, Stay::query()->firstOrFail()->user_id);
    }

    /** Окно правки заполнено данными заселения; смена выезда сохраняется. */
    public function test_edit_prefills_and_saves(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');

        $this->editStay($stay)
            ->assertActionMounted('editStay')
            ->assertActionDataSet([
                'user_id' => $stay->user_id,
                'bed_id' => $bed->id,
                'date_from' => '2026-11-24',
                'date_to' => '2026-11-26',
                'status' => 'living',
                'tariff' => 'standard',
            ])
            ->assertMountedActionModalSee([$stay->user->full_name, 'Стоимость: 2 × 460 = 920', 'Оплачено: 0 / Долг: 920'])
            ->setActionData(['date_to' => '2026-11-28'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionNotMounted('editStay')
            ->assertSeeHtml('data-date-to="2026-11-28"');

        $this->assertSame('2026-11-28', $stay->refresh()->date_to->toDateString());
    }

    /** Пересечение при правке: уведомление, окно открыто, заселение и оплаты не меняются. */
    public function test_edit_overlap_keeps_modal_and_rolls_back(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');
        $this->makeStay($bed, '2026-11-27', '2026-11-29');
        Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 300]);

        $this->editStay($stay)
            ->setActionData(['date_to' => '2026-11-28', 'notes' => 'Продлить'])
            ->callMountedAction()
            ->assertNotified()
            ->assertActionMounted('editStay')
            ->assertActionDataSet(['date_to' => '2026-11-28', 'notes' => 'Продлить']);

        $stay->refresh();

        $this->assertSame('2026-11-26', $stay->date_to->toDateString());
        $this->assertNull($stay->notes);
        $this->assertSame(300, (int) $stay->payments()->sum('amount'));
    }

    /** Клетка койки открывает новое заселение; нажатия на полосу обрабатывает компонент перетаскивания. */
    public function test_cells_and_bars_mount_actions(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');

        Livewire::test(Schedule::class)
            ->assertSeeHtml("mountAction('createStay', { bed_id: {$bed->id}, date: '2026-11-25' })")
            ->assertSeeHtml('x-data="scheduleDrag"')
            ->assertSeeHtml('data-stay-id="' . $stay->id . '"')
            ->assertDontSeeHtml('wire:click.stop');
    }

    /** Оплаты: добавленная строка создаётся, убранная удаляется. */
    public function test_edit_adds_and_removes_payments(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');
        Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 300]);

        $undoFake = Repeater::fake();

        $this->editStay($stay)
            ->callAction(TestAction::make('add')->schemaComponent('payments'))
            ->assertActionDataSet(['payments.1.paid_at' => '2026-11-24'])
            ->setActionData(['payments.1.amount' => 500])
            ->callAction(TestAction::make('delete')->schemaComponent('payments')->arguments(['item' => 0]))
            ->assertActionMounted('editStay')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $undoFake();

        $payments = Payment::query()->get();

        $this->assertCount(1, $payments);
        $this->assertSame(500, $payments->first()->amount);
        $this->assertSame($stay->id, $payments->first()->stay_id);
    }

    /** Оплата, внесённая другим администратором при открытом окне, после сохранения остаётся. */
    public function test_edit_keeps_payment_added_meanwhile(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');
        $seen = Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 300]);

        $component = $this->editStay($stay)->setActionData(['notes' => 'Правка']);

        $meanwhile = Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 500]);

        $component->callMountedAction()->assertHasNoActionErrors();

        $this->assertNotNull($seen->fresh());
        $this->assertNotNull($meanwhile->fresh());
        $this->assertSame('Правка', $stay->refresh()->notes);
    }

    /** После замены оплаты в окне правки полоса показывает новую сумму оплаты и долг. */
    public function test_edit_payments_refresh_bar_amounts(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-27');
        Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 1000]);

        $undoFake = Repeater::fake();

        $this->editStay($stay)
            ->assertSeeHtml('<span class="sch-paid">1000</span>')
            ->assertSeeHtml('<span class="sch-debt">380</span>')
            ->callAction(TestAction::make('add')->schemaComponent('payments'))
            ->setActionData(['payments.1.amount' => 1380])
            ->callAction(TestAction::make('delete')->schemaComponent('payments')->arguments(['item' => 0]))
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSeeHtml('<span class="sch-paid">1380</span>')
            ->assertDontSeeHtml('class="sch-debt"');

        $undoFake();

        $this->assertSame(1380, (int) $stay->payments()->sum('amount'));
    }

    /** «Заселить» переводит бронь в проживание; у проживающего кнопки нет. */
    public function test_check_in_button(): void
    {
        $bed = $this->makeBed();
        $booked = $this->makeStay($bed, '2026-11-24', '2026-11-26', StayStatus::Booked);

        Livewire::test(Schedule::class)
            ->callAction($this->footerAction($booked, 'checkIn'))
            ->assertActionNotMounted('editStay');

        $this->assertSame(StayStatus::Living, $booked->refresh()->status);

        Livewire::test(Schedule::class)
            ->assertActionHidden($this->footerAction($booked, 'checkIn'))
            ->assertActionVisible($this->footerAction($booked, 'checkOut'));
    }

    /** «Выселить» ставит выезд на сегодня; у брони кнопки нет. */
    public function test_check_out_button(): void
    {
        $bed = $this->makeBed();
        $living = $this->makeStay($bed, '2026-11-20', '2026-11-30');
        $booked = $this->makeStay($bed, '2026-12-05', '2026-12-07', StayStatus::Booked);

        Livewire::test(Schedule::class)
            ->callAction($this->footerAction($living, 'checkOut'))
            ->assertActionNotMounted('editStay');

        $living->refresh();

        $this->assertSame(StayStatus::CheckedOut, $living->status);
        $this->assertSame('2026-11-24', $living->date_to->toDateString());

        Livewire::test(Schedule::class)
            ->assertActionHidden($this->footerAction($booked, 'checkOut'));
    }

    /** «Заселить» при конфликте: уведомление с причиной, окно правки открыто, бронь не меняется. */
    public function test_check_in_conflict_notifies_and_keeps_modal(): void
    {
        $bed = $this->makeBed();
        $booked = $this->makeStay($bed, '2026-11-10', '2026-11-15', StayStatus::Booked);
        $next = $this->makeStay($bed, '2026-11-20', '2026-11-26');

        Livewire::test(Schedule::class)
            ->callAction($this->footerAction($booked, 'checkIn'))
            ->assertNotified("Койка занята: {$next->user->full_name} с 20.11 по 26.11")
            ->assertActionMounted('editStay');

        $this->assertSame(StayStatus::Booked, $booked->refresh()->status);
    }

    /** Правка выселившегося в «проживает» задним числом: продление по сегодня задевает соседа — отказ. */
    public function test_edit_checked_out_to_living_conflict_rejected(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-10', '2026-11-15', StayStatus::CheckedOut);
        $next = $this->makeStay($bed, '2026-11-20', '2026-11-26');

        $this->editStay($stay)
            ->setActionData(['status' => StayStatus::Living->value])
            ->callMountedAction()
            ->assertNotified("Койка занята: {$next->user->full_name} с 20.11 по 26.11")
            ->assertActionMounted('editStay');

        $this->assertSame(StayStatus::CheckedOut, $stay->refresh()->status);
    }

    /** «Выселить» просроченного при следующем госте: выезд обрезан, показано предупреждение. */
    public function test_check_out_clipped_warns(): void
    {
        $bed = $this->makeBed();
        $overdue = $this->makeStay($bed, '2026-11-10', '2026-11-15');
        $this->makeStay($bed, '2026-11-20', '2026-11-26', StayStatus::Booked);

        Livewire::test(Schedule::class)
            ->callAction($this->footerAction($overdue, 'checkOut'))
            ->assertNotified('Выезд ограничен датой заезда следующего гостя: 20.11')
            ->assertActionNotMounted('editStay');

        $overdue->refresh();

        $this->assertSame(StayStatus::CheckedOut, $overdue->status);
        $this->assertSame('2026-11-20', $overdue->date_to->toDateString());
    }

    /** «Удалить» убирает заселение вместе с оплатами. */
    public function test_delete_button(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-26');
        Payment::factory()->create(['stay_id' => $stay->id]);

        Livewire::test(Schedule::class)
            ->callAction($this->footerAction($stay, 'delete'))
            ->assertActionNotMounted('editStay')
            ->assertDontSeeHtml('data-stay-id="' . $stay->id . '"');

        $this->assertSame(0, Stay::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    /** Несуществующее заселение: уведомление, окно не открывается. */
    public function test_edit_missing_stay_does_not_crash(): void
    {
        Livewire::test(Schedule::class)
            ->mountAction('editStay', ['stay' => 999999])
            ->assertOk()
            ->assertActionNotMounted('editStay')
            ->assertNotified('Заселение не найдено');
    }
}
