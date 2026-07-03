<?php

namespace App\Exceptions;

use Throwable;
use Symfony\Component\HttpFoundation\Response;
use App\Abstracts\Exceptions\AbstractResponseException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class AppNotFoundException extends AbstractResponseException
{
    public function __construct(Throwable $e)
    {
        if ($e->getPrevious() instanceof ModelNotFoundException) {
            parent::__construct(
                langKey: 'exceptions.model_not_found',
                code: Response::HTTP_NOT_FOUND,
                previous: $e
            );
        } else {
            parent::__construct(
                langKey: 'exceptions.route_not_found',
                code: Response::HTTP_NOT_FOUND,
                previous: $e
            );
        }
    }
}
