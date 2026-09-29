<?php

namespace Sd1\IamSso\Ias;

use Sd1\IamSso\Access\PermissionSet;
use stdClass;

/**
 * Permission MENU dari IAM -> bentuk baris lama AccessController::getListMenu() IAS
 * (stdClass acc_id, acc_group, acc_subgroup1..3, acc_name, acc_url), untuk Session('menu')
 * dan navbar.blade.php yang tidak diubah.
 */
class IasMenuMapper
{
    /** @return stdClass[] */
    public function toLegacyMenu(PermissionSet $permissions): array
    {
        $rows = [];
        foreach ($permissions->menus() as $p) {
            if (empty($p['url'])) {
                continue; // navbar lama selalu membuat link; MENU tanpa url dilewati
            }
            $row = new stdClass();
            $row->acc_id = $p['code'];
            $row->acc_group = $this->val($p, 'group');
            $row->acc_subgroup1 = $this->val($p, 'subgroup1');
            $row->acc_subgroup2 = $this->val($p, 'subgroup2');
            $row->acc_subgroup3 = $this->val($p, 'subgroup3');
            $row->acc_name = $this->val($p, 'name');
            $row->acc_url = $p['url'];
            $rows[] = $row;
        }

        return $rows;
    }

    private function val(array $p, string $key)
    {
        return isset($p[$key]) && $p[$key] !== '' ? $p[$key] : null;
    }
}
