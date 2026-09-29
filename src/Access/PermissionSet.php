<?php

namespace Sd1\IamSso\Access;

/**
 * Hak akses efektif user pada aplikasi ini (dari /api/me/access), sudah difilter tipe cabang aktif.
 *
 * - can('BO190')      : punya permission (MENU/ACTION) berkode tsb.
 * - canUrl('/bo/..')  : semantik SAMA dengan AccessController::isAccessible() IAS:
 *                         '/' selalu boleh; URL diizinkan bila diawali url salah satu MENU
 *                         (pencocokan awalan apa adanya, bukan per segmen).
 *   Bedanya: permission tanpa url (ACTION) tidak pernah dipakai untuk pencocokan URL
 *   (di IAS acc_url kosong akan cocok dengan semua URL).
 * - Mode ROLE_ONLY: daftar permission kosong -> can() selalu false; pakai role dari Sso::user().
 */
class PermissionSet
{
    /** @var array[] */
    private $items;

    /** @var array<string, array> */
    private $byCode = [];

    /** @var string */
    private $mode;

    /** @var string|null */
    private $branchType;

    public function __construct(array $permissions, $branchType = null, string $mode = 'MANAGED')
    {
        $this->mode = $mode;
        $this->branchType = $branchType ? strtoupper($branchType) : null;
        $this->items = [];

        foreach ($permissions as $p) {
            if (! is_array($p) || ! isset($p['code'])) {
                continue;
            }
            if (! $this->allowedForBranch($p)) {
                continue;
            }
            $this->items[] = $p;
            $this->byCode[strtoupper($p['code'])] = $p;
        }
    }

    public static function none(): self
    {
        return new self([], null, 'ROLE_ONLY');
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function branchType()
    {
        return $this->branchType;
    }

    /** @return array[] */
    public function all(): array
    {
        return $this->items;
    }

    /** @return array[] permission bertipe MENU (urutan dari IAM). */
    public function menus(): array
    {
        return array_values(array_filter($this->items, function ($p) {
            return (isset($p['type']) ? $p['type'] : 'MENU') === 'MENU';
        }));
    }

    public function codes(): array
    {
        return array_keys($this->byCode);
    }

    public function can(string $code): bool
    {
        return isset($this->byCode[strtoupper($code)]);
    }

    public function canAny(array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->can($code)) {
                return true;
            }
        }

        return false;
    }

    public function canUrl(string $path): bool
    {
        return $this->isRoot($path) || $this->matchUrl($path) !== null;
    }

    /**
     * Permission MENU pertama yang url-nya menjadi awalan $path, atau null.
     * Kunci tambahan 'exact' = true bila url sama persis (IAS mencatat log menu hanya pada kondisi ini).
     */
    public function matchUrl(string $path)
    {
        $path = $this->normalize($path);
        foreach ($this->menus() as $p) {
            $url = isset($p['url']) ? (string) $p['url'] : '';
            if ($url === '') {
                continue;
            }
            if (substr($path, 0, strlen($url)) === $url) {
                $p['exact'] = strlen($url) === strlen($path);

                return $p;
            }
        }

        return null;
    }

    /** Salinan dengan tipe cabang aktif lain (dipakai setelah user HO memilih cabang). */
    public function withBranchType($branchType, array $rawPermissions): self
    {
        return new self($rawPermissions, $branchType, $this->mode);
    }

    private function allowedForBranch(array $p): bool
    {
        if (empty($p['branch_types'])) {
            return true;
        }
        if ($this->branchType === null) {
            // Belum ada cabang aktif: permission khusus tipe cabang tertentu ditahan.
            return false;
        }

        return in_array($this->branchType, array_map('strtoupper', (array) $p['branch_types']), true);
    }

    private function isRoot(string $path): bool
    {
        return $path === '' || $path === '/';
    }

    private function normalize(string $path): string
    {
        return $path === '' || $path[0] !== '/' ? '/' . $path : $path;
    }
}
