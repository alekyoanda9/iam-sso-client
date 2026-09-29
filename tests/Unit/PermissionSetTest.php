<?php

namespace Sd1\IamSso\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sd1\IamSso\Access\PermissionSet;

class PermissionSetTest extends TestCase
{
    private function perms($branchType = 'IGR', $mode = 'MANAGED'): PermissionSet
    {
        return new PermissionSet([
            ['code' => 'BO027', 'type' => 'MENU', 'url' => '/bo/proses/monthend'],
            ['code' => 'FO005', 'type' => 'MENU', 'url' => '/fo/laporan-kasir/penjualan'],
            ['code' => 'BO068', 'type' => 'MENU', 'url' => '/bo/monitoring-stok-pareto', 'branch_types' => ['IGR']],
            ['code' => 'BO190.EXPORT', 'type' => 'ACTION', 'url' => null],
            ['code' => 'X001', 'type' => 'MENU', 'url' => ''],
        ], $branchType, $mode);
    }

    public function test_can_url_matches_ias_is_accessible_prefix_semantics(): void
    {
        $p = $this->perms();
        $this->assertTrue($p->canUrl('/'));
        $this->assertTrue($p->canUrl('/bo/proses/monthend'));
        $this->assertTrue($p->canUrl('/bo/proses/monthend/getdata'), 'sub-path diizinkan seperti IAS');
        $this->assertTrue($p->canUrl('/bo/proses/monthendXYZ'), 'IAS mencocokkan awalan mentah, bukan per segmen');
        $this->assertFalse($p->canUrl('/bo/proses'));
        $this->assertFalse($p->canUrl('/master/user'));
        $this->assertTrue($p->canUrl('fo/laporan-kasir/penjualan'), 'tanpa slash depan dinormalisasi');
    }

    public function test_actions_and_empty_urls_never_grant_url_access(): void
    {
        $p = $this->perms();
        $this->assertTrue($p->can('bo190.export'));
        $this->assertFalse($p->canUrl('/apa/saja'), 'di IAS acc_url kosong akan meloloskan semua URL; di SDK tidak');
    }

    public function test_match_url_reports_exact_hit_for_menu_log(): void
    {
        $p = $this->perms();
        $this->assertTrue($p->matchUrl('/bo/proses/monthend')['exact']);
        $this->assertFalse($p->matchUrl('/bo/proses/monthend/proses')['exact']);
        $this->assertSame('BO027', $p->matchUrl('/bo/proses/monthend/proses')['code']);
        $this->assertNull($p->matchUrl('/nope'));
    }

    public function test_branch_type_filter(): void
    {
        $this->assertTrue($this->perms('IGR')->can('BO068'));
        $this->assertFalse($this->perms('SPI')->can('BO068'));
        $this->assertFalse($this->perms('SPI')->canUrl('/bo/monitoring-stok-pareto'));
        $this->assertFalse($this->perms(null)->can('BO068'), 'belum pilih cabang: menu khusus tipe cabang ditahan');
        $this->assertTrue($this->perms(null)->can('FO005'));
    }

    public function test_menus_excludes_actions_and_keeps_order(): void
    {
        $this->assertSame(['BO027', 'FO005', 'BO068', 'X001'], array_column($this->perms()->menus(), 'code'));
    }
}
