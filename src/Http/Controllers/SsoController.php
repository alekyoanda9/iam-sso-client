<?php

namespace Sd1\IamSso\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\SsoManager;

/**
 * GET  /sso/login     -> redirect ke IAM /oauth/authorize (dengan state)
 * GET  /sso/callback  -> validasi state, tukar code, verifikasi JWT, ambil hak akses, panggil hook
 * GET|POST /sso/logout -> hook onLogout, hapus sesi lokal, redirect ke logout IAM
 * GET  /sso/error     -> halaman pesan kegagalan login
 */
class SsoController extends Controller
{
    public function login(Request $request, SsoManager $sso)
    {
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

    public function callback(Request $request, SsoManager $sso, LoginHook $hook)
    {
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
            $user = $sso->completeLogin($tokens);
        } catch (Exception $e) {
            Log::warning('[sso] callback gagal: ' . $e->getMessage());

            return $this->fail($request, $e->getCode() === 403
                ? 'Akun Anda belum memiliki akses ke aplikasi ini.'
                : 'Login SSO gagal: ' . $e->getMessage());
        }

        $request->session()->regenerate();

        $response = $hook->onLogin($user, $sso->permissions(), $request);
        if ($response) {
            return $response;
        }

        return redirect()->intended($sso->config('home', '/'));
    }

    public function logout(Request $request, SsoManager $sso, LoginHook $hook)
    {
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
