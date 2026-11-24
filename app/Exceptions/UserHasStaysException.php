<?php

namespace App\Exceptions;

use DomainException;

/** Клиента с заселениями нельзя удалить. */
final class UserHasStaysException extends DomainException
{
    public function __construct()
    {
        parent::__construct('У клиента есть заселения');
    }
}
