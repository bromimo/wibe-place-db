<?php

namespace App\Exceptions;

use DomainException;

/** Комнату с койками нельзя удалить. */
final class RoomHasBedsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Сначала удалите койки комнаты');
    }
}
