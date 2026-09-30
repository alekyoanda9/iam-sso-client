<?php

namespace Sd1\IamSso\Contracts;

use Sd1\IamSso\Branch\BranchContext;

/**
 * Menentukan nama koneksi DB Laravel untuk cabang terpilih (config sso.branch.connection_name).
 * Umumnya cukup nama tetap ('sso_branch') atau pola di config ('{env_prefix}{kode}').
 * Implementasikan interface ini hanya bila aturan penamaan tidak bisa ditulis sebagai pola.
 */
interface ConnectionNamer
{
    public function name(BranchContext $branch): string;
}
