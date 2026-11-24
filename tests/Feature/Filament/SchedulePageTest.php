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
use App\Filament\Pages\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты страницы графика заселения. */
class SchedulePageTest extends TestCase
{
    use RefreshDatabase;

    /** Фиксирует текущее время на 24 ноября 2026. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 12:00');
    }

    /** Входит в панель администратором.
     * @return void
     */
    private function login(): void
    {
        $admin = Admin::query()->create([
            'first_name' => 'Тест',
            'last_name' => 'Админ',
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($admin, 'admin');
    }

    /** Создаёт койку «1.1.» в комнате «1 кімната» с ценой 460.
     * @return Bed
     */
    private function makeBed(): Bed
    {
        $room = Room::factory()->create(['number' => 1, 'name' => '1 кімната', 'price' => 460]);

        return Bed::factory()->create(['room_id' => $room->id, 'number' => 1]);
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

    /** Гостя перенаправляет на вход. */
    public function test_page_requires_admin(): void
    {
        $this->get(Schedule::getUrl())->assertRedirect(route('filament.admin.auth.login'));
    }

    /** Видны комната, койка, гость, оплачено и долг. */
    public function test_page_renders_rooms_beds_and_guest(): void
    {
        $this->login();
        $bed = $this->makeBed();
        $user = User::factory()->create(['last_name' => 'Марченко', 'first_name' => 'Максим']);
        $stay = Stay::factory()->create([
            'bed_id' => $bed->id,
            'user_id' => $user->id,
            'date_from' => '2026-11-24',
            'date_to' => '2026-11-27',
            'status' => StayStatus::Living,
            'price_per_night' => 460,
        ]);
        Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 1000]);

        $this->get(Schedule::getUrl())
            ->assertOk()
            ->assertSee('1 кімната')
            ->assertSee('1.1.')
            ->assertSee('Марченко Максим')
            ->assertSee('1000')
            ->assertSee('380');
    }

    /** По умолчанию окно начинается со вчерашнего дня и длится 31 день. */
    public function test_default_window_starts_yesterday(): void
    {
        $this->login();
        $this->makeBed();

        Livewire::test(Schedule::class)
            ->assertSet('date', '2026-11-24')
            ->assertSeeHtml('data-date="2026-11-23"')
            ->assertSeeHtml('data-date="2026-12-23"')
            ->assertDontSeeHtml('data-date="2026-12-24"');
    }

    /** Сдвиг даты кнопками и возврат к сегодняшнему дню. */
    public function test_shift_and_today(): void
    {
        $this->login();

        Livewire::test(Schedule::class)
            ->call('shift', 7)
            ->assertSet('date', '2026-12-01')
            ->call('shift', -31)
            ->assertSet('date', '2026-10-31')
            ->call('goToday')
            ->assertSet('date', '2026-11-24');
    }

    /** Мусор в shift() из браузера не роняет страницу и не меняет дату; строка-число принимается. */
    public function test_shift_garbage_is_harmless(): void
    {
        $this->login();

        Livewire::test(Schedule::class)
            ->call('shift', 'abc')
            ->assertOk()
            ->assertSet('date', '2026-11-24')
            ->call('shift', ['x'])
            ->assertSet('date', '2026-11-24')
            ->call('shift', null)
            ->assertSet('date', '2026-11-24')
            ->call('shift', 1.5)
            ->assertSet('date', '2026-11-24')
            ->call('shift', PHP_INT_MAX)
            ->assertOk()
            ->assertSet('date', '2026-11-24')
            ->call('shift', '-7')
            ->assertSet('date', '2026-11-17');
    }

    /** Атрибуты полос для перетаскивания. */
    public function test_bar_data_attributes(): void
    {
        $this->login();
        $bed = $this->makeBed();
        $living = $this->makeStay($bed, '2026-11-24', '2026-11-27');
        $gone = $this->makeStay($bed, '2026-11-20', '2026-11-23', StayStatus::CheckedOut);

        Livewire::test(Schedule::class)
            ->assertSeeHtml('data-stay-id="' . $living->id . '"')
            ->assertSeeHtml('data-date-from="2026-11-24"')
            ->assertSeeHtml('data-date-to="2026-11-27"')
            ->assertSeeHtml('data-draggable="1"')
            ->assertSeeHtml('data-stay-id="' . $gone->id . '"')
            ->assertSeeHtml('data-draggable="0"')
            ->assertSeeHtml('data-resize-handle')
            ->assertSeeHtml('data-bed-id="' . $bed->id . '"');
    }

    /** У просроченного заселения рисуется хвост просрочки. */
    public function test_overdue_tail_rendered(): void
    {
        $this->login();
        $bed = $this->makeBed();
        $this->makeStay($bed, '2026-11-20', '2026-11-22');

        Livewire::test(Schedule::class)->assertSeeHtml('sch-overdue');
    }

    /** Просроченное, закончившееся до окна: хвост подписан гостем и долгом и открывает заселение. */
    public function test_overdue_before_window_tail_identifies_stay(): void
    {
        $this->login();
        $bed = $this->makeBed();
        $user = User::factory()->create(['last_name' => 'Безуглый', 'first_name' => 'Максим']);
        $stay = Stay::factory()->create([
            'bed_id' => $bed->id,
            'user_id' => $user->id,
            'date_from' => '2026-11-18',
            'date_to' => '2026-11-22',
            'status' => StayStatus::Living,
            'price_per_night' => 460,
        ]);
        Payment::factory()->create(['stay_id' => $stay->id, 'amount' => 1000]);

        $html = Livewire::test(Schedule::class)->html();

        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="sch-overdue[^"]*"[^>]*data-stay-id="' . $stay->id . '"[^>]*data-draggable="0"/s',
            $html,
        );
        $this->assertStringContainsString('Безуглый Максим', $html);
        $this->assertStringContainsString('<span class="sch-debt">840</span>', $html);
        $this->assertStringNotContainsString('wire:key="stay-' . $stay->id . '"', $html);
    }

    /** Просроченное, выехавшее вчера (первый день окна): обычная полоса прежняя, хвост тоже открывает заселение, но без подписи. */
    public function test_overdue_inside_window_keeps_bar_contract(): void
    {
        $this->login();
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-20', '2026-11-25');
        $this->travelTo('2026-11-26 12:00');

        $html = Livewire::test(Schedule::class)->html();

        $this->assertStringContainsString('wire:key="stay-' . $stay->id . '"', $html);
        $this->assertStringContainsString('wire:key="overdue-' . $stay->id . '"', $html);
        $this->assertSame(2, substr_count($html, 'data-stay-id="' . $stay->id . '"'));
        $this->assertSame(1, substr_count($html, 'class="sch-guest"'));
    }

    /** Без комнат — подсказка со ссылкой на справочник. */
    public function test_empty_hostel_shows_hint(): void
    {
        $this->login();

        $this->get(Schedule::getUrl())
            ->assertOk()
            ->assertSee('Добавьте комнаты и койки в разделе „Комнаты“');
    }

    /** Комната без коек показывает строку-заглушку. */
    public function test_room_without_beds_renders(): void
    {
        $this->login();
        Room::factory()->create(['name' => 'Пустая']);

        $this->get(Schedule::getUrl())
            ->assertOk()
            ->assertSee('В комнате нет коек');
    }

    /** Некорректная дата сбрасывается на сегодня. */
    public function test_invalid_date_falls_back_to_today(): void
    {
        $this->login();

        Livewire::test(Schedule::class)
            ->set('date', 'мусор')
            ->assertOk()
            ->assertSet('date', '2026-11-24')
            ->set('date', '2026-02-30')
            ->assertSet('date', '2026-11-24');
    }
}
