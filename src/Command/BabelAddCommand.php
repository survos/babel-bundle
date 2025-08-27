<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand('babel:add', 'Add a translatable property to an entity (backing + property hooks), with optional scaffolding')]
final class BabelAddCommand implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly Filesystem $fs = new Filesystem(),
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity class (FQCN or short, e.g. App\\Entity\\Article or Article)')] string $entity,
        #[Argument('Property name to add (e.g. bio)')] string $field,
        #[Option('Use TEXT instead of STRING')] bool $text = false,
        #[Option('Optional translation context (e.g. "article")')] ?string $context = null,
        #[Option('Optional human description for translators')] ?string $description = null,
        #[Option('Show diff but do not write file')] bool $dry = false,
        #[Option('Inject hooks trait when missing')] bool $forceHooks = false,
        #[Option('Make the backing column nullable')] bool $nullable = true,
        #[Option('Length for STRING (ignored for TEXT)')] int $length = 255,
        #[Option('Custom anchor comment name (fallbacks to built-in anchors)')] ?string $marker = null,
        #[Option('Also scaffold Str/StrTranslation (+ repos) in App if missing')] bool $scaffold = false,
        #[Option('Interactive mode (prompt for missing info)')] bool $interactive = false,
    ): int {
        $io->title('Babel Add (property hooks)');

        if ($interactive) {
            if (!\str_contains($entity, '\\')) {
                $entity = $io->ask('Entity (FQCN or short)', $entity ?: 'Article');
            }
            if (!$field) {
                $field = $io->ask('Property (e.g. title, bio)', 'title');
            }
            $text = $io->confirm('Use TEXT column type?', $text);
            if (!$text) {
                $length = (int)$io->ask('Length for STRING', (string)($length ?? 255));
            }
            $nullable = $io->confirm('Nullable backing column?', $nullable);
            $context = $io->ask('Context (optional)', $context);
            $description = $io->ask('Description (optional)', $description);
            $forceHooks = $io->confirm('Inject hooks trait if missing?', $forceHooks);
            $scaffold = $io->confirm('Scaffold App\\Entity\\Str(+Translation) if missing?', $scaffold);
            $dry = $io->confirm('Dry run (diff only)?', $dry);
        }

        $entityFqcn = $this->resolveFqcn($entity, ['', 'App\\Entity\\', 'App\\']);
        if (!class_exists($entityFqcn)) {
            $io->error(sprintf('Class "%s" not found. Tried: %s',
                $entity,
                implode(', ', $this->guesses($entity, ['', 'App\\Entity\\', 'App\\']))
            ));
            return Command::FAILURE;
        }

        if ($this->shouldStop) {
            $io->warning('Aborted (signal received).');
            return Command::FAILURE;
        }

        if ($scaffold) {
            $this->maybeScaffoldStr($io);
        }

        $refl = new ReflectionClass($entityFqcn);
        if (!$refl->isUserDefined()) {
            $io->error('Target class is not user-defined.');
            return Command::FAILURE;
        }
        $file = $refl->getFileName();
        if (!$file || !is_file($file) || !is_readable($file)) {
            $io->error('Cannot read entity source file.');
            return Command::FAILURE;
        }
        $original = (string)file_get_contents($file);
        if (!preg_match('/\bclass\s+'.preg_quote($refl->getShortName(), '/').'\b/s', $original)) {
            $io->error('Could not locate class declaration.');
            return Command::FAILURE;
        }

        $code = $original;
        $backing = $field.'Backing';

        // Idempotency: already added?
        if (preg_match('/\bpublic\s+\??string\s+\$'.preg_quote($field, '/').'\b/', $code) || str_contains($code, '$'.$backing)) {
            $io->success('Field already exists; nothing to do.');
            return Command::SUCCESS;
        }

        // Ensure hooks trait exists (user trait or bundle trait with --force-hooks)
        $hooksTraitFqcn = 'Survos\\BabelBundle\\Entity\\Traits\\BabelHooksTrait';
        if (!preg_match('/\buse\s+\\\\?'.preg_quote($hooksTraitFqcn, '/').'\s*;/i', $code)) {
            if (!$forceHooks) {
                $userTrait = $this->resolveExistingClass('CommonTranslatableFieldsTrait', [
                    'App\\Trait\\', 'App\\Traits\\', 'App\\Entity\\Trait\\', 'App\\Entity\\Traits\\',
                ]);
                if ($userTrait) {
                    $code = $this->ensureUse($code, $userTrait);
                    $code = $this->injectTraitUse($code, $userTrait);
                    $io->note(sprintf('Injected use %s;', $userTrait));
                } else {
                    $io->error('BabelHooksTrait missing. Re-run with --force-hooks to inject it.');
                    return Command::FAILURE;
                }
            } else {
                $code = $this->ensureUse($code, $hooksTraitFqcn);
                $code = $this->injectTraitUse($code, $hooksTraitFqcn);
                $io->note('Injected use Survos\\BabelBundle\\Entity\\Traits\\BabelHooksTrait;');
            }
        }

        // Keep short attribute names via imports
        $code = $this->ensureUse($code, 'Doctrine\\ORM\\Mapping\\Column');
        $code = $this->ensureUse($code, 'Doctrine\\DBAL\\Types\\Types');
        $code = $this->ensureUse($code, 'Survos\\BabelBundle\\Attribute\\Translatable');

        $colType = $text ? 'Types::TEXT' : 'Types::STRING';
        $nullableStr = $nullable ? 'true' : 'false';
        $lengthLine = $text ? '' : ', length: '.(int)($length ?? 255);
        $ctxPhp = var_export($context, true);
        $descPhp = var_export($description, true);

        $translatableArgs = 'context: '.$ctxPhp;
        if ($description !== null) {
            $translatableArgs .= ', description: '.$descPhp;
        }

        $block = <<<PHPBLOCK

        // <BABEL:TRANSLATABLE:START {$field}>
        #[Column(type: {$colType}{$lengthLine}, nullable: {$nullableStr})]
        private ?string \${$backing} = null;

        #[Translatable({$translatableArgs})]
        public ?string \${$field} {
            get => \$this->resolveTranslatable('{$field}', \$this->{$backing}, {$ctxPhp});
            set => \$this->{$backing} = \$value;
        }
        // <BABEL:TRANSLATABLE:END {$field}>

PHPBLOCK;

        if ($this->shouldStop) {
            $io->warning('Aborted (signal received).');
            return Command::FAILURE;
        }

        // Insert block
        $inserted = false;
        if ($marker) {
            $anchor = preg_quote($marker, '/');
            if (preg_match('/(\/\*\s*'.$anchor.'\s*\*\/)/', $code, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[1][1] + strlen($m[1][0]);
                $code = substr($code, 0, $pos)."\n".$block.substr($code, $pos);
                $inserted = true;
            }
        }

        if (!$inserted) {
            $start = '/\/\*\s*Translatable\s+Fields\s*\*\/\s*/i';
            $end   = '/\/\*\s*End\s+Translatable\s+Fields\s*\*\/\s*/i';
            if (preg_match($start, $code, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $code = substr($code, 0, $pos)."\n".$block.substr($code, $pos);
                if (!preg_match($end, $code)) {
                    $code = preg_replace('/\}\s*$/', "    /* End Translatable Fields */\n}\n", $code, 1);
                }
                $inserted = true;
            } elseif (preg_match($end, $code, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                $code = substr($code, 0, $pos).$block."\n".substr($code, $pos);
                $inserted = true;
            }
        }

        if (!$inserted) {
            $replaced = preg_replace('/\}\s*$/', rtrim($block)."\n}\n", $code, 1, $count);
            if ($count === 1) {
                $code = $replaced;
                $inserted = true;
            }
        }

        if (!$inserted) {
            $io->error('Failed to find a safe insertion point.');
            return Command::FAILURE;
        }

        if ($dry) {
            $io->writeln($this->diff($original, $code, $file));
            $io->success('Dry run (diff only).');
            return Command::SUCCESS;
        }

        // Backup & write
        $backup = $file.'.bak';
        $this->fs->copy($file, $backup, true);
        if (false === file_put_contents($file, $code)) {
            $io->error('Failed to write modified file.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Added translatable field "%s" to %s', $field, $entityFqcn));
        $io->writeln(sprintf('Backup saved to %s', $backup));

        return Command::SUCCESS;
    }

    // --- utilities --------------------------------------------------------

    private function resolveFqcn(string $input, array $prefixes): string
    {
        if (\str_contains($input, '\\')) {
            return $input;
        }
        foreach ($prefixes as $pfx) {
            $fq = $pfx.$input;
            if (class_exists($fq)) {
                return $fq;
            }
        }
        return $input;
    }

    private function guesses(string $input, array $prefixes): array
    {
        $out = [];
        if (\str_contains($input, '\\')) {
            $out[] = $input;
        }
        foreach ($prefixes as $pfx) {
            $out[] = $pfx.$input;
        }
        return $out;
    }

    private function resolveExistingClass(string $short, array $namespaces): ?string
    {
        foreach ($namespaces as $ns) {
            $fq = $ns.$short;
            if (class_exists($fq)) {
                return $fq;
            }
        }
        return null;
    }

    private function ensureUse(string $code, string $fqcn): string
    {
        $useLine = 'use '.$fqcn.';';
        if (preg_match('/^\s*use\s+\\\\?'.preg_quote($fqcn, '/').'\s*;/m', $code)) {
            return $code;
        }
        if (!preg_match('/^\s*namespace\s+[^;]+;\s*$/m', $code, $m, PREG_OFFSET_CAPTURE)) {
            return $code;
        }
        $nsEnd = $m[0][1] + strlen($m[0][0]);
        $head  = substr($code, 0, $nsEnd);
        $tail  = substr($code, $nsEnd);

        if (preg_match('/^(?:\s*use\s+[^;]+;\s*)+/m', $tail, $useBlock, PREG_OFFSET_CAPTURE)) {
            $pos = $nsEnd + $useBlock[0][1] + strlen($useBlock[0][0]);
            return substr($code, 0, $pos)."\n".$useLine."\n".substr($code, $pos);
        }

        return $head."\n".$useLine."\n".$tail;
    }

    private function injectTraitUse(string $code, string $traitFqcn): string
    {
        $short = ltrim(strrchr('\\'.$traitFqcn, '\\'), '\\') ?: $traitFqcn;
        $code = $this->ensureUse($code, $traitFqcn);

        if (preg_match('/\bclass\b[^{]*\{/', $code, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            if (preg_match('/\buse\s+'.$short.'\s*;\s*$/m', $code)) {
                return $code;
            }
            return substr($code, 0, $pos)."\n    use ".$short.";\n".substr($code, $pos);
        }
        return $code;
    }

    private function maybeScaffoldStr(SymfonyStyle $io): void
    {
        $root = getcwd();
        $entDir = $root.'/src/Entity';
        $repDir = $root.'/src/Repository';
        $this->fs->mkdir([$entDir, $repDir]);

        $str = $entDir.'/Str.php';
        $strTr = $entDir.'/StrTranslation.php';
        $strRepo = $repDir.'/StrRepository.php';
        $strTrRepo = $repDir.'/StrTranslationRepository.php';

        if (!is_file($str)) {
            file_put_contents($str, <<<'PHPX'
<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\StrBase;
use App\Repository\StrRepository;

#[ORM\Entity(repositoryClass: StrRepository::class)]
class Str extends StrBase {}
PHPX);
            $io->note('Created App\Entity\Str');
        }

        if (!is_file($strTr)) {
            file_put_contents($strTr, <<<'PHPX'
<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\StrTranslationBase;
use App\Repository\StrTranslationRepository;

#[ORM\Entity(repositoryClass: StrTranslationRepository::class)]
class StrTranslation extends StrTranslationBase {}
PHPX);
            $io->note('Created App\Entity\StrTranslation');
        }

        if (!is_file($strRepo)) {
            file_put_contents($strRepo, <<<'PHPX'
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Str;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StrRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Str::class); }
}
PHPX);
            $io->note('Created App\Repository\StrRepository');
        }

        if (!is_file($strTrRepo)) {
            file_put_contents($strTrRepo, <<<'PHPX'
<?php
declare(strict_types=1);

namespace App.Repository;

use App\Entity\StrTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StrTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, StrTranslation::class); }
}
PHPX);
            $io->note('Created App\Repository\StrTranslationRepository');
        }
    }

    private function diff(string $old, string $new, string $path): string
    {
        $o = preg_split("/\r\n|\r|\n/", $old);
        $n = preg_split("/\r\n|\r|\n/", $new);

        $out = [];
        $out[] = '--- '.$path;
        $out[] = '+++ '.$path;

        $i = $j = 0;
        while ($i < count($o) || $j < count($n)) {
            if ($i < count($o) && $j < count($n) && $o[$i] === $n[$j]) {
                $i++; $j++;
                continue;
            }
            $hOld = $i; $hNew = $j;
            $hunkOld = []; $hunkNew = [];
            for ($k = 0; $k < 200 && ($i < count($o) || $j < count($n)); $k++) {
                if ($i < count($o) && $j < count($n) && $o[$i] === $n[$j]) {
                    break;
                }
                $hunkOld[] = $i < count($o) ? $o[$i++] : null;
                $hunkNew[] = $j < count($n) ? $n[$j++] : null;
            }
            $lenOld = count(array_filter($hunkOld, static fn($l) => $l !== null));
            $lenNew = count(array_filter($hunkNew, static fn($l) => $l !== null));

            $out[] = sprintf('@@ -%d,%d +%d,%d @@', $hOld+1, max(1,$lenOld), $hNew+1, max(1,$lenNew));

            $p = $q = 0;
            while ($p < count($hunkOld) || $q < count($hunkNew)) {
                $lo = $p < count($hunkOld) ? $hunkOld[$p] : null;
                $ln = $q < count($hunkNew) ? $hunkNew[$q] : null;
                if ($lo !== null && $ln !== null && $lo === $ln) {
                    $out[] = ' '.$lo; $p++; $q++;
                } elseif ($lo !== null && ($ln === null || $lo !== $ln)) {
                    $out[] = '-'.$lo; $p++;
                } elseif ($ln !== null) {
                    $out[] = '+'.$ln; $q++;
                }
            }
        }
        return implode("\n", $out)."\n";
    }

    // --- SignalableCommandInterface ---------------------------------------

    public function getSubscribedSignals(): array
    {
        // Gracefully handle Ctrl-C / termination
        return \defined('SIGINT') && \defined('SIGTERM') ? [\SIGINT, \SIGTERM] : [];
    }


    public function handleSignal(int $signal, false|int $previousExitCode = 0): int|false
    {
        $this->shouldStop = true;
    }
}
