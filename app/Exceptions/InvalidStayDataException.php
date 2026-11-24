<?php

namespace App\Exceptions;

use DomainException;

/** Некорректные данные заселения. */
final class InvalidStayDataException extends DomainException
{
    /**
     * @param string $message Русский текст ошибки для уведомления.
     */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
