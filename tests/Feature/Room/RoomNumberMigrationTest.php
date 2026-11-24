<?php

namespace Tests\Feature\Room;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Тест миграции номеров комнат и коек: заполнение существующих строк. */
class RoomNumberMigrationTest extends TestCase
{
    /** Прогоняет в базе в памяти миграции до шага перед миграцией номеров.
     * @return void
     */
    private function migrateToPreviousStep(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->artisan('migrate')->assertSuccessful();
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('rooms', 'sort'));
        $this->assertFalse(Schema::hasColumn('rooms', 'number'));
    }

    /** Добавляет комнату в старом виде.
     * @param string $name Название.
     * @param int $sort Порядок.
     * @return int
     */
    private function oldRoom(string $name, int $sort): int
    {
        return DB::table('rooms')->insertGetId(['name' => $name, 'price' => 460, 'sort' => $sort]);
    }

    /** Добавляет койку в старом виде.
     * @param int $roomId Комната.
     * @param string $name Название.
     * @param int $sort Порядок.
     * @return int
     */
    private function oldBed(int $roomId, string $name, int $sort): int
    {
        return DB::table('beds')->insertGetId(['room_id' => $roomId, 'name' => $name, 'sort' => $sort]);
    }

    /** Существующие комнаты и койки нумеруются по порядку sort, id; названия коек пересоздаются. */
    public function test_up_backfills_numbers_and_bed_names(): void
    {
        $this->migrateToPreviousStep();

        $b = $this->oldRoom('B', 2);
        $a = $this->oldRoom('A', 1);
        $c = $this->oldRoom('C', 2);
        $a2 = $this->oldBed($a, 'a2', 5);
        $a1 = $this->oldBed($a, 'a1', 0);
        $a3 = $this->oldBed($a, 'a3', 5);
        $b1 = $this->oldBed($b, 'b1', 0);
        $stay = DB::table('stays')->insertGetId([
            'bed_id' => $a2,
            'user_id' => DB::table('users')->insertGetId(['first_name' => 'Иван', 'last_name' => 'Шевченко', 'phone' => '+380000000000']),
            'date_from' => '2026-11-24',
            'date_to' => '2026-11-26',
            'status' => 'booked',
            'tariff' => 'standard',
            'price_per_night' => 460,
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('rooms', 'sort'));
        $this->assertFalse(Schema::hasColumn('beds', 'sort'));
        $this->assertEquals([$a => 1, $b => 2, $c => 3], DB::table('rooms')->pluck('number', 'id')->map(fn (mixed $number): int => (int) $number)->all());
        $this->assertEquals(
            [$a2 => [2, '1.2.'], $a1 => [1, '1.1.'], $a3 => [3, '1.3.'], $b1 => [1, '2.1.']],
            DB::table('beds')->get()->mapWithKeys(fn (object $bed): array => [$bed->id => [(int) $bed->number, $bed->name]])->all(),
        );
        $this->assertSame($a2, (int) DB::table('stays')->where('id', $stay)->value('bed_id'));
    }

    /** После миграции номер комнаты и номер койки в комнате уникальны. */
    public function test_up_adds_unique_indexes(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasIndex('rooms', ['number'], 'unique'));
        $this->assertTrue(Schema::hasIndex('beds', ['room_id', 'number'], 'unique'));
    }

    /** Откат возвращает sort, заполненный номером, и убирает номер. */
    public function test_down_restores_sort(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        $room = DB::table('rooms')->insertGetId(['number' => 4, 'name' => '4 кімната', 'price' => 460]);
        DB::table('beds')->insert(['room_id' => $room, 'number' => 2, 'name' => '4.2.']);

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('rooms', 'number'));
        $this->assertFalse(Schema::hasColumn('beds', 'number'));
        $this->assertSame(4, (int) DB::table('rooms')->value('sort'));
        $this->assertSame(2, (int) DB::table('beds')->value('sort'));
    }
}
