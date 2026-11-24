<?php

namespace Tests\Feature\Filament;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Admin;
use Livewire\Livewire;
use App\Enums\StayStatus;
use App\Filament\Pages\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты переноса и изменения выезда заселения перетаскиванием на графике. */
class ScheduleDragTest extends TestCase
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
     * @param string $name Название койки.
     * @return Bed
     */
    private function makeBed(string $name = '1.1'): Bed
    {
        $room = Room::factory()->create(['name' => 'Комната ' . $name, 'price' => 460]);

        return Bed::factory()->create(['room_id' => $room->id, 'name' => $name]);
    }

    /** Создаёт заселение на койке и перечитывает его из БД.
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
        ])->fresh();
    }

    /** Проверяет, что запись заселения в БД не изменилась.
     * @param Stay $stay Заселение в исходном состоянии, прочитанное из БД.
     * @return void
     */
    private function assertUnchanged(Stay $stay): void
    {
        $this->assertSame($stay->getAttributes(), Stay::query()->findOrFail($stay->id)->getAttributes());
    }

    /** Перенос меняет койку и даты, длительность сохраняется. */
    public function test_move_updates_bed_and_dates(): void
    {
        $bed = $this->makeBed();
        $target = $this->makeBed('2.1');
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-27');

        Livewire::test(Schedule::class)
            ->call('moveStay', $stay->id, $target->id, '2026-11-30')
            ->assertOk()
            ->assertSeeHtml('data-date-from="2026-11-30"')
            ->assertSeeHtml('data-date-to="2026-12-03"');

        $stay->refresh();

        $this->assertSame($target->id, $stay->bed_id);
        $this->assertSame('2026-11-30', $stay->date_from->toDateString());
        $this->assertSame('2026-12-03', $stay->date_to->toDateString());
    }

    /** Перенос на занятую койку: уведомление с причиной, запись прежняя. */
    public function test_move_onto_occupied_notifies_and_keeps(): void
    {
        $bed = $this->makeBed();
        $target = $this->makeBed('2.1');
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-27');
        $other = $this->makeStay($target, '2026-11-29', '2026-12-01');

        Livewire::test(Schedule::class)
            ->call('moveStay', $stay->id, $target->id, '2026-11-30')
            ->assertOk()
            ->assertNotified("Койка занята: {$other->user->full_name} с 29.11 по 01.12");

        $this->assertUnchanged($stay);
    }

    /** Растягивание меняет только день выезда. */
    public function test_resize_updates_date_to(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-27');

        Livewire::test(Schedule::class)
            ->call('resizeStay', $stay->id, '2026-11-30')
            ->assertOk()
            ->assertSeeHtml('data-date-to="2026-11-30"');

        $stay->refresh();

        $this->assertSame($bed->id, $stay->bed_id);
        $this->assertSame('2026-11-24', $stay->date_from->toDateString());
        $this->assertSame('2026-11-30', $stay->date_to->toDateString());
    }

    /** Растягивание на соседнее заселение: уведомление, запись прежняя. */
    public function test_resize_into_neighbour_notifies_and_keeps(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-27');
        $neighbour = $this->makeStay($bed, '2026-11-28', '2026-11-30');

        Livewire::test(Schedule::class)
            ->call('resizeStay', $stay->id, '2026-11-29')
            ->assertOk()
            ->assertNotified("Койка занята: {$neighbour->user->full_name} с 28.11 по 30.11");

        $this->assertUnchanged($stay);
    }

    /** Выселившееся заселение не переносится и не растягивается. */
    public function test_checked_out_cannot_be_moved_or_resized(): void
    {
        $bed = $this->makeBed();
        $target = $this->makeBed('2.1');
        $stay = $this->makeStay($bed, '2026-11-20', '2026-11-23', StayStatus::CheckedOut);

        Livewire::test(Schedule::class)
            ->call('moveStay', $stay->id, $target->id, '2026-11-30')
            ->assertOk()
            ->assertNotified('Выселившегося гостя нельзя переносить');

        Livewire::test(Schedule::class)
            ->call('resizeStay', $stay->id, '2026-11-30')
            ->assertOk()
            ->assertNotified('Выселившегося гостя нельзя переносить');

        $this->assertUnchanged($stay);
    }

    /** Мусорные аргументы: без исключений, с уведомлением, записи прежние. */
    public function test_garbage_payload_is_harmless(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-27');

        $failed = 'Не удалось перенести заселение';
        $calls = [
            [['moveStay', 999999, 1, '2026-11-30'], $failed],
            [['moveStay', $stay->id, 999999, '2026-11-30'], $failed],
            [['moveStay', $stay->id, $bed->id, 'не-дата'], $failed],
            [['moveStay', $stay->id, $bed->id, '2026-02-30'], $failed],
            [['moveStay', 'abc', null, ['2026-11-30']], $failed],
            [['resizeStay', $stay->id, ''], $failed],
            [['resizeStay', null, 20261130], $failed],
            [['resizeStay', $stay->id, '2026-11-20'], 'День выезда должен быть позже дня заезда'],
        ];

        foreach ($calls as [$call, $message]) {
            Livewire::test(Schedule::class)
                ->call(...$call)
                ->assertOk()
                ->assertNotified($message);
        }

        $this->assertSame(1, Stay::query()->count());
        $this->assertUnchanged($stay);
    }

    /** Даты вне 2000–2100 года не доходят до БД: уведомление, запись прежняя. */
    public function test_out_of_range_dates_are_rejected(): void
    {
        $bed = $this->makeBed();
        $stay = $this->makeStay($bed, '2026-11-24', '2026-11-27');

        $calls = [
            ['moveStay', $stay->id, $bed->id, '9999-12-30'],
            ['moveStay', $stay->id, $bed->id, '1999-12-31'],
            ['resizeStay', $stay->id, '9999-12-30'],
            ['resizeStay', $stay->id, '2101-01-01'],
        ];

        foreach ($calls as $call) {
            Livewire::test(Schedule::class)
                ->call(...$call)
                ->assertOk()
                ->assertNotified('Не удалось перенести заселение');
        }

        $this->assertUnchanged($stay);
    }
}
