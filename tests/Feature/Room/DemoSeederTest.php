<?php

namespace Tests\Feature\Room;

use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
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

    /** Ни у одного гостя и ни на одной койке нет пересекающихся по фактическим интервалам заселений. */
    public function test_no_overlaps_per_guest_and_per_bed(): void
    {
        $this->travelTo('2026-11-24 12:00:00');

        $this->seed(DemoSeeder::class);

        $stays = Stay::query()->orderBy('date_from')->get();

        $this->assertGreaterThan(0, $stays->count());

        foreach (['user_id', 'bed_id'] as $key) {
            foreach ($stays->groupBy($key) as $group) {
                foreach ($group->values() as $i => $a) {
                    foreach ($group->values()->slice($i + 1) as $b) {
                        $overlaps = $a->date_from->lt($b->effectiveDateTo()) && $b->date_from->lt($a->effectiveDateTo());

                        $this->assertFalse($overlaps, "Пересечение по {$key}: заселения {$a->id} и {$b->id}");
                    }
                }
            }
        }
    }
}
