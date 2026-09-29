<?php

namespace Sd1\IamSso\Exceptions;

/** JWT tidak valid (format, tanda tangan, kedaluwarsa, iss/aud). */
class InvalidTokenException extends SsoException
{
}
