<?php

namespace App\Exceptions;

use DomainException;

/** Заселение одновременно меняют другие действия, изменение не применено. */
final class StayChangedException extends DomainException
{
    /** Создаёт исключение с текстом для уведомления. */
    public function __construct()
    {
        parent::__construct('Заселение изменилось, повторите действие');
    }
}
