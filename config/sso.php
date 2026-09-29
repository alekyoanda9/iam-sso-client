<?php

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
    | Hook aplikasi (implementasi Sd1\IamSso\Contracts\LoginHook)
    |--------------------------------------------------------------------------
    | Tempat aplikasi mengisi sesi lamanya (mis. adapter sesi IAS), memilih cabang, dsb.
    */
    'hook' => Sd1\IamSso\Support\NullLoginHook::class,

    /*
    |--------------------------------------------------------------------------
    | sso:permission-push
    |--------------------------------------------------------------------------
    | source : class implementasi Sd1\IamSso\Contracts\PermissionCatalogSource
    */
    'permission_push' => [
        'source' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Khusus adapter IAS (Sd1\IamSso\Ias\*)
    |--------------------------------------------------------------------------
    */
    'ias' => [
        // Menu tbmaster_access_migrasi yang TIDAK dikirim ke IAM karena digantikan IAM:
        // Master User (/administration/user) dan akses menu per user (/administration/access).
        // Dicocokkan persis dengan acc_url. excluded_groups mengecualikan satu grup penuh (default kosong).
        'excluded_urls' => ['/administration/user', '/administration/access'],
        'excluded_groups' => [],
        // Kode cabang untuk sso:mirror-users (mode cabang IAS memakai KODEIGR).
        'branch' => env('KODEIGR'),
        // Nilai create_by/modify_by saat mirror menulis tbmaster_user.
        'mirror_actor' => 'SSO',
    ],
];
