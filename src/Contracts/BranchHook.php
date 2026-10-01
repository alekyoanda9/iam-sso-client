<?php

namespace Sd1\IamSso\Contracts;

use Sd1\IamSso\Branch\BranchContext;

/**
 * Opsional, diimplementasikan oleh hook aplikasi (config sso.hook) untuk login multi-cabang.
 * Tanpa interface ini: cabang tidak diwajibkan dan koneksinya bernama 'sso_branch'.
 */
interface BranchHook
{
    /**
     * true -> login DITOLAK bila IAM tidak mengirim pilihan cabang (pengaman salah konfigurasi
     * client di IAM). Aplikasi 1 DB: false.
     */
    public function requiresBranch(): bool;

    /** Nama koneksi Laravel untuk cabang terpilih. Pakai: DB::connection(Sso::connectionName()). */
    public function connectionName(BranchContext $branch): string;

    /**
     * Config koneksi final. $default sudah berisi driver/host/port/database/username/password/schema
     * dari IAM; kembalikan apa adanya atau tambahkan opsi (mis. sslmode).
     */
    public function connectionConfig(BranchContext $branch, array $default): array;
}
