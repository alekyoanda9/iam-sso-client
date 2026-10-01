# sd1/iam-sso-client

SDK Laravel untuk login SSO ke **SSO IAM** (OAuth2 authorization code + JWT RS256) dan otorisasi menu/aksi terpusat.

- PHP **7.1+**, Laravel **5.8 – 12**. Tanpa library tambahan selain Guzzle 6/7 (sudah ada di IAS).
- JWT diverifikasi **lokal** dengan `openssl_verify`. Hanya RS256 yang diterima; `iss`, `aud`, `exp`, dan `nbf` ikut dicek.
- Perubahan hak akses di IAM terbaca **tanpa logout**. Pengecekannya berjalan otomatis lewat `perm_version`, paling sering sekali per 60 detik.
- **Generik.** Tidak ada kode khusus aplikasi tertentu di SDK. Semua perilaku khusus aplikasi (isi sesi lama, nama koneksi cabang, query DB, mirror user, katalog menu) ditulis sebagai **kode PHP biasa di satu kelas hook** milik aplikasi. `config/sso.php` hanya berisi koneksi ke IAM + nama kelas hook. Contoh lengkap IAS: [`examples/IasSsoHook.php`](examples/IasSsoHook.php).
- **v3.0.0** menghapus bridge/mirror/katalog berbasis config dari v2 (lihat [Migrasi](#8-migrasi-dari-v2)).

## 1. Pemasangan

**A. Composer path repository.** Folder SDK disalin ke dalam repo aplikasi, mis. `packages/iam-sso-client`:
```json
"repositories": [{ "type": "path", "url": "packages/iam-sso-client" }],
"require": { "sd1/iam-sso-client": "*" }
```
Setelah itu jalankan `composer update sd1/iam-sso-client`. Provider dan facade `Sso` terdaftar otomatis lewat package discovery.

**B. Tanpa Composer.** Pakai cara ini bila menambah package memang tidak diizinkan.
1. Salin `src/`, `config/`, `routes/`, dan `resources/` ke `packages/iam-sso-client/`.
2. Tambahkan autoload berikut di `composer.json` aplikasi, lalu jalankan `composer dump-autoload`:
   ```json
   "autoload": { "psr-4": { "Sd1\\IamSso\\": "packages/iam-sso-client/src/" } }
   ```
3. Daftarkan `Sd1\IamSso\SsoServiceProvider::class` di `config/app.php` → `providers`, dan alias `'Sso' => Sd1\IamSso\Facades\Sso::class`.

Terakhir, salin konfigurasi dengan:
```bash
php artisan vendor:publish --tag=sso-config
```
Pada cara B yang tidak memakai discovery, cukup salin `config/sso.php`.

## 2. Konfigurasi `.env`
```
SSO_BASE_URL=http://iam.indogrosir.lan
SSO_CLIENT_ID=1
SSO_CLIENT_SECRET=xxxxxxxx
# kosong = otomatis route('sso.callback') di host yang sedang diakses (cocok untuk IAS per cabang)
SSO_REDIRECT_URI=
SSO_POST_LOGOUT_REDIRECT=
SSO_JWT_ISSUER=http://iam.indogrosir.lan
```
Beberapa hal harus sudah beres di sisi IAM:
- **Redirect URI** callback aplikasi terdaftar persis sama di client. Untuk IAS per cabang, pakai `php artisan iam:client-redirects ias`.
- **Role user terpetakan** ke app role aplikasi ini lewat menu *Mapping Role*.
- **Mode MANAGED** dipakai bila menu dikelola di IAM. Katalognya diisi dengan `sso:permission-push`.

## 3. Pemakaian
```php
// routes/web.php
Route::middleware(['web', 'sso.auth'])->group(function () {
    Route::get('/laporan/x', 'LaporanXController@index')->middleware('sso.can');       // izin berdasarkan URL
    Route::post('/laporan/x/export', 'LaporanXController@export')->middleware('sso.can:BO190.EXPORT');
});
```
```php
Sso::check();                     // sudah login SSO?
$u = Sso::user();                 // SsoUser: nik, name, email, ias_user_code, ias_userlevel, branch_code, role_code, ...
Sso::can('BO190');                // punya permission berkode BO190?
Sso::canUrl('/bo/proses/monthend');
Sso::menus();                     // permission MENU terurut
Sso::setBranchType('SPI');        // setelah user HO memilih cabang -> menu difilter ulang per tipe cabang
```
```blade
@ssocan('BO190.EXPORT') <button>Export</button> @endssocan
```

Route bawaan (prefix `sso`, middleware `web`):

| Route | Fungsi |
|---|---|
| `/sso/login` | Arahkan ke IAM (dengan `state`) |
| `/sso/callback` | Tukar code, verifikasi JWT, ambil hak akses, panggil hook |
| `/sso/logout` | Hook `onLogout`, hapus sesi, logout di IAM |
| `/sso/error` | Pesan gagal login |

### Semantik `canUrl` = `AccessController::isAccessible()` IAS
- `/` selalu boleh.
- URL diizinkan bila **diawali** url salah satu MENU. Pencocokannya awalan mentah, jadi `/bo/proses/monthend` juga meloloskan `/bo/proses/monthend/getData`.
- `matchUrl()` mengembalikan permission yang cocok + `exact` (IAS mencatat log menu hanya saat url sama persis).
- **Beda dari IAS:** permission tanpa url (ACTION) tidak pernah dipakai untuk mencocokkan URL. Di IAS, `acc_url` kosong meloloskan semua URL.

### Siklus hak akses
- **Pengecekan versi.** Middleware `sso.auth` memanggil `GET /api/me/access/version`, paling sering sekali per `SSO_VERSION_CHECK_SECONDS` (default 60). Sisanya tanpa panggilan ke IAM.
- **Versi berubah:** identitas (JWT) dan hak akses diambil ulang, lalu `onAccessRefreshed` dipanggil.
- **`active=false`** (user nonaktif atau akses dicabut): `onLogout` dipanggil, sesi dihapus, dan user diarahkan ke login ulang.
- **Access token kedaluwarsa:** diperbarui otomatis dengan refresh token. Kalau gagal, user diarahkan ke login ulang.
- **Mematikan fitur ini** (`SSO_ACCESS_REFRESH=false`): tidak ada satu pun panggilan ke IAM selama sesi. Perubahan menu/role baru berlaku setelah logout lalu login lagi. User yang dinonaktifkan di IAM tetap bisa bekerja sampai ia logout atau JWT identitasnya habis (`IAM_JWT_TTL` di IAM, default 24 jam); setelah itu SDK memaksa login ulang (dicek lokal) dan login ulang ditolak IAM.
- **IAM tidak bisa dihubungi saat pengecekan:** dengan `fail_open=true` (default), hak akses terakhir di sesi tetap dipakai dan pengecekan dicoba lagi sekitar 15 detik kemudian. Login baru tetap butuh IAM.

### Login multi-cabang (aplikasi yang terhubung ke DB tiap cabang)
Untuk aplikasi seperti IAS, pilihan **cabang + koneksi (PRODUCTION/SIMULASI)** ada di halaman login IAM, bukan di aplikasi. Aplikasi tidak perlu tahu webservice `GetConnectionPGDetail`, kunci AES, atau daftar cabang.

1. Di IAM (App Management), centang **Login multi-cabang** untuk client ini dan pilih koneksi yang boleh dipakai.
2. Di hook aplikasi (turunan `BaseHook`, yang sudah mengimplementasikan `Contracts\BranchHook`):
   ```php
   public function requiresBranch(): bool { return true; }               // tolak login bila IAM tidak mengirim pilihan cabang
   public function connectionName(BranchContext $b): string { return 'sso_branch'; }   // default; IAS: igrjkt/simjkt/spibks
   public function connectionConfig(BranchContext $b, array $default): array { return $default; }  // tambah opsi bila perlu
   ```
3. Pakai koneksinya:
   ```php
   DB::connection(Sso::connectionName())->table('tbmaster_perusahaan')->first();
   $b = Sso::branch();   // code(), name(), type(), kode(), env(), isProduction(), connection(), host('SIMULASI')
   ```

Alurnya: IAM menambahkan `iam_ctx` di redirect callback → `/sso/callback` menukarnya di `GET /api/me/branch-context` (sekali pakai, dengan access token user) → konteks disimpan **terenkripsi** di sesi → koneksi `database.connections.{nama}` didaftarkan setiap request oleh `sso.auth` (atau `sso.branch` untuk route yang tidak lewat `sso.auth`). Menu otomatis difilter sesuai tipe cabang yang dimasuki.

Aturan cabang ditegakkan IAM: user cabang selalu masuk ke cabangnya sendiri; user Head Office memilih cabang mana pun; bila aplikasi diakses lewat server cabang (redirect URI = server cabang), cabang dikunci ke cabang server itu.

Aplikasi biasa yang memakai satu DB tidak perlu mengubah apa pun (`requiresBranch()` = `false`, `Sso::branch()` = `null`).

## 4. Hook aplikasi (menyambungkan aplikasi existing)

Aplikasi lama biasanya sudah punya kunci sesi sendiri (`Session::get('usid')`, `Session::get('menu')`, `DB::connection(Session::get('connection'))`, ...).
Semua itu diisi di **satu kelas hook** milik aplikasi, didaftarkan di `config/sso.php`:

```php
'hook' => App\Sso\AppSsoHook::class,
```

```php
namespace App\Sso;

use Illuminate\Http\Request;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Support\BaseHook;
use Sd1\IamSso\SsoUser;

class AppSsoHook extends BaseHook
{
    public function onLogin(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        if (! $user->get('ias_user_code')) {
            return $this->cancelLogin($request, 'Akun Anda belum memiliki kode user.');   // batal + halaman /sso/error
        }
        $request->session()->put('usid', $user->get('ias_user_code'));
        // query DB cabang: DB::connection(app('sso')->connectionName())->table(...)
        return null;   // lanjut ke URL intended
    }

    public function onLogout(Request $request)
    {
        $request->session()->forget(['usid']);
    }
}
```

| Method | Kapan dipanggil | Default `BaseHook` |
|---|---|---|
| `onLogin($user, $permissions, $request)` | Sekali setelah login. `Sso::branch()` dan koneksi cabang sudah siap. Kembalikan Response untuk mengganti tujuan redirect (mis. `cancelLogin()`). | tidak melakukan apa-apa |
| `onAccessRefreshed(...)` | Saat `perm_version` berubah (`access_refresh = true`). Kembalikan Response untuk menghentikan request. | tidak melakukan apa-apa |
| `onLogout($request)` | Sebelum sesi dihapus. | tidak melakukan apa-apa |
| `requiresBranch()` / `connectionName()` / `connectionConfig()` | Login multi-cabang (`Contracts\BranchHook`). | `false` / `'sso_branch'` / config dari IAM |
| `items(array $options)` | `sso:permission-push` — hook perlu `implements Contracts\PermissionCatalogSource`. | — |
| `mirrorUser($connection, $branchCode, $user)` | `sso:mirror-users` — hook perlu `implements Contracts\UserMirrorHook`. | — |

Contoh lengkap untuk Web IAS (sesi lama, menu `acc_*`, `tbmaster_perusahaan`, mirror `tbmaster_user`, katalog `tbmaster_access_migrasi`), diuji di `tests/Feature/IasHookExampleTest.php`:
**[`examples/IasSsoHook.php`](examples/IasSsoHook.php)** + **[`examples/ias-sso-config.php`](examples/ias-sso-config.php)**.

## 5. Commands
```bash
# Kirim katalog menu (hook implements PermissionCatalogSource)
php artisan sso:permission-push --connection=igrjkt --dry-run
php artisan sso:permission-push --connection=igrjkt           # upsert
php artisan sso:permission-push --connection=igrjkt --full    # + nonaktifkan kode yang tidak dikirim

# Mirror user dari IAM ke tabel user lokal (hook implements UserMirrorHook; jadwalkan berkala)
php artisan sso:mirror-users --branch=01 --connection=igrjkt --connection=simjkt [--since=2026-09-29T00:00:00+07:00]
```

## 6. Pengujian
```bash
composer install && vendor/bin/phpunit                       # dengan Orchestra Testbench
SSO_TEST_APP=/path/app-laravel vendor/bin/phpunit -c /path/sso-client/phpunit.xml   # tanpa testbench: pakai aplikasi host
php tools/php71-check.php                                    # cek sintaks/fungsi PHP 7.1 di src/
```
49 test mencakup:
- verifikasi JWT: tanda tangan palsu, `alg none`/HS256, kedaluwarsa, `aud`/`iss` salah, rotasi kunci;
- `state` OAuth: salah dan replay;
- alur `perm_version`: tidak berubah, berubah, user nonaktif, IAM mati, refresh token;
- semantik `canUrl` dan filter tipe cabang;
- hook contoh IAS: sesi lama, tanpa cegatan IP, usertype dari klaim (bukan email), mirror (password & email lama tidak tersentuh, baris ganda buatan SSO dinonaktifkan), katalog;
- kedua command.

## 7. Integrasi aplikasi baru vs existing (ringkas)

| | Aplikasi baru (1 DB) | Aplikasi existing multi-cabang (mis. IAS) |
|---|---|---|
| IAM | daftar client, mapping role | + centang *Login multi-cabang* |
| Kode | `Route::middleware('sso.auth')`, `Sso::user()`, `sso.can` | panggil middleware `sso.auth` dari middleware login lama; pakai `Sso::matchUrl()` di pengecekan menu lama; **satu kelas hook** (salin dari `examples/IasSsoHook.php`) |
| Config | `.env` SSO_* | `.env` SSO_* + `'hook' => App\Sso\...` |

## 8. Migrasi dari v2

| v2 (config) | v3 (kode di hook) |
|---|---|
| `sso.bridge.*` (session, menu, required, queries, on_login, on_logout, forget, logout_on_branch_change) + `BridgeLoginHook`, `SessionBridge`, `ValueResolver` | `onLogin` / `onAccessRefreshed` / `onLogout` di hook aplikasi |
| `sso.branch.*` (enabled, connection_name, env_prefixes, overrides, options) + `ConnectionNamer` | `requiresBranch()` / `connectionName()` / `connectionConfig()` |
| `sso.mirror.*` + `Mirror\UserMirror` | `mirrorUser()` (`Contracts\UserMirrorHook`) |
| `sso.permission_push.*` + `Catalog\TableCatalogSource` | `items()` (`Contracts\PermissionCatalogSource`) |

Langkah: salin `examples/IasSsoHook.php` ke `app/Sso/IasSsoHook.php`, hapus bagian `branch`, `bridge`, `mirror`, `permission_push` dari `config/sso.php`, isi `'hook' => App\Sso\IasSsoHook::class`, lalu `php artisan config:clear`.
`sso:mirror-users` sekarang selalu butuh `--branch` dan `--connection`.
