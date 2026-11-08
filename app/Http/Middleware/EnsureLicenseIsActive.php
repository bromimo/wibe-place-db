<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Filament\Facades\Filament;
use App\Services\LicenseService;
use Symfony\Component\HttpFoundation\Response;

/** Завершает сессию администратора после окончания лицензии. */
class EnsureLicenseIsActive
{
    /**
     * @param LicenseService $license
     */
    public function __construct(private readonly LicenseService $license)
    {
    }

    /**
     * Разлогинивает администратора и отправляет на страницу входа, если лицензия истекла.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->license->isExpired() || ! Filament::auth()->check()) {
            return $next($request);
        }

        Filament::auth()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(Filament::getLoginUrl());
    }
}
