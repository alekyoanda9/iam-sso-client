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
    // false -> /sso/login & /sso/callback menolak (mis. server masih memakai login lama).
    'enabled' => (bool) env('SSO_ENABLED', true),
    'disabled_redirect' => null, // null = url('/login')

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
    | access_refresh        : true  -> hak akses/menu diperbarui TANPA logout: saat user membuka halaman
    |                                  dan sudah >= version_check_seconds sejak cek terakhir, SDK memanggil
    |                                  /api/me/access/version (user dinonaktifkan juga langsung terputus).
    |                         false -> TIDAK ADA panggilan ke IAM selama sesi. Perubahan menu/role baru
    |                                  berlaku setelah logout-login. User yang dinonaktifkan tetap bisa
    |                                  bekerja sampai logout ATAU JWT identitasnya habis (IAM_JWT_TTL,
    |                                  default 24 jam) - setelah itu wajib login ulang (dicek lokal).
    | version_check_seconds : jeda minimal antar cek /api/me/access/version (per sesi).
    | fail_open             : true  -> IAM tidak bisa dihubungi saat cek versi = pakai hak akses
    |                                  terakhir di sesi (login baru tetap butuh IAM).
    |                         false -> paksa login ulang.
    */
    'session_key' => 'sso',
    'access_refresh' => (bool) env('SSO_ACCESS_REFRESH', true),
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
    | Login multi-cabang (client ber-flag "Login multi-cabang" di IAM)
    |--------------------------------------------------------------------------
    | User memilih cabang + koneksi (PRODUCTION/SIMULASI) di halaman login IAM. SDK mengambil
    | detail koneksi DB cabang itu dari IAM dan mendaftarkannya sebagai koneksi Laravel setiap
    | request (middleware sso.auth / sso.branch). Aplikasi tidak perlu tahu webservice/daftar cabang.
    |
    | enabled            : true -> login DITOLAK bila IAM tidak mengirim pilihan cabang
    |                      (pengaman salah konfigurasi). Client 1 DB: biarkan false.
    | connection_name    : nama tetap ('sso_branch'), pola ('{env_prefix}{kode}'), atau class
    |                      Sd1\IamSso\Contracts\ConnectionNamer.
    | env_prefixes       : [ENV => awalan] untuk {env_prefix}, mis. [PRODUCTION => igr, SIMULASI => sim]
    | connection_name_overrides : pola khusus per 'ENV:TIPE' atau 'ENV', mis. ['PRODUCTION:SPI' => '{kode}']
    |                      Pakai: DB::connection(Sso::connectionName()).
    | connection_options : tambahan config koneksi (mis. ['sslmode' => 'prefer']).
    */
    'branch' => [
        'enabled' => (bool) env('SSO_BRANCH_LOGIN', false),
        'connection_name' => env('SSO_BRANCH_CONNECTION', 'sso_branch'),
        // Dipakai bila connection_name berupa pola ({kode} {code} {type} {env} {env_prefix}).
        'env_prefixes' => [],
        'connection_name_overrides' => [],
        'connection_options' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hook aplikasi (implementasi Sd1\IamSso\Contracts\LoginHook)
    |--------------------------------------------------------------------------
    | Tempat aplikasi mengisi sesi lamanya (mis. adapter sesi IAS), memilih cabang, dsb.
    */
    'hook' => Sd1\IamSso\Bridge\BridgeLoginHook::class,

    /*
    |--------------------------------------------------------------------------
    | sso:permission-push
    |--------------------------------------------------------------------------
    | source : class implementasi Sd1\IamSso\Contracts\PermissionCatalogSource
    */
    'permission_push' => [
        // Sumber bawaan: satu tabel menu aplikasi (Sd1\IamSso\Catalog\TableCatalogSource).
        // Boleh diganti class sendiri yang mengimplementasikan PermissionCatalogSource.
        'source' => Sd1\IamSso\Catalog\TableCatalogSource::class,
        'table' => null,
        'connection' => null,
        // [field IAM => kolom tabel]: code (wajib), name, url, group, subgroup1..3, order, level
        'columns' => [],
        'type' => 'MENU',
        'active_column' => null,
        'active_value' => '1',
        'modified_columns' => [],
        'excluded_urls' => [],
        'excluded_groups' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bridge sesi lama (untuk aplikasi existing) - dipakai hook bawaan BridgeLoginHook
    |--------------------------------------------------------------------------
    | Mengisi kunci sesi yang dipakai kode lama aplikasi dari hasil SSO, tanpa menulis kelas.
    | Nilai berupa "spec" (lihat Sd1\IamSso\Bridge\ValueResolver):
    |   user.<klaim>  branch.<field>  request.ip|host  session.<kunci>  row.<kolom>
    |   value:teks  ['value' => apa saja]  now  template:teks {spec}
    |   + transform setelah '|': upper lower trim ucfirst string int empty_null strip:X max:N
    |     substr:S,L default:X prefix:A=B,*=C
    |
    | session   : [kunci sesi => spec]
    | menu      : ['key' => 'menu', 'fields' => [kolom lama => field permission],
    |              'require_url' => true, 'format' => collection|objects|arrays]
    |              field permission: code name url group subgroup1 subgroup2 subgroup3 order level
    | required  : [spec => pesan]  login dibatalkan bila nilainya kosong
    | queries   : [['sql' => 'select ...', 'bindings' => [nama => spec], 'into' => [kunci sesi => 'row.kolom'],
    |               'required' => bool, 'error' => 'pesan {session.connection}', 'connection' => 'branch'|nama]]
    | on_login  : [['sql' => 'update ...', 'bindings' => [...], 'required' => true]]
    | on_logout : [['sql' => 'update ...', 'bindings' => [...], 'when' => [spec yang wajib terisi]]]
    |             placeholder bernama (:nama) - tiap nama hanya dipakai SEKALI dalam satu SQL.
    | forget    : kunci sesi tambahan yang dihapus saat login/logout
    */
    'bridge' => [
        'enabled' => false,
        'session' => [],
        'menu' => null,
        'required' => [],
        'queries' => [],
        'on_login' => [],
        'on_logout' => [],
        'forget' => [],
        // User cabang yang dimutasi ke cabang lain saat sedang login -> paksa logout (butuh access_refresh).
        'logout_on_branch_change' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mirror user ke tabel user lokal (opsional)
    |--------------------------------------------------------------------------
    | Untuk aplikasi yang masih JOIN ke tabel user sendiri. Satu arah IAM -> tabel lokal;
    | hanya kolom yang disebut di sini yang ditulis. Lihat Sd1\IamSso\Mirror\UserMirror.
    | on_login          : mirror user yang login ke koneksi cabang terpilih
    | login_branch_code : spec kode cabang saat mirror on_login (default branch.code)
    | branch/connection : default untuk `php artisan sso:mirror-users`
    */
    'mirror' => [
        'enabled' => false,
        'on_login' => true,
        'login_branch_code' => 'branch.code',
        'branch' => env('SSO_MIRROR_BRANCH'),
        'connection' => null,
        'table' => null,
        'key' => ['column' => 'id', 'value' => 'user.id', 'max' => null],
        'columns' => [],
        'columns_if_set' => [],
        'when_active' => [],
        'when_inactive' => [],
        'on_insert' => [],
        'on_update' => [],
    ],
];
