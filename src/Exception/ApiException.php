<?php

declare(strict_types=1);

namespace NestboxPHP\Macaw\Exception;

use Exception;
use NestboxPHP\Macaw\ApiErrorWrapper;
use stdClass;
use NestboxPHP\Macaw\Exception\MacawException;

class ApiException extends MacawException
{
    public function __construct(stdClass|string $response)
    {
        $apiWrapper = new ApiErrorWrapper($response);
        $message = "$apiWrapper->status: $apiWrapper->errorMessage [$apiWrapper->error]";
        parent::__construct($message, $apiWrapper->code, null);
    }
}
