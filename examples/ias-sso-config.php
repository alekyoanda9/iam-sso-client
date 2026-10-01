<?php

/*
 * Contoh config/sso.php untuk Web IAS (SDK v3): hanya koneksi ke IAM + nama kelas hook.
 * Semua perilaku khusus IAS ada di examples/IasSsoHook.php (salin ke app/Sso/IasSsoHook.php).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Koneksi ke IAM
    |--------------------------------------------------------------------------
    | base_url      : URL IAM, mis. http://iam.indogrosir.lan (tanpa slash akhir)
    | client_id     : ID client OAuth (menu Clients di IAM)
    | client_secret : secret client (confidential)
    | redirect_uri  : kosongkan -> otomatis route('sso.callback') di host yang sedang diakses.
    |                 URL ini HARUS persis terdaftar di IAM (iam:client-redirects).
    */
    // SSO hanya ditawarkan bila AUTH_MODE = hybrid / sso (config/ias_auth.php).
    // legacy -> /sso/login menolak dan kembali ke /login.
    'enabled' => strtolower((string) env('AUTH_MODE', 'legacy')) !== 'legacy',
    'disabled_redirect' => null,

    'base_url' => env('SSO_BASE_URL'),
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
    'redirect_uri' => env('SSO_REDIRECT_URI'),

    'http' => [
        'connect_timeout' => (float) env('SSO_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('SSO_TIMEOUT', 15),
        'verify' => env('SSO_HTTP_VERIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verifikasi JWT (RS256, lokal)
    |--------------------------------------------------------------------------
    | public_key      : isi PEM langsung (opsional)
    | public_key_path : path file PEM (opsional)
    | Jika keduanya kosong, public key diambil dari {base_url}/api/public-key lalu di-cache.
    | issuer          : nilai klaim iss yang diharapkan (kosong = tidak dicek)
    */
    'jwt' => [
        'public_key' => env('SSO_PUBLIC_KEY'),
        'public_key_path' => env('SSO_PUBLIC_KEY_PATH'),
        'public_key_cache_minutes' => 60,
        'issuer' => env('SSO_JWT_ISSUER'),
        'leeway' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sesi & penyegaran hak akses
    |--------------------------------------------------------------------------
    | version_check_seconds : jeda minimal antar cek /api/me/access/version (per sesi).
    | fail_open             : true  -> IAM tidak bisa dihubungi saat cek versi = pakai hak akses
    |                                  terakhir di sesi (login baru tetap butuh IAM).
    |                         false -> paksa login ulang.
    */
    'session_key' => 'sso',
    // IAS: tanpa panggilan ke IAM selama sesi; perubahan menu/role berlaku setelah logout-login.
    // User nonaktif terputus saat logout atau JWT habis (IAM_JWT_TTL). true = perbarui tanpa logout.
    'access_refresh' => (bool) env('SSO_ACCESS_REFRESH', false),
    'version_check_seconds' => (int) env('SSO_VERSION_CHECK_SECONDS', 60),
    'fail_open' => true,

    /*
    |--------------------------------------------------------------------------
    | Route bawaan: /sso/login, /sso/callback, /sso/logout, /sso/error
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'sso',
        'middleware' => ['web'],
    ],

    // Tujuan setelah login bila tidak ada URL "intended".
    'home' => '/',

    // Tujuan setelah logout di IAM. HARUS terdaftar di post_logout_redirect_uris client.
    // Kosong -> url('/login').
    'post_logout_redirect' => env('SSO_POST_LOGOUT_REDIRECT'),

    /*
    |--------------------------------------------------------------------------
    | Hook IAS
    |--------------------------------------------------------------------------
    | Semua perilaku khusus IAS (isi Session lama, nama koneksi cabang igrjkt/simjkt/spibks,
    | query tbmaster_perusahaan, mirror tbmaster_user, katalog tbmaster_access_migrasi)
    | ditulis sebagai kode di app/Sso/IasSsoHook.php, bukan di config ini.
    */
    'hook' => App\Sso\IasSsoHook::class,
];
