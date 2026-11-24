<?php

namespace App\Models;

use App\Abstracts\Models\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Комната хостела. */
class Room extends AbstractModel
{
    protected $fillable = [
        'name',
        'price',
        'sort',
    ];

    /** Койки комнаты по порядку.
     * @return HasMany<Bed, $this>
     */
    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class)->orderBy('sort')->orderBy('id');
    }
}
