<?php

namespace App\Models;

use App\Abstracts\Models\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Койка в комнате. */
class Bed extends AbstractModel
{
    protected $fillable = [
        'room_id',
        'name',
        'sort',
    ];

    /** Комната койки.
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** Заселения на койке.
     * @return HasMany<Stay, $this>
     */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }
}
