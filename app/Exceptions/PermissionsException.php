<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use App\Abstracts\Exceptions\AbstractResponseException;

final class PermissionsException extends AbstractResponseException
{
    public function __construct()
    {
        parent::__construct(
            langKey: 'exceptions.permissions',
            code: Response::HTTP_FORBIDDEN,
        );
    }
}
