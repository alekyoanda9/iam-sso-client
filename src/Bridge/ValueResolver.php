<?php

namespace Sd1\IamSso\Bridge;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Menerjemahkan "spec" nilai dari config menjadi nilai nyata. Semua spec berupa string/array
 * sederhana supaya aman untuk `php artisan config:cache`.
 *
 * Bentuk spec:
 *   'user.ias_user_code'          klaim JWT user (atau baris user dari IAM saat mirror)
 *   'branch.code'                 cabang pilihan user: code, name, type, kode, service_name, php_host,
 *                                 env, is_production, locked, connection_name,
 *                                 connection.host|port|database|username|password|schema, hosts.PRODUCTION
 *   'request.ip' / 'request.host' / 'request.http_host'
 *   'session.<kunci>'             nilai sesi saat ini
 *   'row.<kolom>'                 kolom hasil query (bagian `queries.into`)
 *   'context.<kunci>'             nilai tambahan dari pemanggil (mis. context.branch_code saat mirror)
 *   'value:teks'                  teks apa adanya          ['value' => <apa saja>] nilai apa adanya
 *   'now'                         waktu sekarang (Carbon)
 *   'template:http://{request.host}:3050'   gabungan teks + {spec tanpa transform}
 *
 * Transform ditulis setelah tanda | dan dijalankan berurutan:
 *   upper, lower, trim, ucfirst, string (null -> ''), int (kosong -> null), empty_null,
 *   strip:X (hapus teks X), max:N (trim, kosong -> null, potong N karakter),
 *   substr:S,L, default:X (bila kosong), prefix:SM=SM,SJM=SJM,*=XXX (awalan pertama yang cocok,
 *   tanpa beda huruf besar/kecil; * = selain itu)
 */
class ValueResolver
{
    public function resolve($spec, array $context)
    {
        if (is_array($spec)) {
            if (! array_key_exists('value', $spec)) {
                throw new InvalidArgumentException('Spec array harus berbentuk [\'value\' => ...].');
            }

            return $spec['value'];
        }
        if ($spec === null) {
            return null;
        }

        $spec = (string) $spec;
        if (strpos($spec, 'template:') === 0) {
            return $this->template(substr($spec, 9), $context);
        }

        $parts = explode('|', $spec);
        $value = $this->source(trim(array_shift($parts)), $context);
        foreach ($parts as $transform) {
            $value = $this->transform($value, trim($transform));
        }

        return $value;
    }

    /** Isi {spec} di dalam teks. */
    public function template(string $text, array $context): string
    {
        $self = $this;

        return (string) preg_replace_callback('/\{([^{}]+)\}/', function ($m) use ($self, $context) {
            $value = $self->resolve($m[1], $context);

            return is_scalar($value) ? (string) $value : '';
        }, $text);
    }

    public function isEmpty($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function source(string $source, array $context)
    {
        if ($source === 'now') {
            return Carbon::now();
        }
        if ($source === 'null') {
            return null;
        }
        if (strpos($source, 'value:') === 0) {
            return substr($source, 6);
        }
        $dot = strpos($source, '.');
        $root = $dot === false ? $source : substr($source, 0, $dot);
        if (! in_array($root, ['user', 'branch', 'request', 'session', 'row', 'context'], true)) {
            throw new InvalidArgumentException('Sumber nilai tidak dikenal: ' . $source);
        }
        $data = isset($context[$root]) ? $context[$root] : [];

        return $dot === false ? $data : Arr::get((array) $data, substr($source, $dot + 1));
    }

    private function transform($value, string $transform)
    {
        $arg = null;
        if (($pos = strpos($transform, ':')) !== false) {
            $arg = substr($transform, $pos + 1);
            $transform = substr($transform, 0, $pos);
        }

        switch ($transform) {
            case 'upper':
                return $value === null ? null : strtoupper((string) $value);
            case 'lower':
                return $value === null ? null : strtolower((string) $value);
            case 'trim':
                return $value === null ? null : trim((string) $value);
            case 'ucfirst':
                return $value === null ? null : ucfirst((string) $value);
            case 'string':
                return $value === null ? '' : (string) $value;
            case 'int':
                return $this->isEmpty($value) ? null : (int) $value;
            case 'empty_null':
                return $this->isEmpty($value) ? null : $value;
            case 'strip':
                return $value === null ? null : str_replace((string) $arg, '', (string) $value);
            case 'max':
                if ($value === null) {
                    return null;
                }
                $value = trim((string) $value);

                return $value === '' ? null : mb_substr($value, 0, (int) $arg);
            case 'substr':
                if ($value === null) {
                    return null;
                }
                $p = explode(',', (string) $arg);

                return isset($p[1]) ? substr((string) $value, (int) $p[0], (int) $p[1]) : substr((string) $value, (int) $p[0]);
            case 'default':
                return $this->isEmpty($value) ? $arg : $value;
            case 'prefix':
                return $this->prefix((string) $value, (string) $arg);
        }

        throw new InvalidArgumentException('Transform tidak dikenal: ' . $transform);
    }

    private function prefix(string $value, string $rules)
    {
        $fallback = null;
        foreach (explode(',', $rules) as $rule) {
            $pair = explode('=', $rule, 2);
            $prefix = trim($pair[0]);
            $result = isset($pair[1]) ? trim($pair[1]) : $prefix;
            if ($prefix === '*') {
                $fallback = $result;
                continue;
            }
            if ($prefix !== '' && strtoupper(substr($value, 0, strlen($prefix))) === strtoupper($prefix)) {
                return $result;
            }
        }

        return $fallback;
    }
}
