<?php
declare(strict_types=1);

namespace Localia;

class HttpError extends \RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(int $status, string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
}
