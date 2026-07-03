<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use App\Abstracts\Exceptions\AbstractResponseException;

final class FileUnknownException extends AbstractResponseException
{
    public function __construct()
    {
        parent::__construct(
            langKey: 'exceptions.file_exist',
            code: Response::HTTP_BAD_REQUEST
        );
    }
}
