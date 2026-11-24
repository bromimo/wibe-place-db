<?php

namespace Tests\Feature\Room;

use Tests\TestCase;
use App\Models\Room;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тест демо-данных: комнаты и койки по номерам. */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Комнаты 1–4 «N кімната» с 5, 1, 8 и 3 койками «N.M.». */
    public function test_rooms_and_beds(): void
    {
        $this->travelTo('2026-11-24 12:00:00');

        $this->seed(DemoSeeder::class);

        $rooms = Room::query()->with('beds')->orderBy('number')->get();

        $this->assertSame(['1 кімната', '2 кімната', '3 кімната', '4 кімната'], $rooms->pluck('name')->all());
        $this->assertSame([5, 1, 8, 3], $rooms->map(fn (Room $room): int => $room->beds->count())->all());
        $this->assertSame(['3.1.', '3.2.', '3.3.', '3.4.', '3.5.', '3.6.', '3.7.', '3.8.'], $rooms[2]->beds->pluck('name')->all());
    }
}
