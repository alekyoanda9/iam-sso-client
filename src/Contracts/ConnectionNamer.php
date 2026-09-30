<?php

namespace Sd1\IamSso\Contracts;

use Sd1\IamSso\Branch\BranchContext;

/**
 * Menentukan nama koneksi DB Laravel untuk cabang terpilih (config sso.branch.connection_name).
 * Aplikasi baru cukup memakai nama tetap (mis. 'sso_branch'); aplikasi lama seperti IAS
 * memakai penamaan lamanya (Sd1\IamSso\Ias\IasConnectionNamer: igrjkt, simjkt, spibks, ...).
 */
interface ConnectionNamer
{
    public function name(BranchContext $branch): string;
}
