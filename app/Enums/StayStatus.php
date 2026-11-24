<?php

namespace App\Enums;

/** Состояние заселения. */
enum StayStatus: string
{
    case Booked = 'booked';
    case Living = 'living';
    case CheckedOut = 'checked_out';

    /**
     * Русское название состояния.
     */
    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Бронь',
            self::Living => 'Проживает',
            self::CheckedOut => 'Выселился',
        };
    }

    /**
     * Список состояний для выпадающих списков.
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
