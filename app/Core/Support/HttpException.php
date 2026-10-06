<?php
declare(strict_types=1);

namespace App\Core\Support;

class HttpException extends \RuntimeException
{
    public function __construct(public int $status, string $message = '')
    {
        parent::__construct($message);
    }
}
