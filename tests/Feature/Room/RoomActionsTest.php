<?php

namespace Tests\Feature\Room;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Actions\Room\CreateRoomAction;
use App\Actions\Room\DeleteRoomAction;
use App\Actions\Room\UpdateRoomAction;
use App\Exceptions\BedHasStaysException;
use App\Exceptions\BedCountDecreaseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\UniqueConstraintViolationException;

/** Тесты действий с комнатами: создание с койками, изменение числа коек, перенумерация, удаление. */
class RoomActionsTest extends TestCase
{
    use RefreshDatabase;

    /** Создаёт комнату через действие.
     * @param int $number Номер комнаты.
     * @param int $bedCount Число коек.
     * @return Room
     */
    private function createRoom(int $number = 3, int $bedCount = 3): Room
    {
        return (new CreateRoomAction())->run($number, "{$number} кімната", 460, $bedCount);
    }

    /** Названия коек комнаты по порядку номеров.
     * @param Room $room Комната.
     * @return array<int, string>
     */
    private function bedNames(Room $room): array
    {
        return $room->beds()->pluck('name')->all();
    }

    /** Комната создаётся с заданным числом коек, пронумерованных с единицы. */
    public function test_create_room_with_beds(): void
    {
        $room = $this->createRoom(3, 3);

        $this->assertSame(3, $room->number);
        $this->assertSame('3 кімната', $room->name);
        $this->assertSame(460, $room->price);
        $this->assertSame(['3.1.', '3.2.', '3.3.'], $this->bedNames($room));
        $this->assertSame([1, 2, 3], $room->beds()->pluck('number')->all());
    }

    /** Комнату можно создать без коек. */
    public function test_create_room_without_beds(): void
    {
        $room = $this->createRoom(1, 0);

        $this->assertSame([], $this->bedNames($room));
    }

    /** Увеличение числа коек добавляет койки со следующими номерами. */
    public function test_grow_appends_beds(): void
    {
        $room = $this->createRoom(3, 2);
        $first = $room->beds()->first();

        (new UpdateRoomAction())->run($room, 3, '3 кімната', 460, 4);

        $this->assertSame(['3.1.', '3.2.', '3.3.', '3.4.'], $this->bedNames($room));
        $this->assertSame($first->id, $room->beds()->first()->id);
    }

    /** Уменьшение числа коек удаляет койки с наибольшими номерами. */
    public function test_shrink_removes_highest_beds(): void
    {
        $room = $this->createRoom(3, 4);

        (new UpdateRoomAction())->run($room, 3, '3 кімната', 460, 2);

        $this->assertSame(['3.1.', '3.2.'], $this->bedNames($room));
        $this->assertDatabaseCount('beds', 2);
    }

    /** Уменьшение не выполняется, если на удаляемой койке есть заселения; ничего не меняется. */
    public function test_shrink_refused_when_removed_bed_has_stays(): void
    {
        $room = $this->createRoom(3, 4);
        $beds = $room->beds()->get();
        Stay::factory()->create(['bed_id' => $beds[2]->id]);
        Stay::factory()->create(['bed_id' => $beds[3]->id]);

        try {
            (new UpdateRoomAction())->run($room, 5, 'Люкс', 600, 1);
            $this->fail('Ожидалось BedCountDecreaseException');
        } catch (BedCountDecreaseException $e) {
            $this->assertSame('Нельзя уменьшить число коек: на койке 3.3. есть заселения', $e->getMessage());
        }

        $fresh = $room->fresh();
        $this->assertSame(3, $fresh->number);
        $this->assertSame('3 кімната', $fresh->name);
        $this->assertSame(460, $fresh->price);
        $this->assertSame(['3.1.', '3.2.', '3.3.', '3.4.'], $this->bedNames($fresh));
    }

    /** Смена номера комнаты пересоздаёт названия всех её коек. */
    public function test_renumber_regenerates_bed_names(): void
    {
        $room = $this->createRoom(3, 3);

        (new UpdateRoomAction())->run($room, 7, 'Люкс', 600, 3);

        $fresh = $room->fresh();
        $this->assertSame(7, $fresh->number);
        $this->assertSame('Люкс', $fresh->name);
        $this->assertSame(600, $fresh->price);
        $this->assertSame(['7.1.', '7.2.', '7.3.'], $this->bedNames($fresh));
    }

    /** Номер комнаты уникален. */
    public function test_room_number_is_unique(): void
    {
        $this->createRoom(3, 1);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createRoom(3, 1);
    }

    /** Комната без заселений удаляется вместе с койками. */
    public function test_delete_room_without_stays(): void
    {
        $room = $this->createRoom(3, 3);

        (new DeleteRoomAction())->run($room);

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $this->assertDatabaseCount('beds', 0);
    }

    /** Комната с заселением на любой койке не удаляется, койки остаются. */
    public function test_delete_room_with_stays_is_refused(): void
    {
        $room = $this->createRoom(3, 3);
        Stay::factory()->create(['bed_id' => $room->beds()->get()[1]->id]);

        try {
            (new DeleteRoomAction())->run($room);
            $this->fail('Ожидалось BedHasStaysException');
        } catch (BedHasStaysException $e) {
            $this->assertSame('На койке есть заселения', $e->getMessage());
        }

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertSame(3, Bed::query()->where('room_id', $room->id)->count());
    }
}
