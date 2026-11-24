<?php

namespace App\Models;

use App\Abstracts\Models\AbstractModel;
use App\Observers\UserObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

#[ObservedBy(UserObserver::class)]
class User extends AbstractModel
{
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'last_name',
        'first_name',
        'phone',
        'notes',
        'docs',
    ];

    /**
     * Заселения гостя.
     *
     * @return HasMany<Stay, $this>
     */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    /**
     * Полное имя: фамилия и имя.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->last_name} {$this->first_name}");
    }
}
