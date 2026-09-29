<?php

namespace Sd1\IamSso\Ias;

use Illuminate\Contracts\Session\Session;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\SsoUser;

/**
 * Mengisi kunci sesi lama IAS yang berasal dari USER (bukan dari cabang/koneksi),
 * supaya controller IAS lama tetap jalan tanpa diubah:
 *
 *   usid       = ias_user_code (kode 3 karakter, dipakai create_by/modify_by)
 *   un         = nama
 *   eml        = email
 *   userlevel  = ias_userlevel (null untuk user baru)
 *   usertype   = SM / SJM / XXX dari prefix email (logika lama loginController)
 *   menu       = daftar menu bentuk acc_*
 *   specialUser= [] (akun generik sudah dihapus; tetap array karena dipakai in_array())
 *   sso_nik, sso_role = informasi tambahan
 *
 * Kunci cabang/koneksi (kdigr, connection, kodeigr, rptname, ppn, ip, dst.)
 * diisi alur pilih cabang di aplikasi IAS (Tahap C), bukan di sini.
 */
class IasSessionWriter
{
    /** @var IasMenuMapper */
    private $menus;

    public function __construct(IasMenuMapper $menus)
    {
        $this->menus = $menus;
    }

    public function writeUser(Session $session, SsoUser $user, PermissionSet $permissions)
    {
        $email = (string) $user->email();

        $session->put('usid', $user->get('ias_user_code'));
        $session->put('un', $user->name());
        $session->put('eml', $email);
        $session->put('userlevel', $user->get('ias_userlevel'));
        $session->put('usertype', self::userType($email));
        $session->put('specialUser', []);
        $session->put('sso_nik', $user->nik());
        $session->put('sso_role', $user->roleCode());
        $this->writeMenu($session, $permissions);
    }

    public function writeMenu(Session $session, PermissionSet $permissions)
    {
        $session->put('menu', $this->menus->toLegacyMenu($permissions));
    }

    /** Sama persis dengan loginController IAS (Steven Leo 12/12/2022). */
    public static function userType(string $email): string
    {
        if (strtoupper(substr($email, 0, 2)) == 'SM') {
            return 'SM';
        }
        if (strtoupper(substr($email, 0, 3)) == 'SJM') {
            return 'SJM';
        }

        return 'XXX';
    }
}
