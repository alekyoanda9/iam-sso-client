<?php

namespace Sd1\IamSso\Session;

use Illuminate\Contracts\Session\Session;

/**
 * Penyimpanan data SSO di sesi Laravel, di bawah satu kunci (config sso.session_key):
 *   tokens      : access_token, refresh_token, expires_at (unix)
 *   claims      : klaim JWT terverifikasi
 *   access      : payload /api/me/access
 *   checked_at  : unix waktu cek versi terakhir
 *   branch_type : tipe cabang aktif (bisa diganti setelah user HO memilih cabang)
 *   branch      : konteks cabang + koneksi dari IAM (terenkripsi, lihat SsoManager::branch())
 *   states      : state OAuth yang menunggu callback
 */
class SsoSession
{
    /** @var Session */
    private $session;

    /** @var string */
    private $key;

    public function __construct(Session $session, string $key)
    {
        $this->session = $session;
        $this->key = $key;
    }

    public function get(string $field, $default = null)
    {
        $data = $this->session->get($this->key, []);

        return is_array($data) && array_key_exists($field, $data) ? $data[$field] : $default;
    }

    public function put(array $fields)
    {
        $data = $this->session->get($this->key, []);
        $this->session->put($this->key, array_merge(is_array($data) ? $data : [], $fields));
    }

    public function has(): bool
    {
        return (bool) $this->get('claims');
    }

    public function clear()
    {
        $states = $this->get('states', []);
        $this->session->forget($this->key);
        if ($states) {
            $this->put(['states' => $states]);
        }
    }

    /** Simpan state OAuth baru (maks. 5 tertunda, masing-masing berlaku 10 menit). */
    public function pushState(string $state)
    {
        $now = time();
        $states = array_filter($this->get('states', []), function ($exp) use ($now) {
            return $exp > $now;
        });
        $states[$state] = $now + 600;
        if (count($states) > 5) {
            $states = array_slice($states, -5, 5, true);
        }
        $this->put(['states' => $states]);
    }

    /** Ambil & hapus state; true bila valid. */
    public function consumeState(string $state): bool
    {
        $states = $this->get('states', []);
        $valid = false;
        foreach ($states as $known => $exp) {
            if (hash_equals((string) $known, $state) && $exp > time()) {
                $valid = true;
            }
        }
        unset($states[$state]);
        $this->put(['states' => $states]);

        return $valid;
    }
}
