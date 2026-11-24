<?php

namespace App\Actions\Room;

use App\Models\Room;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BedCountDecreaseException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Изменение комнаты и числа её коек. */
class UpdateRoomAction
{
    /** Сохраняет комнату и приводит койки к заданному числу; при отказе не меняется ничего.
     * @param Room $room Комната (может быть устаревшей копией; после сохранения перечитывается).
     * @param int $number Номер комнаты.
     * @param string $name Название.
     * @param int $price Цена за ночь.
     * @param int $bedCount Число коек.
     * @throws BedCountDecreaseException
     * @throws ModelNotFoundException
     * @return Room
     */
    public function run(Room $room, int $number, string $name, int $price, int $bedCount): Room
    {
        DB::transaction(function () use ($room, $number, $name, $price, $bedCount): void {
            $fresh = Room::query()->lockForUpdate()->findOrFail($room->id);
            $fresh->update(['number' => $number, 'name' => $name, 'price' => $price]);

            (new SyncRoomBedsAction())->run($fresh, $bedCount);
        });

        return $room->refresh();
    }
}
