<?php

namespace Sd1\IamSso;

use ArrayAccess;
use JsonSerializable;

/**
 * Identitas user dari klaim JWT IAM (sudah diverifikasi).
 * Akses: $user->nik, $user->ias_user_code, $user['role_code'], $user->get('branch_code').
 */
class SsoUser implements ArrayAccess, JsonSerializable
{
    /** @var array */
    private $claims;

    public function __construct(array $claims)
    {
        $this->claims = $claims;
    }

    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->claims) ? $this->claims[$key] : $default;
    }

    public function id()
    {
        return $this->get('sub', $this->get('id'));
    }

    public function nik()
    {
        return $this->get('nik');
    }

    public function name()
    {
        return $this->get('name');
    }

    public function email()
    {
        return $this->get('email');
    }

    public function roleCode()
    {
        return $this->get('role_code');
    }

    public function branchCode()
    {
        return $this->get('branch_code');
    }

    public function branchType()
    {
        return $this->get('branch_type');
    }

    /** User Head Office boleh memilih cabang mana pun di aplikasi. */
    public function isHeadOffice(): bool
    {
        return $this->get('role_scope') === 'HO';
    }

    public function hasRole($codes): bool
    {
        return in_array($this->roleCode(), (array) $codes, true);
    }

    public function toArray(): array
    {
        return $this->claims;
    }

    public function __get($key)
    {
        return $this->get($key);
    }

    public function __isset($key)
    {
        return isset($this->claims[$key]);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->claims[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        // read-only
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        // read-only
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->claims;
    }
}
