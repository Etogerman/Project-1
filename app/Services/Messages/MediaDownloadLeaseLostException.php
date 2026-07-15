<?php

namespace App\Services\Messages;

use RuntimeException;

class MediaDownloadLeaseLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Media download lease is no longer active.');
    }
}
