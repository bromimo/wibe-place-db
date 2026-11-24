<?php

namespace App\Exceptions;

use DomainException;

/** Клиента с заселениями нельзя удалить. */
final class UserHasStaysException extends DomainException
{
    /** Создаёт исключение с текстом для уведомления. */
    public function __construct()
    {
        parent::__construct('У клиента есть заселения');
    }
}
