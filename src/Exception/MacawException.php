<?php

namespace NestboxPHP\Macaw\Exception;

use Exception;
use NestboxPHP\Nestbox\Exception\NestboxException;

class MacawException extends NestboxException
{
    public function __construct(string $message, int $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}