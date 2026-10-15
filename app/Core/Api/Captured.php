<?php
declare(strict_types=1);

namespace App\Core\Api;

/** What a web action answered (json_response / redirect) while the API delegated to it. */
final class Captured extends \RuntimeException
{
    public function __construct(public readonly array $data, public readonly int $status)
    {
        parent::__construct('captured');
    }
}
