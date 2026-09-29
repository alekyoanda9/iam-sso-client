<?php

namespace Sd1\IamSso\Exceptions;

/** IAM menjawab dengan status error. getCode() = status HTTP. */
class IamRequestException extends SsoException
{
    /** @var array|null */
    private $body;

    public function __construct(string $message, int $status, $body = null)
    {
        parent::__construct($message, $status);
        $this->body = is_array($body) ? $body : null;
    }

    public function body()
    {
        return $this->body;
    }
}
