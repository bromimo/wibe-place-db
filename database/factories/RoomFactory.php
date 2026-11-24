<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Фабрика комнат.
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /** Последний выданный номер комнаты. */
    private static int $lastNumber = 0;

    /** Состояние по умолчанию: следующий свободный номер и название по нему.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fn (): int => self::nextNumber(),
            'name' => fn (array $attributes): string => Room::defaultName($attributes['number']),
            'price' => 460,
        ];
    }

    /** Номер больше всех выданных и всех существующих в БД: подходит и для пакетного создания.
     * @return int
     */
    private static function nextNumber(): int
    {
        return self::$lastNumber = max(self::$lastNumber, (int) Room::query()->max('number')) + 1;
    }
}
