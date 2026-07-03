<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use App\Abstracts\Exceptions\AbstractResponseException;

final class AdminCreateException extends AbstractResponseException
{
    public function __construct()
    {
        parent::__construct(
            langKey: 'exceptions.admin_already_exists',
            code: Response::HTTP_NOT_FOUND,
        );
    }
}
