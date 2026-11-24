<?php

namespace App\Exceptions;

use App\Models\Stay;
use DomainException;

/** Койка уже занята на выбранные даты. */
final class StayOverlapException extends DomainException
{
    /**
     * @param Stay $conflict Конфликтующее заселение.
     */
    public function __construct(Stay $conflict)
    {
        parent::__construct(sprintf(
            'Койка занята: %s с %s по %s',
            $conflict->user->full_name,
            $conflict->date_from->format('d.m'),
            $conflict->date_to->format('d.m'),
        ));
    }
}
