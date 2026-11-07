<?php

namespace App\Services\WpImport;

/** A chunk arrived for another offset than the bytes received so far (the browser resumes from $received). */
class WpImportOffsetException extends WpImportException
{
    public function __construct(public readonly int $received)
    {
        parent::__construct('Unexpected offset');
    }
}
