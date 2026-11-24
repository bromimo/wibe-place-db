<?php

namespace App\Enums;

/** Тариф заселения. */
enum StayTariff: string
{
    case Standard = 'standard';
    case Manual = 'manual';

    /**
     * Русское название тарифа.
     */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Наш',
            self::Manual => 'Ручной',
        };
    }

    /**
     * Список тарифов для выпадающих списков.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
