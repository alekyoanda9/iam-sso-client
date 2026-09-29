<?php

namespace Sd1\IamSso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\SsoManager;

/**
 * sso.auth
 *  - Belum login SSO -> simpan URL tujuan, redirect ke /sso/login (AJAX: 401 JSON).
 *  - Sudah login     -> cek perm_version ke IAM (maks. sekali per version_check_seconds):
 *      berubah          -> sesi diperbarui + hook onAccessRefreshed
 *      user nonaktif / token tak bisa diperbarui -> hook onLogout, sesi dihapus, login ulang
 */
class Authenticate
{
    /** @var SsoManager */
    private $sso;

    /** @var LoginHook */
    private $hook;

    public function __construct(SsoManager $sso, LoginHook $hook)
    {
        $this->sso = $sso;
        $this->hook = $hook;
    }

    public function handle(Request $request, Closure $next)
    {
        if (! $this->sso->check()) {
            return $this->toLogin($request, null);
        }

        $status = $this->sso->refreshIfStale();

        if ($status === SsoManager::STATUS_INACTIVE || $status === SsoManager::STATUS_RELOGIN) {
            $this->hook->onLogout($request);
            $this->sso->forget();
            $message = $status === SsoManager::STATUS_INACTIVE
                ? 'Akses Anda ke aplikasi ini sudah dicabut atau akun dinonaktifkan.'
                : 'Sesi SSO berakhir, silakan login kembali.';

            return $this->toLogin($request, $message);
        }

        if ($status === SsoManager::STATUS_REFRESHED) {
            $response = $this->hook->onAccessRefreshed($this->sso->user(), $this->sso->permissions(), $request);
            if ($response) {
                return $response;
            }
        }

        return $next($request);
    }

    private function toLogin(Request $request, $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => $message ?: 'Belum login.'], 401);
        }
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }
        if ($message) {
            $request->session()->flash('sso_message', $message);
        }

        return redirect()->route('sso.login');
    }
}
