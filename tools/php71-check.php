<?php
/**
 * Pemeriksa kompatibilitas sintaks & fungsi PHP 7.1 untuk src/ (IAS jalan di PHP 7.x).
 * Pemakaian: php tools/php71-check.php [autoload.php yang memuat nikic/php-parser v5]
 * Menandai fitur PHP >= 7.2 yang lazim: arrow fn, typed property, ??=, match, nullsafe,
 * named argument, union/mixed/static/object/never type, spread array, trailing comma di
 * pemanggilan, enum/readonly, first-class callable, literal numerik ber-underscore,
 * dan fungsi yang baru ada setelah 7.1.
 */
require $argv[1] ?? __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

$newFunctions = ['str_contains', 'str_starts_with', 'str_ends_with', 'array_key_first', 'array_key_last', 'is_countable',
    'get_debug_type', 'fdiv', 'array_is_list', 'mb_str_split', 'spl_object_id', 'hrtime', 'password_verify_argon'];
$badTypes = ['object', 'mixed', 'static', 'never', 'false', 'null', 'true'];

$issues = [];
$visitor = new class($issues, $newFunctions, $badTypes) extends NodeVisitorAbstract {
    public $issues; private $fns; private $types; public $file;
    public function __construct(&$issues, $fns, $types) { $this->issues = &$issues; $this->fns = $fns; $this->types = $types; }
    private function flag(Node $n, $msg) { $this->issues[] = sprintf('%s:%d  %s', $this->file, $n->getStartLine(), $msg); }
    private function checkType($type, Node $n, $where) {
        if ($type === null) return;
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) { $this->flag($n, "union/intersection type ($where)"); return; }
        if ($type instanceof Node\NullableType) $type = $type->type;
        $name = ($type instanceof Node\Identifier || $type instanceof Node\Name) ? $type->toLowerString() : null;
        if ($name && in_array($name, $this->types, true)) $this->flag($n, "type '$name' ($where) butuh PHP >= 7.2/8");
    }
    public function enterNode(Node $n) {
        if ($n instanceof Node\Expr\ArrowFunction) $this->flag($n, 'arrow function fn() (PHP 7.4)');
        if ($n instanceof Node\Stmt\Property && $n->type) $this->flag($n, 'typed property (PHP 7.4)');
        if ($n instanceof Node\Expr\AssignOp\Coalesce) $this->flag($n, '??= (PHP 7.4)');
        if ($n instanceof Node\Expr\Match_) $this->flag($n, 'match (PHP 8)');
        if ($n instanceof Node\Expr\NullsafeMethodCall || $n instanceof Node\Expr\NullsafePropertyFetch) $this->flag($n, '?-> (PHP 8)');
        if ($n instanceof Node\Arg && $n->name) $this->flag($n, 'named argument (PHP 8)');
        if ($n instanceof Node\VariadicPlaceholder) $this->flag($n, 'first-class callable (PHP 8.1)');
        if ($n instanceof Node\ArrayItem && $n->unpack) $this->flag($n, 'spread di array (PHP 7.4)');
        if ($n instanceof Node\Stmt\Enum_) $this->flag($n, 'enum (PHP 8.1)');
        if ($n instanceof Node\Param && $n->flags) $this->flag($n, 'constructor promotion (PHP 8)');
        if ($n instanceof Node\Scalar\Int_ || $n instanceof Node\Scalar\Float_) { if (strpos((string) $n->getAttribute('rawValue'), '_') !== false) $this->flag($n, 'literal numerik ber-underscore (7.4)'); }
        if ($n instanceof Node\FunctionLike) {
            foreach ($n->getParams() as $p) $this->checkType($p->type, $n, 'param');
            $this->checkType($n->getReturnType(), $n, 'return');
        }
        if ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && in_array($n->name->toLowerString(), $this->fns, true)) $this->flag($n, 'fungsi ' . $n->name . '() tidak ada di PHP 7.1');
        if ($n instanceof Node\Stmt\Expression && $n->expr instanceof Node\Expr\Throw_) $n->expr->setAttribute('stmt', true);
        if ($n instanceof Node\Expr\Throw_ && ! $n->getAttribute('stmt')) $this->flag($n, 'throw sebagai ekspresi (PHP 8)');
        if ($n instanceof Node\Stmt\ClassConst && $n->isFinal()) $this->flag($n, 'final const (8.1)');
        return null;
    }
};

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$dirs = array_slice($argv, 2) ?: [__DIR__ . '/../src', __DIR__ . '/../routes', __DIR__ . '/../config'];
$count = 0;
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $count++;
        $code = file_get_contents($f->getPathname());
        $visitor->file = str_replace(dirname(__DIR__) . '/', '', $f->getPathname());
        $t = new NodeTraverser();
        $t->addVisitor($visitor);
        $t->traverse($parser->parse($code));
        // trailing comma di argumen pemanggilan (7.3): cek token ",\s*)" di luar deklarasi array
        if (preg_match_all('/,\s*\n\s*\)(?!\s*[;,]?\s*(?:use|\{|:))/m', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $before = substr($code, 0, $hit[1]);
                // abaikan penutup array( ... ) gaya lama & deklarasi fungsi; laporkan untuk dicek manual
                $issues[] = sprintf('%s:%d  kemungkinan trailing comma di pemanggilan fungsi (PHP 7.3) - cek manual', $visitor->file, substr_count($before, "\n") + 2);
            }
        }
    }
}
if ($issues) {
    echo implode(PHP_EOL, $issues), PHP_EOL, count($issues), " temuan di $count file.", PHP_EOL;
    exit(1);
}
echo "OK: $count file lolos pemeriksaan sintaks/fungsi PHP 7.1.", PHP_EOL;
