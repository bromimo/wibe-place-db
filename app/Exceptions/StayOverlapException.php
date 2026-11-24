<?php

namespace App\Exceptions;

use App\Models\Stay;
use DomainException;

/** Койка уже занята на выбранные даты. */
final class StayOverlapException extends DomainException
{
    /** Создаёт исключение с текстом для уведомления; о просроченном говорит, что гость не выселен.
     * @param Stay $conflict Конфликтующее заселение.
     */
    public function __construct(Stay $conflict)
    {
        $format = $conflict->isOverdue() ? 'Койка занята: %s с %s, не выселен (срок до %s)' : 'Койка занята: %s с %s по %s';

        parent::__construct(sprintf(
            $format,
            $conflict->user->full_name,
            $conflict->date_from->format('d.m'),
            $conflict->date_to->format('d.m'),
        ));
    }
}
