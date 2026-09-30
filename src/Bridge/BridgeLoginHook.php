<?php

namespace Sd1\IamSso\Bridge;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\Exceptions\SsoException;
use Sd1\IamSso\Mirror\UserMirror;
use Sd1\IamSso\SsoManager;
use Sd1\IamSso\SsoUser;
use Throwable;

/**
 * Hook bawaan SDK (config sso.hook). Semua perilaku diatur lewat config, tanpa kode aplikasi:
 *  - sso.bridge  : isi sesi lama aplikasi (SessionBridge)
 *  - sso.mirror  : salin user ke tabel user lokal saat login (UserMirror, bila on_login)
 *  - sso.bridge.logout_on_branch_change : user cabang yang dimutasi saat sedang login -> logout
 * Bridge & mirror mati (default) = hook ini tidak melakukan apa-apa.
 *
 * Aplikasi yang butuh logika tambahan boleh meng-extend kelas ini dan memanggil parent::.
 */
class BridgeLoginHook implements LoginHook
{
    /** @var SessionBridge */
    protected $bridge;

    /** @var UserMirror */
    protected $mirror;

    /** @var SsoManager */
    protected $sso;

    /** @var ValueResolver */
    protected $values;

    public function __construct(SessionBridge $bridge, UserMirror $mirror, SsoManager $sso, ValueResolver $values)
    {
        $this->bridge = $bridge;
        $this->mirror = $mirror;
        $this->sso = $sso;
        $this->values = $values;
    }

    public function onLogin(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        if ($this->bridge->enabled()) {
            try {
                $this->bridge->login($user, $permissions, $request);
            } catch (SsoException $e) {
                return $this->fail($request, $e->getMessage());
            } catch (Throwable $e) {
                Log::error('[sso-bridge] login gagal: ' . $e->getMessage(), ['exception' => $e]);
                $branch = $this->sso->branch();

                return $this->fail($request, 'Gagal menyiapkan sesi aplikasi'
                    . ($branch ? ' untuk cabang ' . $branch->code() . ' (' . $branch->env() . ')' : '') . ': ' . $e->getMessage());
            }
        }

        if ($this->mirror->enabled() && config('sso.mirror.on_login', true) && $this->sso->connectionName()) {
            try {
                $context = $this->bridge->context($user, $request);
                $spec = config('sso.mirror.login_branch_code', 'branch.code');
                $this->mirror->upsert($this->sso->connectionName(), (string) $this->values->resolve($spec, $context), $user->toArray() + ['active' => true]);
            } catch (Throwable $e) {
                Log::warning('[sso-mirror] mirror saat login gagal: ' . $e->getMessage());
            }
        }

        return null;
    }

    public function onAccessRefreshed(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        $branch = $this->sso->branch();
        if ($branch && config('sso.bridge.logout_on_branch_change', true)
            && ! $user->isHeadOffice() && $user->branchCode() && $user->branchCode() != $branch->code()) {
            return redirect()->route('sso.logout');
        }
        if ($this->bridge->enabled()) {
            $this->bridge->refresh($user, $permissions, $request);
        }

        return null;
    }

    public function onLogout(Request $request)
    {
        if ($this->bridge->enabled()) {
            $this->bridge->logout($request);
        }
    }

    /** Batalkan login: hapus sesi SSO lokal (sesi IAM tetap) lalu tampilkan halaman error SDK. */
    protected function fail(Request $request, string $message)
    {
        $this->bridge->clear($request->session());
        $this->sso->forget();
        $request->session()->flash('sso_error', $message);

        return redirect()->route('sso.error');
    }
}
