<?php

namespace Sd1\IamSso\Tests;

use Illuminate\Http\Request;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\SsoUser;

class RecordingHook implements LoginHook
{
    public static $calls = [];
    public static $loginResponse;

    public function onLogin(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        self::$calls[] = ['login', $user->nik(), $permissions->codes()];

        return self::$loginResponse;
    }

    public function onAccessRefreshed(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        self::$calls[] = ['refreshed', $user->nik(), $permissions->codes()];
    }

    public function onLogout(Request $request)
    {
        self::$calls[] = ['logout'];
    }
}
