<?php

namespace Sd1\IamSso\Support;

use Illuminate\Http\Request;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\SsoUser;

class NullLoginHook implements LoginHook
{
    public function onLogin(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        return null;
    }

    public function onAccessRefreshed(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        return null;
    }

    public function onLogout(Request $request)
    {
    }
}
