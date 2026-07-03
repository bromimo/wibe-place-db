<?php

namespace App\Exceptions;

use Throwable;
use Symfony\Component\HttpFoundation\Response;
use App\Abstracts\Exceptions\AbstractResponseException;

final class ThrottleRequestException extends AbstractResponseException
{
    public function __construct(Throwable $e)
    {
        parent::__construct(
            langKey: 'exceptions.throttle_request_error',
            code: Response::HTTP_TOO_MANY_REQUESTS,
            previous: $e
        );
    }
}
