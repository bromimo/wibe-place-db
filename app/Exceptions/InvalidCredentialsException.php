<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use App\Abstracts\Exceptions\AbstractResponseException;

final class InvalidCredentialsException extends AbstractResponseException
{
    public function __construct()
    {
        parent::__construct(
            langKey: 'exceptions.invalid_credentials',
            code: Response::HTTP_UNAUTHORIZED
        );
    }
}
