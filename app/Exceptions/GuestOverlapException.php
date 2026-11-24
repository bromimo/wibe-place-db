<?php

namespace App\Exceptions;

use App\Models\Stay;
use DomainException;

/** Гость в эти даты уже занимает другую койку. */
final class GuestOverlapException extends DomainException
{
    /** Создаёт исключение с текстом для уведомления; о просроченном говорит, что гость не выселен.
     * @param Stay $conflict Конфликтующее заселение того же гостя.
     */
    public function __construct(Stay $conflict)
    {
        $format = $conflict->isOverdue()
            ? 'Гость уже занимает койку %s: с %s, не выселен (срок до %s)'
            : 'Гость уже занимает койку %s: с %s по %s';

        parent::__construct(sprintf(
            $format,
            $conflict->bed->name,
            $conflict->date_from->format('d.m'),
            $conflict->date_to->format('d.m'),
        ));
    }
}
