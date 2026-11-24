<?php

namespace App\Models;

use App\Abstracts\Models\AbstractModel;
use App\Exceptions\RoomHasBedsException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Комната хостела. */
class Room extends AbstractModel
{
    protected $fillable = [
        'name',
        'price',
        'sort',
    ];

    /** Запрещает удаление комнаты с койками.
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

    /** Койки комнаты по порядку.
     * @return HasMany<Bed, $this>
     */
    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class)->orderBy('sort')->orderBy('id');
    }
}
