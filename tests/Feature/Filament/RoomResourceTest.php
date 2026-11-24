<?php

namespace Tests\Feature\Filament;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Models\Admin;
use Livewire\Livewire;
use App\Actions\Room\CreateRoomAction;
use Filament\Actions\Testing\TestAction;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты справочника комнат и запретов удаления в панели. */
class RoomResourceTest extends TestCase
{
    use RefreshDatabase;

    /** Входит в панель администратором. */
    protected function setUp(): void
    {
        parent::setUp();

        $admin = Admin::query()->create([
            'first_name' => 'Тест',
            'last_name' => 'Админ',
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($admin, 'admin');
    }

    /** Создаёт комнату с койками через действие.
     * @param int $number Номер комнаты.
     * @param int $bedCount Число коек.
     * @return Room
     */
    private function room(int $number, int $bedCount): Room
    {
        return (new CreateRoomAction())->run($number, "{$number} кімната", 460, $bedCount);
    }

    /** Список комнат открывается и показывает комнату. */
    public function test_list_renders(): void
    {
        $room = $this->room(1, 2);

        Livewire::test(ListRooms::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$room])
            ->assertSee('1 кімната');
    }

    /** Таблица по умолчанию упорядочена по номеру комнаты. */
    public function test_table_sorted_by_number(): void
    {
        $second = $this->room(2, 1);
        $third = $this->room(3, 1);
        $first = $this->room(1, 1);

        Livewire::test(ListRooms::class)
            ->assertCanSeeTableRecords([$first, $second, $third], inOrder: true)
            ->assertTableColumnExists('number')
            ->assertTableColumnExists('beds_count')
            ->assertTableColumnDoesNotExist('sort');
    }

    /** Новая комната получает следующий номер, название по номеру и одну койку. */
    public function test_create_form_defaults(): void
    {
        Livewire::test(CreateRoom::class)
            ->assertSchemaStateSet(['number' => 1, 'name' => '1 кімната', 'bed_count' => 1]);

        $this->room(4, 1);

        Livewire::test(CreateRoom::class)
            ->assertSchemaStateSet(['number' => 5, 'name' => '5 кімната']);
    }

    /** Название следует за номером, пока его не изменили вручную. */
    public function test_create_name_follows_number_until_edited(): void
    {
        Livewire::test(CreateRoom::class)
            ->fillForm(['number' => 7])
            ->assertSchemaStateSet(['name' => '7 кімната'])
            ->fillForm(['name' => 'Люкс'])
            ->fillForm(['number' => 8])
            ->assertSchemaStateSet(['name' => 'Люкс']);
    }

    /** Комната создаётся с заданным числом коек. */
    public function test_create_room_with_bed_count(): void
    {
        Livewire::test(CreateRoom::class)
            ->fillForm(['number' => 3, 'name' => '3 кімната', 'price' => 460, 'bed_count' => 3])
            ->call('create')
            ->assertHasNoFormErrors();

        $room = Room::query()->where('number', 3)->firstOrFail();

        $this->assertSame('3 кімната', $room->name);
        $this->assertSame(460, (int) $room->price);
        $this->assertSame(['3.1.', '3.2.', '3.3.'], $room->beds->pluck('name')->all());
    }

    /** Номер комнаты уникален; при правке свой номер не мешает. */
    public function test_number_must_be_unique(): void
    {
        $room = $this->room(3, 1);

        Livewire::test(CreateRoom::class)
            ->fillForm(['number' => 3, 'name' => 'Другая', 'price' => 460, 'bed_count' => 1])
            ->call('create')
            ->assertHasFormErrors(['number' => 'unique']);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->fillForm(['name' => 'Люкс'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('rooms', 1);
        $this->assertSame('Люкс', $room->fresh()->name);
    }

    /** Цена — неотрицательное целое. */
    public function test_price_must_be_non_negative_integer(): void
    {
        Livewire::test(CreateRoom::class)
            ->fillForm(['price' => -1])
            ->call('create')
            ->assertHasFormErrors(['price']);

        Livewire::test(CreateRoom::class)
            ->fillForm(['price' => 10.5])
            ->call('create')
            ->assertHasFormErrors(['price']);

        $this->assertDatabaseCount('rooms', 0);
    }

    /** Номер — целое от единицы, число коек — от 0 до 50. */
    public function test_number_and_bed_count_bounds(): void
    {
        Livewire::test(CreateRoom::class)
            ->fillForm(['number' => 0, 'price' => 460, 'bed_count' => 51])
            ->call('create')
            ->assertHasFormErrors(['number', 'bed_count']);

        Livewire::test(CreateRoom::class)
            ->fillForm(['price' => 460, 'bed_count' => -1])
            ->call('create')
            ->assertHasFormErrors(['bed_count']);

        $this->assertDatabaseCount('rooms', 0);
    }

    /** Правка показывает число коек и их названия; увеличение добавляет койки, название не меняется за номером. */
    public function test_edit_shows_beds_and_grows_count(): void
    {
        $room = $this->room(3, 2);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->assertSchemaStateSet(['number' => 3, 'name' => '3 кімната', 'bed_count' => 2])
            ->assertSee('Койки: 3.1., 3.2.')
            ->fillForm(['number' => 4, 'bed_count' => 3])
            ->assertSchemaStateSet(['name' => '3 кімната'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['4.1.', '4.2.', '4.3.'], $room->beds()->pluck('name')->all());
    }

    /** Уменьшение числа коек с занятой удаляемой койкой показывает уведомление, ничего не меняется. */
    public function test_shrink_with_stays_shows_notification_and_keeps_beds(): void
    {
        $room = $this->room(3, 3);
        Stay::factory()->create(['bed_id' => $room->beds()->get()[2]->id]);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->fillForm(['name' => 'Люкс', 'bed_count' => 1])
            ->call('save')
            ->assertNotified('Нельзя уменьшить число коек: на койке 3.3. есть заселения')
            ->assertSchemaStateSet(['name' => 'Люкс', 'bed_count' => 1]);

        $this->assertSame('3 кімната', $room->fresh()->name);
        $this->assertSame(['3.1.', '3.2.', '3.3.'], $room->beds()->pluck('name')->all());
    }

    /** Комната без заселений удаляется вместе с койками. */
    public function test_delete_room_without_stays_deletes_beds(): void
    {
        $room = $this->room(3, 2);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $this->assertDatabaseCount('beds', 0);
    }

    /** Удаление комнаты с заселениями показывает уведомление, комната и койки остаются. */
    public function test_delete_room_with_stays_shows_notification_and_keeps_record(): void
    {
        $room = $this->room(3, 2);
        Stay::factory()->create(['bed_id' => $room->beds()->first()->id]);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('На койке есть заселения');

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseCount('beds', 2);
    }

    /** Массовое удаление комнат пропускает комнату с заселениями. */
    public function test_bulk_delete_rooms_skips_room_with_stays(): void
    {
        $busy = $this->room(1, 1);
        $free = $this->room(2, 2);
        Stay::factory()->create(['bed_id' => $busy->beds()->first()->id]);

        Livewire::test(ListRooms::class)
            ->selectTableRecords([$busy, $free])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertDatabaseHas('rooms', ['id' => $busy->id]);
        $this->assertDatabaseMissing('rooms', ['id' => $free->id]);
        $this->assertSame(1, Bed::query()->count());
    }

    /** Удаление клиента с заселениями показывает уведомление, клиент остаётся. */
    public function test_delete_user_with_stays_shows_notification_and_keeps_record(): void
    {
        $user = User::factory()->create();
        Stay::factory()->create(['user_id' => $user->id]);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('У клиента есть заселения');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /** Массовое удаление пропускает клиента с заселениями. */
    public function test_bulk_delete_users_skips_user_with_stays(): void
    {
        $busy = User::factory()->create();
        $free = User::factory()->create();
        Stay::factory()->create(['user_id' => $busy->id]);

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$busy, $free])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $notifications = new Notifications();
        $notifications->mount();
        $notification = $notifications->notifications->first();

        $this->assertNotNull($notification, 'Уведомление не отправлено');
        $this->assertSame('danger', $notification->getStatus());
        $this->assertStringContainsString('У клиента есть заселения', (string) $notification->getBody());
        $this->assertDatabaseHas('users', ['id' => $busy->id]);
        $this->assertDatabaseMissing('users', ['id' => $free->id]);
    }
}
