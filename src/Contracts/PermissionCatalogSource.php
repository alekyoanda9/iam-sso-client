<?php

namespace Sd1\IamSso\Contracts;

/**
 * Sumber katalog menu/aksi aplikasi untuk sso:permission-push.
 */
interface PermissionCatalogSource
{
    /**
     * @param array $options opsi command (mis. ['connection' => 'igrjkt'])
     *
     * @return array[] tiap item: code, name, type(MENU|ACTION), url, group, subgroup1..3,
     *                 order, level, is_active, source_modified_at, [branch_types]
     */
    public function items(array $options): array;
}
