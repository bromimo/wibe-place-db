<?php

namespace App\Exceptions;

use DomainException;

/** Выселившегося гостя нельзя менять. */
final class StayLockedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Выселившегося гостя нельзя переносить');
    }
}
