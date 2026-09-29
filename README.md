# sd1/iam-sso-client

SDK Laravel untuk login SSO ke **SSO IAM** (OAuth2 authorization code + JWT RS256) dan otorisasi menu/aksi terpusat.

- PHP **7.1+**, Laravel **5.8 – 12**. Tanpa library tambahan selain Guzzle 6/7 (sudah ada di IAS).
- JWT diverifikasi **lokal** dengan `openssl_verify`. Hanya RS256 yang diterima; `iss`, `aud`, `exp`, dan `nbf` ikut dicek.
- Perubahan hak akses di IAM terbaca **tanpa logout**. Pengecekannya berjalan otomatis lewat `perm_version`, paling sering sekali per 60 detik.
- Inti SDK (`Sd1\IamSso\*`) tidak tahu apa pun tentang IAS. Adapter IAS dipisah di `Sd1\IamSso\Ias\*`.

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
- **IAM tidak bisa dihubungi saat pengecekan:** dengan `fail_open=true` (default), hak akses terakhir di sesi tetap dipakai dan pengecekan dicoba lagi sekitar 15 detik kemudian. Login baru tetap butuh IAM.

## 4. Hook aplikasi
Implementasikan `Sd1\IamSso\Contracts\LoginHook` dan daftarkan di `config('sso.hook')`:

| Method | Kapan dipanggil |
|---|---|
| `onLogin($user, $permissions, $request)` | Sekali setelah login. Kembalikan Response untuk, misalnya, redirect ke halaman pilih cabang. |
| `onAccessRefreshed(...)` | Saat `perm_version` berubah. Kembalikan Response untuk menghentikan request, misalnya paksa logout bila cabang user berubah. |
| `onLogout($request)` | Sebelum sesi dihapus. |

## 5. Adapter IAS (`Sd1\IamSso\Ias`)

**`IasSessionWriter::writeUser($session, $user, $perms)`** mengisi kunci sesi lama:

| Kunci | Isi |
|---|---|
| `usid` | `ias_user_code` |
| `un` | nama |
| `eml` | email |
| `userlevel` | `ias_userlevel` |
| `usertype` | SM/SJM/XXX dari prefix email |
| `menu` | bentuk `acc_*` untuk `navbar.blade.php` |
| `specialUser` | `[]` |

Kunci cabang/koneksi (`kdigr`, `connection`, dst.) diisi alur pilih cabang di IAS.

**`IasMenuMapper`** mengubah permission MENU menjadi `stdClass` `acc_id`, `acc_group`, `acc_subgroup1..3`, `acc_name`, `acc_url`.

**`TbmasterUserMirror::upsert($connection, $kodeigr, $user)`** menulis mirror satu arah ke `tbmaster_user`:
- **Tidak pernah menulis** `userpassword`, `encryptpwd`, `finger*`, `jabatan`, atau `station`.
- `userlevel` hanya ditimpa bila IAM mengirim nilai.
- User nonaktif ditandai `recordid = '1'`.

**`AccessMigrasiCatalogSource`** adalah sumber katalog dari `tbmaster_access_migrasi`:
- dedup `acc_id` dengan `acc_modify_dt` terbaru;
- `acc_status '0'` = aktif;
- `acc_url` `/administration/user` (Master User) dan `/administration/access` (akses menu per user) dilewati karena digantikan IAM. Menu Administration lain (Unlock IP, Menu, API Menu, dst.) tetap dikirim, lalu SYSTEM menentukan role yang boleh memakainya.

### Commands
```bash
# Kirim katalog menu (config sso.permission_push.source = Sd1\IamSso\Ias\AccessMigrasiCatalogSource::class)
php artisan sso:permission-push --connection=igrjkt --dry-run
php artisan sso:permission-push --connection=igrjkt           # upsert
php artisan sso:permission-push --connection=igrjkt --full    # + nonaktifkan kode yang tidak dikirim

# Mirror user cabang dari IAM ke tbmaster_user (jadwalkan berkala)
php artisan sso:mirror-users --branch=01 --connection=igrjkt --connection=simjkt [--since=2026-09-29T00:00:00+07:00]
```

## 6. Pengujian
```bash
composer install && vendor/bin/phpunit                       # dengan Orchestra Testbench
SSO_TEST_APP=/path/app-laravel vendor/bin/phpunit -c /path/sso-client/phpunit.xml   # tanpa testbench: pakai aplikasi host
php tools/php71-check.php                                    # cek sintaks/fungsi PHP 7.1 di src/
```
31 test mencakup:
- verifikasi JWT: tanda tangan palsu, `alg none`/HS256, kedaluwarsa, `aud`/`iss` salah, rotasi kunci;
- `state` OAuth: salah dan replay;
- alur `perm_version`: tidak berubah, berubah, user nonaktif, IAM mati, refresh token;
- semantik `canUrl` dan filter tipe cabang;
- adapter IAS dan mirror (password lama tidak tersentuh);
- kedua command.
