<?php

namespace Sd1\IamSso\Contracts;

use Illuminate\Http\Request;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\SsoUser;

/**
 * Titik sambung aplikasi ke SDK (didaftarkan di config sso.hook).
 * Inti SDK tidak tahu apa pun tentang sesi lama aplikasi; semua itu di sini.
 */
interface LoginHook
{
    /**
     * Dipanggil sekali setelah login SSO berhasil (token & hak akses sudah di sesi).
     *
     * @return \Symfony\Component\HttpFoundation\Response|null  Response untuk dikirim
     *         (mis. redirect ke halaman pilih cabang), atau null = lanjut ke URL intended.
     */
    public function onLogin(SsoUser $user, PermissionSet $permissions, Request $request);

    /**
     * Dipanggil saat perm_version berubah (hak akses / identitas diperbarui dari IAM).
     *
     * @return \Symfony\Component\HttpFoundation\Response|null  Response untuk menghentikan
     *         request (mis. paksa logout bila cabang user berubah), atau null = lanjut.
     */
    public function onAccessRefreshed(SsoUser $user, PermissionSet $permissions, Request $request);

    /** Dipanggil sebelum sesi dihapus (logout manual, user dinonaktifkan, token tak bisa diperbarui). */
    public function onLogout(Request $request);
}
