<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Enums\StayTariff;

/** Определение цены за ночь и ручной суммы заселения. */
class ResolveStayPriceAction
{
    /** Вычисляет значения price_per_night и manual_total по тарифу.
     * Ручной тариф: цена за ночь null. Стандартный: ручная сумма null; цена берётся из комнаты койки
     * при создании, при смене комнаты или если прежний тариф был ручным, иначе остаётся прежней.
     * @param StayTariff $tariff Тариф заселения.
     * @param Bed $bed Целевая койка.
     * @param Stay|null $previous Прежнее заселение или null при создании.
     * @param int|null $manualTotal Ручная сумма.
     * @return array{price_per_night: int|null, manual_total: int|null}
     */
    public function run(StayTariff $tariff, Bed $bed, ?Stay $previous, ?int $manualTotal): array
    {
        if ($tariff === StayTariff::Manual) {
            return ['price_per_night' => null, 'manual_total' => $manualTotal];
        }

        $keepsPrice = $previous !== null
            && $previous->tariff !== StayTariff::Manual
            && $previous->bed->room_id === $bed->room_id;

        return [
            'price_per_night' => $keepsPrice ? $previous->price_per_night : $bed->room->price,
            'manual_total' => null,
        ];
    }
}
