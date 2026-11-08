<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Services\LicenseService;
use Illuminate\Contracts\View\View;
use Filament\Notifications\Notification;

/** Периодическое уведомление о скором окончании лицензии. */
class LicenseExpirationNotifier extends Component
{
    public const string NOTIFICATION_ID = 'license-expiration';

    public const string NOTIFIED_AT_KEY = 'license.notified_at';

    public const string OPEN_KEY = 'license.notification_open';

    /** Максимальный шаг опроса сервера, секунды. */
    public const int MAX_POLL_INTERVAL = 60;

    /**
     * Показывает уведомление, если подошёл срок или оно не было закрыто.
     *
     * @param LicenseService $license
     * @param bool $pageLoad
     * @return void
     */
    public function check(LicenseService $license, bool $pageLoad = false): void
    {
        if (! $license->isExpiringSoon()) {
            return;
        }

        if (session(self::OPEN_KEY)) {
            // Незакрытое уведомление восстанавливаем только после перезагрузки страницы.
            if ($pageLoad) {
                $this->send($license);
            }

            return;
        }

        $notifiedAt = session(self::NOTIFIED_AT_KEY);

        if ($notifiedAt !== null && now()->getTimestamp() - $notifiedAt < $license->notificationInterval()) {
            return;
        }

        $this->send($license);
    }

    /**
     * Отмечает уведомление закрытым и отсчитывает интервал от момента закрытия.
     *
     * @param string $id
     * @return void
     */
    #[On('notificationClosed')]
    public function closed(string $id): void
    {
        if ($id !== self::NOTIFICATION_ID) {
            return;
        }

        session()->forget(self::OPEN_KEY);
        session()->put(self::NOTIFIED_AT_KEY, now()->getTimestamp());
    }

    /**
     * Отрисовывает компонент.
     *
     * @param LicenseService $license
     * @return View
     */
    public function render(LicenseService $license): View
    {
        return view('livewire.license-expiration-notifier', [
            'active'       => $license->isExpiringSoon(),
            'pollInterval' => min($license->notificationInterval(), self::MAX_POLL_INTERVAL),
        ]);
    }

    /**
     * Отправляет уведомление.
     *
     * @param LicenseService $license
     * @return void
     */
    private function send(LicenseService $license): void
    {
        session()->put(self::OPEN_KEY, true);
        session()->put(self::NOTIFIED_AT_KEY, now()->getTimestamp());

        Notification::make(self::NOTIFICATION_ID)
            ->danger()
            ->color('danger')
            ->title($license->notificationText())
            ->persistent()
            ->send();
    }
}
