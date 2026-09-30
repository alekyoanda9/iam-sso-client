<?php

namespace Sd1\IamSso\Ias;

use Illuminate\Contracts\Session\Session;
use Sd1\IamSso\Branch\BranchContext;

/**
 * Mengisi kunci sesi lama IAS yang berasal dari CABANG/KONEKSI, dari konteks cabang IAM
 * (pengganti halaman pilih cabang di IAS). Nilainya sama dengan loginController::login() lama:
 *
 *   connection = nama koneksi (IasConnectionNamer: igrjkt / simjkt / spibks / ...)
 *   phpIP, namacabang, kode, kodeigr, dbHostProd, dbHostSim, dbPort, dbPass
 *
 * Catatan: dbPass = password koneksi TERPILIH. Login lama selalu menyimpan password
 * PRODUCTION walau memilih SIMULASI; IAM tidak mengirim password koneksi yang tidak dipilih.
 */
class IasBranchSessionWriter
{
    public function write(Session $session, BranchContext $branch, string $connectionName)
    {
        $connection = $branch->connection();

        $session->put('connection', $connectionName);
        $session->put('phpIP', (string) $branch->phpHost());
        $session->put('namacabang', ucfirst(self::namaCabang((string) $branch->name())));
        $session->put('kode', $branch->kode());
        $session->put('kodeigr', $branch->code());
        $session->put('dbHostProd', (string) $branch->host('PRODUCTION'));
        $session->put('dbHostSim', (string) $branch->host('SIMULASI'));
        $session->put('dbPort', '5432');
        $session->put('dbPass', isset($connection['password']) ? $connection['password'] : '');
    }

    /** Sama dengan loginController lama: huruf kecil, tanpa kata 'INDOGROSIR'. */
    public static function namaCabang(string $name): string
    {
        return $name === '' ? '' : strtolower(trim(str_replace('INDOGROSIR', '', strtoupper($name))));
    }
}
