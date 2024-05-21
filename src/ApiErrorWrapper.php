<?php

declare(strict_types=1);

namespace NestboxPHP\Macaw;

/**
 * The basic wrapper around every failed API response
 *
 * @property $code Numerical HTTP code
 * @property $error Playfab error code
 * @property $errorCode Numerical PlayFab error code
 * @property $errorDetails Detailed description of individual issues with the request object
 * @property $errorMessage Description for the PlayFab errorCode
 * @property $status String HTTP code
 */
class ApiErrorWrapper
{
    public int $code;
    public string $error;
    public int $errorCode;
    public string $errorDetails;
    public string $errorMessage;
    public string $status;

    public function __construct(string|object $response)
    {
        $response = (is_object($response)) ? $response : json_decode($response);

        $this->code = $response->code ?? -1;
        $this->error = $response->error ?? "No error provided";
        $this->errorCode = $response->errorCode ?? -1;
        $this->errorDetails = $response->errorDetails ?? "No errorDetails provided";
        $this->errorMessage = $response->errorMessage ?? "No errorMessage provided";
        $this->status = $response->status ?? "No status provided";
    }
}
