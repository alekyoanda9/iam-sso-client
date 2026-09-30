<?php

namespace Sd1\IamSso\Ias;

use Sd1\IamSso\Branch\BranchContext;
use Sd1\IamSso\Contracts\ConnectionNamer;

/**
 * Nama koneksi gaya IAS (sama persis dengan loginController::login() lama):
 *   PRODUCTION -> 'igr' . kode, kecuali cabang SPI/ICM -> kode saja (spibks, icmxxx)
 *   SIMULASI   -> 'sim' . kode
 */
class IasConnectionNamer implements ConnectionNamer
{
    public function name(BranchContext $branch): string
    {
        return self::legacyName((string) $branch->kode(), $branch->isProduction() ? 'igr' : 'sim');
    }

    /** @param string $koneksi 'igr' | 'sim' (nilai dropdown Koneksi di login IAS lama) */
    public static function legacyName(string $kode, string $koneksi): string
    {
        if (in_array(substr($kode, 0, 3), ['icm', 'spi'], true) && $koneksi == 'igr') {
            return $kode;
        }

        return $koneksi . $kode;
    }
}
