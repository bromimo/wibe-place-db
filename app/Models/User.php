<?php

namespace App\Models;

use App\Observers\UserObserver;
use App\Abstracts\Models\AbstractModel;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(UserObserver::class)]
class User extends AbstractModel
{
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     * @var array<int, string>
     */
    protected $fillable = [
        'last_name',
        'first_name',
        'phone',
        'notes',
        'docs',
    ];
}
