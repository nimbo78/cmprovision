<?php

namespace App\Support;

/* Why an image could not be taken from its source, in words for the operator (never an address with its query).
   A fatal one is not worth another try: the address is refused, the file is not there, what was published disagrees. */
class ImageSourceException extends \RuntimeException
{
    public $fatal;
    /* the HTTP status the server answered, when the exception is about one (0 otherwise) */
    public $status;

    public function __construct(string $message, bool $fatal = false, int $status = 0)
    {
        parent::__construct($message);
        $this->fatal = $fatal;
        $this->status = $status;
    }
}
