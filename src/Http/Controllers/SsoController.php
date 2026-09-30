<?php

namespace Sd1\IamSso\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sd1\IamSso\Branch\BranchConnectionRegistrar;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\Exceptions\SsoException;
use Sd1\IamSso\SsoManager;

/**
 * GET  /sso/login     -> redirect ke IAM /oauth/authorize (dengan state)
 * GET  /sso/callback  -> validasi state, tukar code, verifikasi JWT, ambil hak akses,
 *                        ambil konteks cabang (iam_ctx, login multi-cabang), panggil hook
 * GET|POST /sso/logout -> hook onLogout, hapus sesi lokal, redirect ke logout IAM
 * GET  /sso/error     -> halaman pesan kegagalan login
 */
class SsoController extends Controller
{
    public function login(Request $request, SsoManager $sso)
    {
        if ($disabled = $this->disabled($sso)) {
            return $disabled;
        }
        $state = Str::random(40);
        $sso->session()->pushState($state);

        return redirect()->away($sso->client()->url('/oauth/authorize', [
            'client_id' => $sso->config('client_id'),
            'redirect_uri' => $this->redirectUri($sso),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
        ]));
    }

    public function callback(Request $request, SsoManager $sso, LoginHook $hook, BranchConnectionRegistrar $registrar)
    {
        if ($disabled = $this->disabled($sso)) {
            return $disabled;
        }
        if ($request->query('error')) {
            return $this->fail($request, 'Login SSO dibatalkan: ' . $request->query('error_description', $request->query('error')));
        }
        $state = (string) $request->query('state', '');
        if ($state === '' || ! $sso->session()->consumeState($state)) {
            return $this->fail($request, 'Sesi login kedaluwarsa atau tidak valid. Silakan ulangi login.');
        }
        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $this->fail($request, 'IAM tidak mengirim kode otorisasi.');
        }

        try {
            $tokens = $sso->client()->exchangeCode($code, $this->redirectUri($sso));
            $ctx = $request->query('iam_ctx');
            $user = $sso->completeLogin($tokens, is_string($ctx) ? $ctx : null);
        } catch (Exception $e) {
            Log::warning('[sso] callback gagal: ' . $e->getMessage());

            // SsoException murni = pesan yang memang untuk user (mis. pilihan cabang ditolak IAM).
            return $this->fail($request, get_class($e) === SsoException::class ? $e->getMessage() : ($e->getCode() === 403
                ? 'Akun Anda belum memiliki akses ke aplikasi ini.'
                : 'Login SSO gagal: ' . $e->getMessage()));
        }

        $request->session()->regenerate();
        $registrar->register($sso->branch());

        $response = $hook->onLogin($user, $sso->permissions(), $request);
        if ($response) {
            return $response;
        }

        return redirect()->intended($sso->config('home', '/'));
    }

    public function logout(Request $request, SsoManager $sso, LoginHook $hook, BranchConnectionRegistrar $registrar)
    {
        // Hook logout biasanya menulis ke DB cabang (mis. IAS mengosongkan useraktif).
        $registrar->register($sso->branch());
        $hook->onLogout($request);
        $sso->forget();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $back = $sso->config('post_logout_redirect') ?: url('/login');

        return redirect()->away($sso->client()->url('/logout', [
            'client_id' => $sso->config('client_id'),
            'redirect_back' => $back,
        ]));
    }

    public function error(Request $request)
    {
        return response()->view('sso::error', [
            'message' => $request->session()->get('sso_error', 'Login SSO gagal.'),
        ], 403);
    }

    /** SSO dimatikan di server ini (config sso.enabled=false) -> kembali ke halaman login aplikasi. */
    private function disabled(SsoManager $sso)
    {
        if ($sso->config('enabled', true)) {
            return null;
        }

        return redirect($sso->config('disabled_redirect') ?: url('/login'))
            ->with('sso_message', 'Login SSO belum diaktifkan di server ini.');
    }

    private function redirectUri(SsoManager $sso): string
    {
        return $sso->config('redirect_uri') ?: route('sso.callback');
    }

    private function fail(Request $request, string $message)
    {
        $request->session()->flash('sso_error', $message);

        return redirect()->route('sso.error');
    }
}
