<?php
declare(strict_types=1);

namespace App\Core\Api;

/** An API answer other than 2xx: {"error": {"code": "...", "message": "..."}}. */
final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $error, string $message, public readonly array $headers = [], public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
