<?php

/*
 * Konfigurasi SSO khusus Web IAS (bagian yang menggantikan kelas Sd1\IamSso\Ias\* di SDK v1).
 * Salin bagian ini ke config/sso.php aplikasi IAS. Dipakai juga oleh tests/Feature/LegacyBridgeTest untuk memastikan
 * hasilnya sama dengan perilaku adapter IAS lama.
 */
return [
    'branch' => [
        'enabled' => true,
        // igrjkt / simjkt ; SPI & ICM di PRODUCTION = kode saja (spibks), SIMULASI = simspibks
        'connection_name' => '{env_prefix}{kode}',
        'env_prefixes' => ['PRODUCTION' => 'igr', 'SIMULASI' => 'sim'],
        'connection_name_overrides' => ['PRODUCTION:SPI' => '{kode}', 'PRODUCTION:ICM' => '{kode}'],
        'connection_options' => [],
    ],

    'bridge' => [
        'enabled' => true,
        'required' => [
            'user.ias_user_code' => 'Akun Anda belum memiliki kode user IAS. Hubungi admin SSO.',
        ],
        'session' => [
            // dari user (IAM)
            'usid' => 'user.ias_user_code',
            'un' => 'user.name',
            'eml' => 'user.email|string',
            'userlevel' => 'user.ias_userlevel',
            'usertype' => 'user.email|string|prefix:SM=SM,SJM=SJM,*=XXX',
            'specialUser' => ['value' => []],
            'sso_nik' => 'user.nik',
            'sso_role' => 'user.role_code',
            // dari cabang + koneksi pilihan di halaman login IAM
            'connection' => 'branch.connection_name',
            'phpIP' => 'branch.php_host|string',
            'namacabang' => 'branch.name|string|strip:INDOGROSIR|trim|lower|ucfirst',
            'kode' => 'branch.kode',
            'kodeigr' => 'branch.code',
            'dbHostProd' => 'branch.hosts.PRODUCTION|string',
            'dbHostSim' => 'branch.hosts.SIMULASI|string',
            'dbPort' => 'value:5432',
            'dbPass' => 'branch.connection.password|string',
            // dari request
            'ip' => 'request.ip',
            'id' => 'request.ip|strip:.',
            'baseUrlIasApi' => 'template:http://{request.host}:3050',
            'auth_via' => 'value:sso',
        ],
        'menu' => [
            'key' => 'menu',
            'require_url' => true,
            'format' => 'collection',
            'fields' => [
                'acc_id' => 'code',
                'acc_group' => 'group',
                'acc_subgroup1' => 'subgroup1',
                'acc_subgroup2' => 'subgroup2',
                'acc_subgroup3' => 'subgroup3',
                'acc_name' => 'name',
                'acc_url' => 'url',
            ],
        ],
        'queries' => [
            [
                'sql' => 'select prs_kodeigr, prs_rptname, prs_nilaippn from tbmaster_perusahaan limit 1',
                'into' => ['kdigr' => 'row.prs_kodeigr', 'rptname' => 'row.prs_rptname', 'ppn' => 'row.prs_nilaippn'],
                'required' => true,
                'error' => 'Data TBMASTER_PERUSAHAAN tidak ditemukan di koneksi {session.connection}.',
            ],
            [
                // PostgreSQL saja; di DB lain dilewati (required=false)
                'sql' => 'select pg_backend_pid() as userenv',
                'into' => ['sessionID' => 'row.userenv|string'],
                'required' => false,
            ],
        ],
        'on_login' => [
            [
                'sql' => 'update tbmaster_perusahaan set prs_periodeterakhir = :periode, prs_modify_by = :usid, prs_modify_dt = :modify_dt',
                'bindings' => ['periode' => 'now', 'usid' => 'session.usid', 'modify_dt' => 'now'],
            ],
            [
                'sql' => 'update tbmaster_computer set useraktif = :usid where ip = :ip',
                'bindings' => ['usid' => 'session.usid', 'ip' => 'session.ip'],
            ],
        ],
        'on_logout' => [
            [
                'sql' => "update tbmaster_computer set useraktif = '' where ip = :ip",
                'bindings' => ['ip' => 'session.ip'],
                'when' => ['session.ip', 'session.connection'],
            ],
        ],
        'forget' => ['stat', 'token', 'apilogin_token_expiry', 'apilogin_retry_after'],
        'logout_on_branch_change' => true,
    ],

    'mirror' => [
        'enabled' => true,
        'on_login' => true,
        'login_branch_code' => 'session.kdigr',
        'branch' => env('KODEIGR'),
        'table' => 'tbmaster_user',
        'key' => ['column' => 'userid', 'value' => 'user.ias_user_code|trim|upper', 'max' => 3],
        'columns' => [
            'kodeigr' => 'context.branch_code|substr:0,2',
            'username' => 'user.name|max:15',
            'email' => 'user.email|max:50',
            'nik' => 'user.nik|max:16',
        ],
        'columns_if_set' => ['userlevel' => 'user.ias_userlevel|int'],
        'when_active' => ['recordid' => 'null'],
        'when_inactive' => ['recordid' => 'value:1'],
        'on_insert' => ['create_by' => 'value:SSO', 'create_dt' => 'now'],
        'on_update' => ['modify_by' => 'value:SSO', 'modify_dt' => 'now'],
    ],

    'permission_push' => [
        'source' => Sd1\IamSso\Catalog\TableCatalogSource::class,
        'table' => 'tbmaster_access_migrasi',
        'connection' => null,
        'columns' => [
            'code' => 'acc_id', 'name' => 'acc_name', 'url' => 'acc_url', 'group' => 'acc_group',
            'subgroup1' => 'acc_subgroup1', 'subgroup2' => 'acc_subgroup2', 'subgroup3' => 'acc_subgroup3',
            'order' => 'acc_order', 'level' => 'acc_level',
        ],
        'type' => 'MENU',
        'active_column' => 'acc_status',
        'active_value' => '0',
        'modified_columns' => ['acc_modify_dt', 'acc_create_dt'],
        // Master User & akses menu per user digantikan IAM
        'excluded_urls' => ['/administration/user', '/administration/access'],
        'excluded_groups' => [],
    ],
];
