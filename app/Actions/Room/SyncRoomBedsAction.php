<?php

namespace App\Actions\Room;

use App\Models\Bed;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BedCountDecreaseException;

/** Приведение коек комнаты к заданному числу с названиями по номеру комнаты. */
class SyncRoomBedsAction
{
    /** Добавляет койки со следующими номерами или удаляет койки с наибольшими номерами, если на них нет заселений;
     * названия оставшихся коек пересоздаются по номеру комнаты.
     * @param Room $room Комната с уже сохранённым номером.
     * @param int $bedCount Нужное число коек.
     * @throws BedCountDecreaseException
     * @return void
     */
    public function run(Room $room, int $bedCount): void
    {
        DB::transaction(function () use ($room, $bedCount): void {
            $beds = Bed::query()->where('room_id', $room->id)->orderBy('number')->lockForUpdate()->get();
            $kept = $beds->take($bedCount);
            $removed = $beds->slice($bedCount);

            $this->assertRemovable($removed->pluck('id')->all());
            $removed->each(fn (Bed $bed): ?bool => $bed->delete());

            foreach ($kept as $bed) {
                $bed->update(['name' => Bed::nameFor($room->number, $bed->number)]);
            }

            for ($number = (int) $kept->max('number') + 1; $kept->count() < $bedCount; $number++) {
                $kept->push($room->beds()->create(['number' => $number, 'name' => Bed::nameFor($room->number, $number)]));
            }
        });
    }

    /** Проверяет, что на удаляемых койках нет заселений.
     * @param array<int, int> $bedIds Удаляемые койки.
     * @throws BedCountDecreaseException
     * @return void
     */
    private function assertRemovable(array $bedIds): void
    {
        $busy = Bed::query()->whereKey($bedIds)->whereHas('stays')->orderBy('number')->first();

        if ($busy !== null) {
            throw new BedCountDecreaseException($busy->name);
        }
    }
}
