<?php

namespace App\Models;

use App\Abstracts\Models\AbstractModel;
use App\Exceptions\RoomHasBedsException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Комната хостела. */
class Room extends AbstractModel
{
    protected $fillable = [
        'number',
        'name',
        'price',
    ];

    /** Запрещает прямое удаление комнаты с койками; удаление вместе с койками — DeleteRoomAction.
     * @throws RoomHasBedsException
     * @return void
     */
    protected static function booted(): void
    {
        static::deleting(function (self $room): void {
            if ($room->beds()->exists()) {
                throw new RoomHasBedsException();
            }
        });
    }

    /** Название комнаты по умолчанию: «N кімната».
     * @param int|string|null $number Номер комнаты.
     * @return string
     */
    public static function defaultName(int|string|null $number): string
    {
        return "{$number} кімната";
    }

    /** Койки комнаты по номеру.
     * @return HasMany<Bed, $this>
     */
    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class)->orderBy('number');
    }
}
