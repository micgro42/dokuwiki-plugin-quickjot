<?php

namespace dokuwiki\plugin\quickjot;

/**
 * Thrown for any condition the caller is meant to see as a proper HTTP error
 *
 * The exception code doubles as the HTTP status code to send.
 */
class QuickJotException extends \RuntimeException
{
    /**
     * @param string $message Message safe to hand back to the client
     * @param int $status HTTP status code to answer with
     */
    public function __construct($message, $status = 400)
    {
        parent::__construct($message, $status);
    }

    /**
     * The HTTP status code this error should be reported as
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->getCode();
    }
}
