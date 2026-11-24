<?php

namespace App\Actions\Stay;

use Closure;
use App\Models\Bed;
use App\Models\Stay;
use App\Enums\StayStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Exceptions\StayChangedException;
use App\Exceptions\StayOverlapException;
use App\Http\Queries\Stay\FindOverlappingStayQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Единый защищённый путь изменения заселений: блокировка койки, затем блокировка и перечитывание заселения. */
class GuardStayChangeAction
{
    /** Число попыток, если заселение успело переехать на другую койку между чтением и блокировкой. */
    private const ATTEMPTS = 3;

    /** Выполняет изменение в транзакции; порядок блокировок всегда «койка → заселение».
     * Целевая койка — $bedId, а без него — текущая койка заселения. Если заселение переехало до блокировки,
     * транзакция завершается без записи и попытка повторяется с его новой койкой.
     * @template TResult
     * @param Stay|null $stay Изменяемое заселение (возможно, устаревшая копия) или null при создании.
     * @param int|null $bedId Целевая койка или null, чтобы взять койку самого заселения.
     * @param Closure(Bed, Stay|null): TResult $change Изменение; получает заблокированные койку и свежее заселение.
     * @throws ModelNotFoundException
     * @throws StayChangedException
     * @return TResult
     */
    public function run(?Stay $stay, ?int $bedId, Closure $change): mixed
    {
        $lockBedId = (int) ($bedId ?? $stay?->bed_id);

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $outcome = DB::transaction(function () use ($stay, $bedId, $lockBedId, $change): array {
                $bed = Bed::query()->lockForUpdate()->findOrFail($lockBedId);
                $fresh = $stay === null ? null : Stay::query()->lockForUpdate()->findOrFail($stay->id);

                if ($bedId === null && $fresh !== null && $fresh->bed_id !== $bed->id) {
                    return ['moved_to' => $fresh->bed_id];
                }

                return ['result' => $change($bed, $fresh)];
            });

            if (! array_key_exists('moved_to', $outcome)) {
                return $outcome['result'];
            }

            $lockBedId = $outcome['moved_to'];
        }

        throw new StayChangedException();
    }

    /** Проверяет, что на койке нет другого заселения в фактическом интервале сохраняемого.
     * Фактический конец у проживающего с прошедшей датой выезда — завтрашний день (Stay::effectiveEnd).
     * @param Bed $bed Заблокированная койка.
     * @param CarbonImmutable $from День заезда.
     * @param CarbonImmutable $to Сохраняемый день выезда.
     * @param StayStatus $status Сохраняемое состояние.
     * @param int|null $exceptStayId Само заселение при правке.
     * @throws StayOverlapException
     * @return void
     */
    public function assertBedFree(Bed $bed, CarbonImmutable $from, CarbonImmutable $to, StayStatus $status, ?int $exceptStayId = null): void
    {
        $conflict = (new FindOverlappingStayQuery())($bed->id, $from, Stay::effectiveEnd($status, $to), $exceptStayId);

        if ($conflict !== null) {
            throw new StayOverlapException($conflict);
        }
    }
}
