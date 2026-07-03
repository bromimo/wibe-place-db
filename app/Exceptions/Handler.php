<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
//        ResponseException::class,
    ];

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     * @var array<int, string>
     */
    protected $dontFlash = [
        'password',
        'current_password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        if (request()->expectsJson()) {
            $this->reportable(fn(Throwable $e) => $this->globalResponseException($e));
            $this->renderable(fn(Throwable $e) => $this->globalResponseException($e));
        }
    }

    /** Глобальный перехват исключений.
     * @param Throwable $e
     * @return void
     * @throws AppNotFoundException
     * @throws ResponseException|Throwable
     */
    protected function globalResponseException(Throwable $e): void
    {
        if (!$e instanceof ResponseException) {
            $this->overrideException($e);
        }
    }

    /** Перезаписывает код оригинальных исключений.
     * @param Throwable $e
     * @return int
     */
    protected function overrideExceptionCode(Throwable $e): int
    {
        $codeIsOk = is_numeric($e->getCode()) && (int)$e->getCode() >= 1 && (int)$e->getCode() <= 504;

        return match ($e::class) {
            ModelNotFoundException::class, NotFoundHttpException::class => 404,
            default => $codeIsOk ? $e->getCode() : 400
        };
    }

    /** Перезаписывает оригинальные исключения.
     * @param Throwable $e
     * @return mixed
     * @throws AppNotFoundException
     * @throws ResponseException
     * @throws Throwable
     */
    protected function overrideException(Throwable $e): mixed
    {
        match ($e::class) {
            AuthenticationException::class => throw new AuthException(),
            ThrottleRequestsException::class => throw new ThrottleRequestException($e),
            NotFoundHttpException::class => throw new AppNotFoundException($e),
            default => throw new ResponseException(
                    langKey: $e->getMessage(),
                    code: $this->overrideExceptionCode($e),
                    previous: $e)
        };
    }
}
