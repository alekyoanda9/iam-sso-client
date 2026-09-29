<?php

namespace Sd1\IamSso\Ias;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;

/**
 * Mirror SATU ARAH IAM -> tbmaster_user di DB cabang (baca-saja bagi IAS).
 *
 * Kolom yang ditulis : kodeigr, userid, username, email, nik, userlevel, recordid,
 *                      create_by/create_dt (insert), modify_by/modify_dt (update).
 * TIDAK pernah ditulis: userpassword, encryptpwd, finger, fingerdata, jabatan, station,
 *                      stchangepassword, flag_soic  (password user lama tetap utuh).
 * userlevel hanya ditimpa bila IAM mengirim nilai (user baru = null -> tidak menimpa).
 * User nonaktif / tanpa akses -> recordid = '1' (dianggap terhapus oleh logika lama).
 */
class TbmasterUserMirror
{
    /** @var DatabaseManager */
    private $db;

    /** @var string */
    private $actor;

    public function __construct(DatabaseManager $db, string $actor = 'SSO')
    {
        $this->db = $db;
        $this->actor = substr($actor, 0, 3);
    }

    /**
     * @param array $user kunci: ias_user_code, name, email, nik, ias_userlevel, active(bool)
     *
     * @return string 'inserted' | 'updated' | 'skipped'
     */
    public function upsert(string $connection, string $kodeigr, array $user): string
    {
        $userid = isset($user['ias_user_code']) ? strtoupper(trim((string) $user['ias_user_code'])) : '';
        if ($userid === '' || strlen($userid) > 3) {
            return 'skipped';
        }

        $table = $this->db->connection($connection)->table('tbmaster_user');
        $now = Carbon::now();
        $values = [
            'kodeigr' => substr($kodeigr, 0, 2),
            'username' => $this->cut(isset($user['name']) ? $user['name'] : null, 15),
            'email' => $this->cut(isset($user['email']) ? $user['email'] : null, 50),
            'nik' => $this->cut(isset($user['nik']) ? $user['nik'] : null, 16),
            'recordid' => array_key_exists('active', $user) && ! $user['active'] ? '1' : null,
        ];
        if (isset($user['ias_userlevel']) && $user['ias_userlevel'] !== null && $user['ias_userlevel'] !== '') {
            $values['userlevel'] = (int) $user['ias_userlevel'];
        }

        if ($table->where('userid', $userid)->exists()) {
            $this->db->connection($connection)->table('tbmaster_user')->where('userid', $userid)->update($values + [
                'modify_by' => $this->actor,
                'modify_dt' => $now,
            ]);

            return 'updated';
        }

        $this->db->connection($connection)->table('tbmaster_user')->insert($values + [
            'userid' => $userid,
            'create_by' => $this->actor,
            'create_dt' => $now,
        ]);

        return 'inserted';
    }

    private function cut($value, int $max)
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
