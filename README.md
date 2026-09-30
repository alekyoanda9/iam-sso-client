# sd1/iam-sso-client

SDK Laravel untuk login SSO ke **SSO IAM** (OAuth2 authorization code + JWT RS256) dan otorisasi menu/aksi terpusat.

- PHP **7.1+**, Laravel **5.8 – 12**. Tanpa library tambahan selain Guzzle 6/7 (sudah ada di IAS).
- JWT diverifikasi **lokal** dengan `openssl_verify`. Hanya RS256 yang diterima; `iss`, `aud`, `exp`, dan `nbf` ikut dicek.
- Perubahan hak akses di IAM terbaca **tanpa logout**. Pengecekannya berjalan otomatis lewat `perm_version`, paling sering sekali per 60 detik.
- **Generik.** Tidak ada kode khusus aplikasi tertentu di SDK. Aplikasi existing (mis. IAS) disambungkan lewat **config** (`sso.bridge`, `sso.mirror`, `sso.permission_push`), tanpa menulis kelas; contoh lengkap IAS ada di [`examples/ias-sso.php`](examples/ias-sso.php).
- **v2.0.0** menghapus `Sd1\IamSso\Ias\*` (lihat [Migrasi dari v1](#8-migrasi-dari-v1)).

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
2. Di aplikasi:
   ```
   SSO_BRANCH_LOGIN=true            # tolak login bila IAM tidak mengirim pilihan cabang
   SSO_BRANCH_CONNECTION=sso_branch # nama tetap, atau pola di config: '{env_prefix}{kode}'
   ```
3. Pakai koneksinya:
   ```php
   DB::connection(Sso::connectionName())->table('tbmaster_perusahaan')->first();
   $b = Sso::branch();   // code(), name(), type(), kode(), env(), isProduction(), connection(), host('SIMULASI')
   ```

Alurnya: IAM menambahkan `iam_ctx` di redirect callback → `/sso/callback` menukarnya di `GET /api/me/branch-context` (sekali pakai, dengan access token user) → konteks disimpan **terenkripsi** di sesi → koneksi `database.connections.{nama}` didaftarkan setiap request oleh `sso.auth` (atau `sso.branch` untuk route yang tidak lewat `sso.auth`). Menu otomatis difilter sesuai tipe cabang yang dimasuki.

Aturan cabang ditegakkan IAM: user cabang selalu masuk ke cabangnya sendiri; user Head Office memilih cabang mana pun; bila aplikasi diakses lewat server cabang (redirect URI = server cabang), cabang dikunci ke cabang server itu.

Aplikasi biasa yang memakai satu DB tidak perlu mengubah apa pun (`SSO_BRANCH_LOGIN=false`, `Sso::branch()` = `null`).

## 4. Menyambungkan aplikasi existing tanpa menulis kode

Aplikasi lama biasanya sudah punya kunci sesi sendiri (`Session::get('usid')`, `Session::get('menu')`, `DB::connection(Session::get('connection'))`, ...). Hook bawaan **`Sd1\IamSso\Bridge\BridgeLoginHook`** mengisi semuanya dari hasil SSO berdasarkan `config/sso.php`, jadi controller lama tidak perlu diubah.

| Bagian config | Fungsi |
|---|---|
| `bridge.session` | `[kunci sesi => spec]`, mis. `'usid' => 'user.ias_user_code'`, `'connection' => 'branch.connection_name'`, `'id' => 'request.ip\|strip:.'` |
| `bridge.menu` | Permission MENU → bentuk menu lama: `fields => [kolom lama => code/name/url/group/subgroup1..3/order/level]`, `format => collection\|objects\|arrays` |
| `bridge.required` | `[spec => pesan]` — login dibatalkan dengan pesan itu bila nilainya kosong |
| `bridge.queries` | `SELECT` satu baris dari DB cabang → kunci sesi (`into => [kunci => 'row.kolom']`), `required` + `error` bertemplate |
| `bridge.on_login` / `on_logout` | Statement `UPDATE/INSERT` dengan binding bernama; `on_logout.when` = syarat |
| `bridge.forget` | Kunci sesi lain yang dibersihkan saat login/logout |
| `bridge.logout_on_branch_change` | User cabang yang dimutasi saat sedang login → logout (butuh `access_refresh`) |
| `mirror` | Salin user IAM ke tabel user lokal (kolom yang disebut saja; password dsb. tidak disentuh) |
| `permission_push` | Katalog menu dari satu tabel (`TableCatalogSource`) untuk `sso:permission-push` |
| `branch.connection_name` | Nama tetap, atau pola `{env_prefix}{kode}` + `env_prefixes` + `connection_name_overrides` (`'PRODUCTION:SPI' => '{kode}'`) |
| `enabled` | `false` → `/sso/login` menolak & kembali ke `/login` (mis. server masih mode login lama) |

**Spec nilai** (`Sd1\IamSso\Bridge\ValueResolver`, aman untuk `config:cache`):

| Sumber | Contoh |
|---|---|
| `user.*` | klaim JWT: `user.nik`, `user.ias_user_code`, `user.role_code`, `user.email` |
| `branch.*` | `branch.code`, `branch.name`, `branch.kode`, `branch.type`, `branch.env`, `branch.connection_name`, `branch.connection.password`, `branch.hosts.PRODUCTION` |
| `request.*` | `request.ip`, `request.host` |
| `session.*` / `row.*` / `context.*` | kunci sesi, kolom hasil query, nilai tambahan (mis. `context.branch_code` di mirror) |
| literal | `value:5432`, `['value' => []]`, `now`, `null`, `template:http://{request.host}:3050` |

Transform setelah `|`, berurutan: `upper lower trim ucfirst string int empty_null strip:X max:N substr:S,L default:X prefix:SM=SM,SJM=SJM,*=XXX`.

Contoh lengkap untuk Web IAS — menggantikan seluruh adapter IAS v1 dengan hasil yang sama (diuji di `tests/Feature/LegacyBridgeTest.php`): **[`examples/ias-sso.php`](examples/ias-sso.php)**.

Butuh logika yang tidak bisa ditulis di config? Extend `BridgeLoginHook` (panggil `parent::`) atau implementasikan `Sd1\IamSso\Contracts\LoginHook` sendiri, lalu daftarkan di `sso.hook`:

| Method | Kapan dipanggil |
|---|---|
| `onLogin($user, $permissions, $request)` | Sekali setelah login. `Sso::branch()` dan koneksi cabang sudah siap. Kembalikan Response untuk mengganti tujuan redirect. |
| `onAccessRefreshed(...)` | Saat `perm_version` berubah (`access_refresh = true`). Kembalikan Response untuk menghentikan request. |
| `onLogout($request)` | Sebelum sesi dihapus. |

## 5. Commands
```bash
# Kirim katalog menu (config sso.permission_push)
php artisan sso:permission-push --connection=igrjkt --dry-run
php artisan sso:permission-push --connection=igrjkt           # upsert
php artisan sso:permission-push --connection=igrjkt --full    # + nonaktifkan kode yang tidak dikirim

# Mirror user dari IAM ke tabel user lokal (config sso.mirror; jadwalkan berkala)
php artisan sso:mirror-users --branch=01 --connection=igrjkt --connection=simjkt [--since=2026-09-29T00:00:00+07:00]
```

## 6. Pengujian
```bash
composer install && vendor/bin/phpunit                       # dengan Orchestra Testbench
SSO_TEST_APP=/path/app-laravel vendor/bin/phpunit -c /path/sso-client/phpunit.xml   # tanpa testbench: pakai aplikasi host
php tools/php71-check.php                                    # cek sintaks/fungsi PHP 7.1 di src/
```
46 test mencakup:
- verifikasi JWT: tanda tangan palsu, `alg none`/HS256, kedaluwarsa, `aud`/`iss` salah, rotasi kunci;
- `state` OAuth: salah dan replay;
- alur `perm_version`: tidak berubah, berubah, user nonaktif, IAM mati, refresh token;
- semantik `canUrl` dan filter tipe cabang;
- adapter IAS dan mirror (password lama tidak tersentuh);
- kedua command.

## 7. Integrasi aplikasi baru vs existing (ringkas)

| | Aplikasi baru (1 DB) | Aplikasi existing multi-cabang (mis. IAS) |
|---|---|---|
| IAM | daftar client, mapping role | + centang *Login multi-cabang* |
| Kode | `Route::middleware('sso.auth')`, `Sso::user()`, `sso.can` | panggil middleware `sso.auth` dari middleware login lama; pakai `Sso::matchUrl()` di pengecekan menu lama |
| Config | `.env` SSO_* | + `branch`, `bridge`, `mirror`, `permission_push` (salin dari `examples/`) |

## 8. Migrasi dari v1

| v1 (`Sd1\IamSso\Ias\*`) | v2 (config) |
|---|---|
| `IasSessionWriter`, `IasBranchSessionWriter`, `IasMenuMapper`, hook aplikasi | `sso.bridge` + hook bawaan `BridgeLoginHook` |
| `IasConnectionNamer` | `sso.branch.connection_name = '{env_prefix}{kode}'` + `env_prefixes` + `connection_name_overrides` |
| `TbmasterUserMirror` | `sso.mirror` (`Sd1\IamSso\Mirror\UserMirror`) |
| `AccessMigrasiCatalogSource` | `sso.permission_push` (`Sd1\IamSso\Catalog\TableCatalogSource`) |
| `sso.ias.*` | dihapus |

Langkah: salin isi `examples/ias-sso.php` ke `config/sso.php` aplikasi, hapus hook aplikasi (mis. `SsoIasHook`) dan kosongkan `sso.hook` (pakai default), lalu `php artisan config:clear`.
