<?php

namespace Sd1\IamSso;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Log;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Exceptions\IamRequestException;
use Sd1\IamSso\Exceptions\IamUnavailableException;
use Sd1\IamSso\Exceptions\InvalidTokenException;
use Sd1\IamSso\Exceptions\SsoException;
use Sd1\IamSso\Http\IamClient;
use Sd1\IamSso\Jwt\JwtVerifier;
use Sd1\IamSso\Session\SsoSession;

/**
 * Pintu utama SDK (facade Sso).
 *
 *   Sso::check()           sudah login SSO?
 *   Sso::user()            SsoUser|null (klaim JWT)
 *   Sso::can('BO190')      punya permission?
 *   Sso::canUrl('/bo/..')  boleh buka URL? (semantik isAccessible IAS)
 *   Sso::menus()           permission MENU terurut
 *   Sso::setBranchType()   tipe cabang aktif (user HO setelah memilih cabang)
 */
class SsoManager
{
    const STATUS_GUEST = 'guest';
    const STATUS_OK = 'ok';
    const STATUS_REFRESHED = 'refreshed';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_RELOGIN = 'relogin';

    /** @var IamClient */
    private $client;

    /** @var JwtVerifier */
    private $verifier;

    /** @var Session */
    private $laravelSession;

    /** @var array */
    private $config;

    /** @var PermissionSet|null */
    private $permissionCache;

    public function __construct(IamClient $client, JwtVerifier $verifier, Session $session, array $config)
    {
        $this->client = $client;
        $this->verifier = $verifier;
        $this->laravelSession = $session;
        $this->config = $config;
    }

    public function session(): SsoSession
    {
        return new SsoSession($this->laravelSession, $this->config['session_key']);
    }

    public function client(): IamClient
    {
        return $this->client;
    }

    public function check(): bool
    {
        return $this->session()->has();
    }

    /** @return SsoUser|null */
    public function user()
    {
        $claims = $this->session()->get('claims');

        return $claims ? new SsoUser($claims) : null;
    }

    public function permissions(): PermissionSet
    {
        if ($this->permissionCache) {
            return $this->permissionCache;
        }
        $access = $this->session()->get('access');
        if (! $access) {
            return PermissionSet::none();
        }

        return $this->permissionCache = new PermissionSet(
            isset($access['permissions']) ? $access['permissions'] : [],
            $this->activeBranchType(),
            isset($access['authz_mode']) ? $access['authz_mode'] : 'ROLE_ONLY'
        );
    }

    public function can(string $code): bool
    {
        return $this->permissions()->can($code);
    }

    public function canAny(array $codes): bool
    {
        return $this->permissions()->canAny($codes);
    }

    public function canUrl(string $path): bool
    {
        return $this->permissions()->canUrl($path);
    }

    public function matchUrl(string $path)
    {
        return $this->permissions()->matchUrl($path);
    }

    public function menus(): array
    {
        return $this->permissions()->menus();
    }

    /** @return array payload /api/me/access mentah */
    public function access(): array
    {
        return (array) $this->session()->get('access', []);
    }

    public function permVersion()
    {
        $access = $this->access();

        return isset($access['perm_version']) ? $access['perm_version'] : null;
    }

    /** Tipe cabang aktif: pilihan eksplisit (user HO) atau tipe cabang user. */
    public function activeBranchType()
    {
        $explicit = $this->session()->get('branch_type');
        if ($explicit) {
            return $explicit;
        }
        $user = $this->user();

        return $user ? $user->branchType() : null;
    }

    /** Dipanggil aplikasi setelah user (HO) memilih cabang: menu difilter ulang sesuai tipenya. */
    public function setBranchType($type)
    {
        $this->session()->put(['branch_type' => $type ? strtoupper($type) : null]);
        $this->permissionCache = null;
    }

    public function accessToken()
    {
        $tokens = $this->session()->get('tokens', []);

        return isset($tokens['access_token']) ? $tokens['access_token'] : null;
    }

    /**
     * Selesaikan login: token -> JWT identitas (diverifikasi) -> hak akses -> sesi.
     *
     * @throws SsoException
     */
    public function completeLogin(array $tokenResponse): SsoUser
    {
        if (empty($tokenResponse['access_token'])) {
            throw new SsoException('IAM tidak mengembalikan access token.');
        }
        $tokens = $this->normalizeTokens($tokenResponse, []);
        $claims = $this->fetchClaims($tokens['access_token']);
        $access = $this->client->access($tokens['access_token']);

        $this->session()->clear();
        $this->session()->put([
            'tokens' => $tokens,
            'claims' => $claims,
            'access' => $access,
            'checked_at' => time(),
            'logged_in_at' => time(),
            'branch_type' => null,
        ]);
        $this->permissionCache = null;

        return new SsoUser($claims);
    }

    /**
     * Cek berkala (dipanggil middleware sso.auth). Maksimal sekali per version_check_seconds.
     *
     * @return string salah satu STATUS_*
     */
    public function refreshIfStale(bool $force = false): string
    {
        if (! $this->check()) {
            return self::STATUS_GUEST;
        }
        $session = $this->session();
        $now = time();
        if (! $force && $now - (int) $session->get('checked_at', 0) < (int) $this->config['version_check_seconds']) {
            return self::STATUS_OK;
        }

        try {
            $token = $this->validAccessToken();
            if ($token === null) {
                return self::STATUS_RELOGIN;
            }

            try {
                $version = $this->client->accessVersion($token);
            } catch (IamRequestException $e) {
                if ($e->getCode() !== 401 || ! ($token = $this->refreshAccessToken())) {
                    throw $e;
                }
                $version = $this->client->accessVersion($token);
            }

            if (empty($version['active'])) {
                return self::STATUS_INACTIVE;
            }

            $claims = (array) $session->get('claims', []);
            $claimsExpiring = isset($claims['exp']) && (int) $claims['exp'] - 300 < $now;
            $changed = ! isset($version['perm_version']) || $version['perm_version'] !== $this->permVersion();

            if ($changed || $claimsExpiring) {
                // Role/cabang user bisa berubah (mutasi) -> ambil ulang identitas juga.
                $session->put([
                    'claims' => $this->fetchClaims($token),
                    'access' => $this->client->access($token),
                ]);
                $this->permissionCache = null;
            }
            $session->put(['checked_at' => $now]);

            return $changed ? self::STATUS_REFRESHED : self::STATUS_OK;
        } catch (IamUnavailableException $e) {
            Log::warning('[sso] IAM tidak dapat dihubungi saat cek hak akses: ' . $e->getMessage());
            if (! empty($this->config['fail_open'])) {
                // Coba lagi ~15 detik kemudian, bukan setiap request.
                $session->put(['checked_at' => $now - (int) $this->config['version_check_seconds'] + 15]);

                return self::STATUS_OK;
            }

            return self::STATUS_RELOGIN;
        } catch (IamRequestException $e) {
            Log::info('[sso] sesi SSO tidak valid lagi: ' . $e->getMessage());

            return $e->getCode() === 403 ? self::STATUS_INACTIVE : self::STATUS_RELOGIN;
        } catch (InvalidTokenException $e) {
            Log::warning('[sso] JWT tidak valid saat penyegaran: ' . $e->getMessage());

            return self::STATUS_RELOGIN;
        }
    }

    /** Hapus data SSO dari sesi (tidak logout dari IAM). */
    public function forget()
    {
        $this->session()->clear();
        $this->permissionCache = null;
    }

    public function config(string $key, $default = null)
    {
        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function fetchClaims(string $accessToken): array
    {
        $data = $this->client->user($accessToken);
        if (empty($data['token'])) {
            throw new SsoException('IAM tidak mengembalikan JWT identitas.');
        }

        // Hanya klaim dari JWT terverifikasi yang dipakai (bukan field JSON biasa).
        return $this->verifier->verify($data['token']);
    }

    /** Access token yang masih berlaku (refresh bila hampir habis), atau null. */
    private function validAccessToken()
    {
        $tokens = $this->session()->get('tokens', []);
        if (empty($tokens['access_token'])) {
            return null;
        }
        if (! empty($tokens['expires_at']) && (int) $tokens['expires_at'] - 30 <= time()) {
            return $this->refreshAccessToken();
        }

        return $tokens['access_token'];
    }

    private function refreshAccessToken()
    {
        $tokens = $this->session()->get('tokens', []);
        if (empty($tokens['refresh_token'])) {
            return null;
        }
        try {
            $fresh = $this->normalizeTokens($this->client->refreshToken($tokens['refresh_token']), $tokens);
        } catch (IamUnavailableException $e) {
            throw $e;
        } catch (IamRequestException $e) {
            return null;
        }
        $this->session()->put(['tokens' => $fresh]);

        return $fresh['access_token'];
    }

    private function normalizeTokens(array $response, array $previous): array
    {
        return [
            'access_token' => $response['access_token'],
            'refresh_token' => isset($response['refresh_token']) ? $response['refresh_token'] : (isset($previous['refresh_token']) ? $previous['refresh_token'] : null),
            'expires_at' => isset($response['expires_in']) ? time() + (int) $response['expires_in'] : null,
        ];
    }
}
