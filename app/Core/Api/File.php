<?php
declare(strict_types=1);

namespace App\Core\Api;

/** A handler's answer that is a file (a PDF), streamed instead of JSON. */
final class File
{
    public function __construct(public readonly string $path, public readonly string $name, public readonly string $mime = 'application/pdf') {}
}
