<?php

namespace App\Abstracts\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

abstract class AbstractResponseException extends AbstractBaseException
{
    public function toJsonResponse(): JsonResponse
    {
        $data = [
            'success' => false,
            'message' => $this->toArray(),
            'data'    => $this->hasData() ? $this->getData() : null,
            'code'    => $this->getCode(),
        ];

        $msg = $this->getPrevious()?->getMessage()
            . ' in ' . $this->getPrevious()?->getFile()
            . ' on line ' . $this->getPrevious()?->getLine();

        Log::error($msg);

        if (config('app.exceptions_trace', false)) {
            $data['trace'] = $msg;
        }

        return response()->json(
            data: $data,
            status: $this->code
        );
    }

    public function render(): JsonResponse
    {
        return $this->toJsonResponse();
    }
}
