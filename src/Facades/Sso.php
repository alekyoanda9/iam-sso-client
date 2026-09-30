<?php

namespace Sd1\IamSso\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool check()
 * @method static \Sd1\IamSso\SsoUser|null user()
 * @method static bool can(string $code)
 * @method static bool canAny(array $codes)
 * @method static bool canUrl(string $path)
 * @method static array|null matchUrl(string $path)
 * @method static array menus()
 * @method static \Sd1\IamSso\Access\PermissionSet permissions()
 * @method static void setBranchType(string|null $type)
 * @method static string|null activeBranchType()
 * @method static string|null accessToken()
 * @method static string|null permVersion()
 * @method static string refreshIfStale(bool $force = false)
 * @method static void forget()
 * @method static \Sd1\IamSso\Branch\BranchContext|null branch()
 * @method static string|null connectionName()
 *
 * @see \Sd1\IamSso\SsoManager
 */
class Sso extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'sso';
    }
}
