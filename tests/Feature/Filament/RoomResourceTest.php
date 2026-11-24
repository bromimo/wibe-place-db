<?php

namespace Tests\Feature\Filament;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Models\Admin;
use Livewire\Livewire;
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

    /** Список комнат открывается и показывает комнату. */
    public function test_list_renders(): void
    {
        $room = Room::factory()->create(['name' => '1 комната']);

        Livewire::test(ListRooms::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$room])
            ->assertSee('1 комната');
    }

    /** Комната создаётся вместе с койками. */
    public function test_create_room_with_beds(): void
    {
        Livewire::test(CreateRoom::class)
            ->fillForm([
                'name' => '1 комната',
                'price' => 460,
                'sort' => 1,
                'beds' => [
                    ['name' => '1.1', 'sort' => 1],
                    ['name' => '1.2', 'sort' => 2],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $room = Room::query()->where('name', '1 комната')->firstOrFail();

        $this->assertSame(460, (int) $room->price);
        $this->assertSame(['1.1', '1.2'], $room->beds->pluck('name')->all());
    }

    /** Цена — неотрицательное целое. */
    public function test_price_must_be_non_negative_integer(): void
    {
        Livewire::test(CreateRoom::class)
            ->fillForm(['name' => 'Комната', 'price' => -1, 'beds' => []])
            ->call('create')
            ->assertHasFormErrors(['price']);

        Livewire::test(CreateRoom::class)
            ->fillForm(['name' => 'Комната', 'price' => 10.5, 'beds' => []])
            ->call('create')
            ->assertHasFormErrors(['price']);

        $this->assertDatabaseCount('rooms', 0);
    }

    /** Удаление комнаты с койками показывает уведомление, комната остаётся. */
    public function test_delete_room_with_beds_shows_notification_and_keeps_record(): void
    {
        $room = Room::factory()->create();
        Bed::factory()->create(['room_id' => $room->id]);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Сначала удалите койки комнаты');

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    /** Удаление койки с заселениями в repeater показывает уведомление, койка остаётся. */
    public function test_delete_bed_with_stays_in_repeater_shows_notification_and_keeps_bed(): void
    {
        $room = Room::factory()->create();
        $bed = Bed::factory()->create(['room_id' => $room->id, 'name' => '1.1']);
        Stay::factory()->create(['bed_id' => $bed->id]);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->callAction(TestAction::make('delete')->schemaComponent('beds', 'form')->arguments(['item' => "record-{$bed->id}"]))
            ->assertNotified('На койке есть заселения')
            ->assertSet("data.beds.record-{$bed->id}.name", '1.1')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('beds', ['id' => $bed->id]);
    }

    /** Сохранение без койки с заселениями показывает уведомление, койка остаётся. */
    public function test_save_without_bed_with_stays_shows_notification_and_keeps_bed(): void
    {
        $room = Room::factory()->create();
        $bed = Bed::factory()->create(['room_id' => $room->id]);
        Stay::factory()->create(['bed_id' => $bed->id]);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->set('data.beds', [])
            ->call('save')
            ->assertNotified('На койке есть заселения');

        $this->assertDatabaseHas('beds', ['id' => $bed->id]);
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
