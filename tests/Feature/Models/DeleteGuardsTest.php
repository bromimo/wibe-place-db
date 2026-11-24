<?php

namespace Tests\Feature\Models;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use App\Exceptions\BedHasStaysException;
use App\Exceptions\RoomHasBedsException;
use App\Exceptions\UserHasStaysException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты запретов удаления комнат, коек и клиентов. */
class DeleteGuardsTest extends TestCase
{
    use RefreshDatabase;

    /** Комнату с койками удалить нельзя. */
    public function test_room_with_beds_cannot_be_deleted(): void
    {
        $room = Room::factory()->create();
        Bed::factory()->create(['room_id' => $room->id]);

        try {
            $room->delete();
            $this->fail('Ожидалось RoomHasBedsException');
        } catch (RoomHasBedsException $e) {
            $this->assertSame('Сначала удалите койки комнаты', $e->getMessage());
            $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        }
    }

    /** Пустую комнату удалить можно. */
    public function test_empty_room_can_be_deleted(): void
    {
        $room = Room::factory()->create();

        $room->delete();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    /** Койку с заселениями удалить нельзя. */
    public function test_bed_with_stays_cannot_be_deleted(): void
    {
        $bed = Bed::factory()->create();
        Stay::factory()->create(['bed_id' => $bed->id]);

        try {
            $bed->delete();
            $this->fail('Ожидалось BedHasStaysException');
        } catch (BedHasStaysException $e) {
            $this->assertSame('На койке есть заселения', $e->getMessage());
            $this->assertDatabaseHas('beds', ['id' => $bed->id]);
        }
    }

    /** Клиента с заселениями удалить нельзя, файл документов остаётся. */
    public function test_user_with_stays_cannot_be_deleted(): void
    {
        Storage::fake();
        Storage::put('docs/passport.pdf', 'x');
        $user = User::factory()->create(['docs' => 'docs/passport.pdf']);
        Stay::factory()->create(['user_id' => $user->id]);

        try {
            $user->delete();
            $this->fail('Ожидалось UserHasStaysException');
        } catch (UserHasStaysException $e) {
            $this->assertSame('У клиента есть заселения', $e->getMessage());
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            Storage::assertExists('docs/passport.pdf');
        }
    }

    /** Клиента без заселений удалить можно. */
    public function test_user_without_stays_can_be_deleted(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
