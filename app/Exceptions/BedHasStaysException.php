<?php

namespace App\Exceptions;

use DomainException;

/** Койку с заселениями нельзя удалить. */
final class BedHasStaysException extends DomainException
{
    public function __construct()
    {
        parent::__construct('На койке есть заселения');
    }
}
