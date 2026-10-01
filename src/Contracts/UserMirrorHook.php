<?php

namespace Sd1\IamSso\Contracts;

/**
 * Opsional, diimplementasikan oleh hook aplikasi (config sso.hook) bila aplikasi masih punya
 * tabel user sendiri. Dipakai `php artisan sso:mirror-users`. Aplikasi menulis sendiri
 * query-nya (kolom apa yang ditulis, kapan), SDK hanya mengambil data user dari IAM.
 */
interface UserMirrorHook
{
    /**
     * @param string $connection koneksi DB tujuan (dari --connection)
     * @param string $branchCode kode cabang (dari --branch)
     * @param array  $user       satu user dari IAM GET /api/client/users: nik, ias_user_code, ias_userlevel,
     *                           ias_usertype, name, email, phone, branch_code, role_code, app_role, active, ...
     *
     * @return string 'inserted' | 'updated' | 'skipped'
     */
    public function mirrorUser(string $connection, string $branchCode, array $user): string;
}
