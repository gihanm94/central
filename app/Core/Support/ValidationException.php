<?php
declare(strict_types=1);

namespace App\Core\Support;

class ValidationException extends \RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct((string) reset($errors));
    }
}
