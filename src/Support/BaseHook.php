<?php

namespace Sd1\IamSso\Support;

use Illuminate\Http\Request;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Branch\BranchContext;
use Sd1\IamSso\Contracts\BranchHook;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\SsoUser;

/**
 * Titik awal hook aplikasi: semua method sudah punya perilaku default (tidak melakukan apa-apa),
 * aplikasi cukup meng-override yang dibutuhkan, dengan kode PHP biasa.
 *
 *   class AppSsoHook extends \Sd1\IamSso\Support\BaseHook
 *   {
 *       public function onLogin(SsoUser $user, PermissionSet $permissions, Request $request)
 *       {
 *           session(['user_code' => $user->ias_user_code]);
 *       }
 *   }
 *
 * Tambahkan `implements PermissionCatalogSource` / `UserMirrorHook` bila aplikasi memakai
 * sso:permission-push / sso:mirror-users.
 */
abstract class BaseHook implements LoginHook, BranchHook
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

    public function requiresBranch(): bool
    {
        return false;
    }

    public function connectionName(BranchContext $branch): string
    {
        return 'sso_branch';
    }

    public function connectionConfig(BranchContext $branch, array $default): array
    {
        return $default;
    }

    /**
     * Batalkan login dari dalam onLogin: hapus sesi SSO lokal (sesi IAM tetap) lalu tampilkan
     * halaman error SDK dengan pesan ini.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function cancelLogin(Request $request, string $message)
    {
        app('sso')->forget();
        $request->session()->flash('sso_error', $message);

        return redirect()->route('sso.error');
    }
}
