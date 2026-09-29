<?php

namespace Sd1\IamSso\Exceptions;

/** IAM tidak bisa dihubungi (koneksi/timeout/5xx). */
class IamUnavailableException extends IamRequestException
{
}
