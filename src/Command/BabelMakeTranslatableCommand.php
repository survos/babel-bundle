<?php
// packages/code-bundle/src/Service/TranslatableTraitGenerator.php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\ParserFactory;

final class TranslatableTraitGenerator
{
    public function generate(
        string $entityFile,
        string $projectDir,
        ?string $traitNamespace = null,
        ?string $outputDir = null,
    ): array {
        $code = file_get_contents($entityFile);
        if ($code === false) {
            throw new \RuntimeException("Cannot read $entityFile");
        }

        // ✅ php-parser v5 API
        $parser = new ParserFactory()->createForHostVersion();
        $ast = $parser->parse($code);
        if (!$ast) {
            throw new \RuntimeException("Failed to parse $entityFile");
        }

        // Locate namespace + first class
        $ns = null;  /** @var null|Namespace_ $ns */
        $class = null; /** @var null|Class_ $class */
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $ns = $stmt;
                foreach ($ns->stmts as $nsStmt) {
                    if ($nsStmt instanceof Class_) {
                        $class = $nsStmt;
                        break 2;
                    }
                }
            }
        }
        if (!$ns || !$class) {
            throw new \RuntimeException("Could not find a class in $entityFile");
        }

        $entityNs   = $ns->name?->toString() ?? 'App\\Entity';
        $entityName = $class->name?->toString() ?? 'Entity';
        $entityFqcn = $entityNs.'\\'.$entityName;

        // Target trait FQCN + path
        $traitNamespace ??= $entityNs.'\\Translations';
        $traitName       = $entityName.'TranslationsTrait';
        $traitFqcn       = $traitNamespace.'\\'.$traitName;

        $outputDir ??= $this->namespaceToPath($traitNamespace, $projectDir);
        if (!is_dir($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Cannot create $outputDir");
        }
        $traitPath = rtrim($outputDir, '/').'/'.$traitName.'.php';

        // Gather translatable public props
        $fields = [];
        $blocks = []; // each: ['name'=>string,'colAttrs'=>string[],'hookAttrs'=>string[],'hasContext'=>bool]

        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property) continue;
            if (!($stmt->flags & Class_::MODIFIER_PUBLIC)) continue;
            if (!$this->hasTranslatable($stmt->attrGroups)) continue;

            foreach ($stmt->props as $pp) {
                $name = $pp->name->toString();
                $split = $this->splitAttributes($stmt->attrGroups);
                $blocks[] = [
                    'name'       => $name,
                    'colAttrs'   => $split['col'],
                    'hookAttrs'  => $split['hook'],
                    'hasContext' => $split['hasContext'],
                ];
                $fields[] = $name;
            }
        }

        // Emit trait (as raw code; property hooks not modeled in php-parser yet)
        $lines = [];
        $lines[] = "<?php";
        $lines[] = "declare(strict_types=1);";
        $lines[] = "";
        $lines[] = "namespace $traitNamespace;";
        $lines[] = "";
        $lines[] = "use Survos\\BabelBundle\\Attribute\\Translatable;";
        $lines[] = "use Doctrine\\ORM\\Mapping as ORM;";
        $lines[] = "";
        $lines[] = "trait $traitName";
        $lines[] = "{";

        foreach ($blocks as $b) {
            $name       = $b['name'];
            $colAttrs   = $b['colAttrs'];
            $hookAttrs  = $b['hookAttrs'];
            $hasContext = $b['hasContext'];

            if (!$colAttrs) {
                $colAttrs = ["#[ORM\\Column(type: 'text', nullable: true)]"];
            }
            foreach ($colAttrs as $a) {
                $lines[] = "    $a";
            }
            $lines[] = "    private ?string \${$name}Backing = null;";
            $lines[] = "";

            foreach ($hookAttrs as $a) {
                $lines[] = "    $a";
            }
            $ctx = $hasContext ? 'null /* context via attribute */' : "'$name'";
            $lines[] = "    public ?string \${$name} {";
            $lines[] = "        get => \$this->resolveTranslatable('$name', \$this->{$name}Backing, $ctx);";
            $lines[] = "        set => \$this->{$name}Backing = \$value;";
            $lines[] = "    }";
            $lines[] = "";
        }

        $lines[] = "}";

        file_put_contents($traitPath, implode("\n", $lines));

        return compact('traitFqcn', 'traitPath', 'fields', 'entityName', 'entityFqcn');
    }

    private function hasTranslatable(array $groups): bool
    {
        foreach ($groups as $g) {
            if (!$g instanceof AttributeGroup) continue;
            foreach ($g->attrs as $a) {
                $n = $a->name->toString();
                if ($n === 'Translatable' || $n === 'Survos\\BabelBundle\\Attribute\\Translatable') {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array{col:string[], hook:string[], hasContext:bool} */
    private function splitAttributes(array $groups): array
    {
        $col = [];
        $hook = [];
        $hasContext = false;

        foreach ($groups as $g) {
            if (!$g instanceof AttributeGroup) continue;
            foreach ($g->attrs as $a) {
                $name = $a->name->toString();
                $raw  = $this->printAttr($a);

                if ($name === 'Translatable' || $name === 'Survos\\BabelBundle\\Attribute\\Translatable') {
                    foreach ($a->args as $arg) {
                        if ($arg->name?->toString() === 'context') { $hasContext = true; break; }
                    }
                    $hook[] = $raw;
                    continue;
                }

                if (\in_array($name, ['Column','Doctrine\\ORM\\Mapping\\Column','ORM\\Column'], true) ||
                    str_ends_with($name, '\\Column')) {
                    $col[] = $raw;
                    continue;
                }

                $hook[] = $raw;
            }
        }

        return ['col' => $col, 'hook' => $hook, 'hasContext' => $hasContext];
    }

    private function printAttr(Attribute $a): string
    {
        $name = $a->name->toString();
        if ($name === 'Doctrine\\ORM\\Mapping\\Column') $name = 'ORM\\Column';

        $parts = [];
        foreach ($a->args as $arg) {
            $k = $arg->name?->toString();
            $v = (new \PhpParser\PrettyPrinter\Standard())->prettyPrintExpr($arg->value);
            $parts[] = $k ? ($k.': '.$v) : $v;
        }
        return $parts ? "#[$name(" . implode(', ', $parts) . ')]' : "#[$name]";
    }

    private function namespaceToPath(string $ns, string $projectDir): string
    {
        $rel = str_replace('\\', '/', $ns);
        if (str_starts_with($rel, 'App/')) {
            return rtrim($projectDir, '/').'/src/'.substr($rel, 4);
        }
        // adjust if your bundles live elsewhere; this works for most libs
        return rtrim($projectDir, '/').'/src/'.$rel;
    }
}
