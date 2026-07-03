<?php

namespace App\Models;

use Filament\Panel;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use App\Abstracts\Models\AbstractAuthenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Admin extends AbstractAuthenticatable implements FilamentUser
{
    use Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password'
    ];
    protected $hidden   = [
        'password',
        'remember_token'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    protected function name(): Attribute
    {
        return Attribute::get(
            fn () => trim($this->first_name . ' ' . $this->last_name)
        );
    }
}
