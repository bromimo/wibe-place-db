<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Проверка срока действия лицензии. */
class LicenseService
{
    /**
     * Возвращает дату окончания лицензии или null, если она не задана.
     *
     * @return CarbonImmutable|null
     */
    public function expiresAt(): ?CarbonImmutable
    {
        $value = config('license.expires_at');

        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * Определяет, истекла ли лицензия.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && now()->greaterThanOrEqualTo($expiresAt);
    }

    /**
     * Определяет, что до окончания лицензии осталось меньше календарного месяца.
     *
     * @return bool
     */
    public function isExpiringSoon(): bool
    {
        $expiresAt = $this->expiresAt();

        if ($expiresAt === null || $this->isExpired()) {
            return false;
        }

        return now()->greaterThanOrEqualTo($expiresAt->subMonthNoOverflow());
    }

    /**
     * Возвращает частоту уведомлений в секундах.
     *
     * @return int
     */
    public function notificationInterval(): int
    {
        return max(1, (int) config('license.notification_interval'));
    }

    /**
     * Возвращает текст предупреждения о скором окончании лицензии.
     *
     * @return string
     */
    public function notificationText(): string
    {
        return (string) config('license.notification_text');
    }

    /**
     * Возвращает текст сообщения об истёкшей лицензии.
     *
     * @return string
     */
    public function expiredText(): string
    {
        return (string) config('license.expired_text');
    }
}
