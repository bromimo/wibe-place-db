<?php

namespace App\Actions\Room;

use App\Models\Bed;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BedHasStaysException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Удаление комнаты вместе с койками. */
class DeleteRoomAction
{
    /** Удаляет койки комнаты, затем саму комнату; если на какой-либо койке есть заселения, не удаляет ничего.
     * @param Room $room Комната.
     * @throws BedHasStaysException
     * @throws ModelNotFoundException
     * @return void
     */
    public function run(Room $room): void
    {
        DB::transaction(function () use ($room): void {
            $fresh = Room::query()->lockForUpdate()->findOrFail($room->id);
            $beds = Bed::query()->where('room_id', $fresh->id)->lockForUpdate()->get();

            if (Bed::query()->whereKey($beds->modelKeys())->whereHas('stays')->exists()) {
                throw new BedHasStaysException();
            }

            $beds->each(fn (Bed $bed): ?bool => $bed->delete());
            $fresh->delete();
        });
    }
}
