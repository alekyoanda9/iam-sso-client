<?php

namespace Sd1\IamSso\Ias;

use Illuminate\Support\Collection;
use Sd1\IamSso\Access\PermissionSet;
use stdClass;

class IasMenuMapper
{
    /** @return Collection|stdClass[] */
    public function toLegacyMenu(PermissionSet $permissions): Collection
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

        return new Collection($rows);
    }

    private function val(array $p, string $key)
    {
        return isset($p[$key]) && $p[$key] !== '' ? $p[$key] : null;
    }
}