<?php

namespace App\Actions\Stay;

use App\Models\Bed;
use App\Models\Stay;
use App\Dto\Stay\StayDto;
use App\Exceptions\StayChangedException;
use App\Exceptions\StayOverlapException;
use App\Exceptions\GuestOverlapException;
use App\Exceptions\InvalidStayDataException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Правка заселения. */
class UpdateStayAction
{
    /** Обновляет заселение; цену за ночь пересчитывает при смене комнаты или с ручного тарифа.
     * @param Stay $stay Правимое заселение (может быть устаревшей копией).
     * @param StayDto $dto Новые данные.
     * @throws InvalidStayDataException
     * @throws StayOverlapException
     * @throws GuestOverlapException
     * @throws StayChangedException
     * @throws ModelNotFoundException
     * @return Stay
     */
    public function run(Stay $stay, StayDto $dto): Stay
    {
        $dto->assertValid();

        return (new GuardStayChangeAction())->run(
            $stay,
            $dto->bed_id,
            fn (Bed $bed, Stay $fresh): Stay => $this->apply($bed, $fresh, $dto),
        );
    }

    /** Записывает новые данные в свежее заселение; вызывается только внутри GuardStayChangeAction.
     * @param Bed $bed Заблокированная целевая койка.
     * @param Stay $fresh Заселение, перечитанное под блокировкой.
     * @param StayDto $dto Новые данные.
     * @throws InvalidStayDataException
     * @throws StayOverlapException
     * @throws GuestOverlapException
     * @return Stay
     */
    public function apply(Bed $bed, Stay $fresh, StayDto $dto): Stay
    {
        $dto->assertValid();

        if (! $this->keepsOccupancy($bed, $fresh, $dto)) {
            (new GuardStayChangeAction())->assertFree($bed, $dto->user_id, $dto->date_from, $dto->date_to, $dto->status, $fresh->id);
        }

        $price = (new ResolveStayPriceAction())->run($dto->tariff, $bed, $fresh, $dto->manual_total);
        $attributes = array_merge($dto->toUpdateArray(), $price);

        $fresh->update($attributes);

        return $fresh->refresh();
    }

    /** Правка не меняет занимаемое: та же койка, те же даты, то же состояние и тот же гость, что у свежей записи.
     * Тогда пересечения (по койке и по гостю) не проверяются, чтобы в возникшем со временем пересечении
     * (просрочка на чужую бронь) или в старых данных с нарушением можно было сохранить примечание, тариф и оплаты.
     * @param Bed $bed Заблокированная целевая койка.
     * @param Stay $fresh Заселение, перечитанное под блокировкой.
     * @param StayDto $dto Новые данные.
     * @return bool
     */
    private function keepsOccupancy(Bed $bed, Stay $fresh, StayDto $dto): bool
    {
        return $bed->id === $fresh->bed_id
            && $dto->user_id === $fresh->user_id
            && $dto->status === $fresh->status
            && $dto->date_from->toDateString() === $fresh->date_from->toDateString()
            && $dto->date_to->toDateString() === $fresh->date_to->toDateString();
    }
}
