<?php

namespace Database\Factories;

use App\Models\Bed;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Фабрика коек.
 * @extends Factory<Bed>
 */
class BedFactory extends Factory
{
    /** Последний выданный номер койки по комнатам.
     * @var array<int, int>
     */
    private static array $lastNumbers = [];

    /** Состояние по умолчанию: следующий номер в комнате и название по номерам комнаты и койки.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'number' => fn (array $attributes): int => self::nextNumber((int) $attributes['room_id']),
            'name' => fn (array $attributes): string => Bed::nameFor(
                (int) Room::query()->whereKey($attributes['room_id'])->value('number'),
                (int) $attributes['number'],
            ),
        ];
    }

    /** Номер больше всех выданных и существующих в комнате: подходит и для пакетного создания.
     * @param int $roomId Комната.
     * @return int
     */
    private static function nextNumber(int $roomId): int
    {
        $existing = (int) Bed::query()->where('room_id', $roomId)->max('number');

        return self::$lastNumbers[$roomId] = max(self::$lastNumbers[$roomId] ?? 0, $existing) + 1;
    }
}
