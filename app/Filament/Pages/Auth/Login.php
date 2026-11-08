<?php

namespace App\Filament\Pages\Auth;

use App\Services\LicenseService;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Validation\ValidationException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;

/** Страница входа с блокировкой после окончания лицензии. */
class Login extends BaseLogin
{
    /**
     * Показывает сообщение об истёкшей лицензии сразу при открытии страницы.
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        $license = app(LicenseService::class);

        if ($license->isExpired()) {
            $this->addError('data.email', $license->expiredText());
        }
    }

    /**
     * Запрещает вход после окончания лицензии.
     *
     * @throws ValidationException
     * @return LoginResponse|null
     */
    public function authenticate(): ?LoginResponse
    {
        $license = app(LicenseService::class);

        if ($license->isExpired()) {
            throw ValidationException::withMessages([
                'data.email' => $license->expiredText(),
            ]);
        }

        return parent::authenticate();
    }
}
