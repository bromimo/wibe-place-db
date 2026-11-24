<?php

namespace App\Actions\Room;

use App\Models\Room;
use Illuminate\Support\Facades\DB;

/** Создание комнаты с койками. */
class CreateRoomAction
{
    /** Создаёт комнату и койки с номерами 1..число коек.
     * @param int $number Номер комнаты.
     * @param string $name Название.
     * @param int $price Цена за ночь.
     * @param int $bedCount Число коек.
     * @return Room
     */
    public function run(int $number, string $name, int $price, int $bedCount): Room
    {
        return DB::transaction(function () use ($number, $name, $price, $bedCount): Room {
            $room = Room::query()->create(['number' => $number, 'name' => $name, 'price' => $price]);

            (new SyncRoomBedsAction())->run($room, $bedCount);

            return $room;
        });
    }
}
