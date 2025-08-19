<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Service\TranslationStore;
use Survos\LibreTranslateBundle\Service\LibreTranslateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;

/**
 * Translate missing StrTranslation rows via LibreTranslate (or your MT engine).
 *
 * It looks for App\Entity\Str / App\Entity\StrTranslation by default.
 * If your project doesn't have those, it falls back to Pixie entities (sqlite) if present.
 *
 * Usage:
 *   bin/console babel:translate:missing "Target locale (e.g. es)" \
 *     --from "source locale default (e.g. en)" \
 *     --engine libre \
 *     --limit 1000 --batch 200 --dry-run
 */
#[AsCommand('babel:translate:missing', 'Populate missing StrTranslation rows using LibreTranslate.')]
final class BabelTranslateMissingCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslationStore $store,
        // optional: wire your LibreTranslate service, but we resolve lazily to avoid hard coupling
        private readonly ?LibreTranslateService $libreTranslateService = null,
    ) {

        if ($libreTranslateService === null) {
            throw new \Exception("composer req survos/libre-translate-bundle");
        }
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Target locale (e.g. es)')]
        string $targetLocale,

        #[Option('Default source locale (fallback if Str.srcLocale is null)', shortcut: 'f')]
        ?string $from = null,

        #[Option('Engine name (passed through to your service)')]
        string $engine = 'libre',

        #[Option('Only process rows whose Str.srcLocale matches this (optional filter)')]
        ?string $onlyFrom = null,

        #[Option('Optional context filter (e.g. "headline")', shortcut: 'c')]
        ?string $context = null,

        #[Option('Max # of Str rows to consider', shortcut: 'l')]
        int $limit = 0,

        #[Option('Flush batch size', shortcut: 'b')]
        int $batch = 200,

        #[Option('Dry run (do not write)', shortcut: 'd')]
        bool $dryRun = false
    ): int
    {
        // Resolve concrete class names (App first, then Pixie)
        $strClass = \class_exists('\\App\\Entity\\Str') ? '\\App\\Entity\\Str' :
                    (\class_exists('\\Survos\\PixieBundle\\Entity\\Str') ? '\\Survos\\PixieBundle\\Entity\\Str' : null);
        $trClass  = \class_exists('\\App\\Entity\\StrTranslation') ? '\\App\\Entity\\StrTranslation' :
                    (\class_exists('\\Survos\\PixieBundle\\Entity\\StrTranslation') ? '\\Survos\\PixieBundle\\Entity\\StrTranslation' : null);

        if (!$strClass || !$trClass) {
            $io->error('Cannot locate Str/StrTranslation entities (App or Pixie).');
            return 1;
        }

        // Resolve LibreTranslate service in a tolerant way (avoid hard dependency from BabelBundle)
        $lts = $this->libreTranslateService;
        if (!$lts) {
            // if Survos\LibreTranslateBundle is installed, try its default service id
            // otherwise we fail loudly when we try to translate
            try {
                $serviceId = 'Survos\\LibreTranslateBundle\\Service\\LibreTranslateService';
                if (\class_exists($serviceId)) {
                    // Symfony DI will proxy this argument if configured; this is just a best-effort fallback.
                    $lts = $this->libreTranslateService;
                }
            } catch (\Throwable) {}
        }

        $io->title('Translate missing StrTranslation rows');
        $io->writeln(sprintf('target: <info>%s</info> engine: <comment>%s</comment> %s',
            $targetLocale, $engine, $dryRun ? '(dry-run)' : ''
        ));

        // Build a DQL to find Str rows missing StrTranslation(targetLocale)
        $qb = $this->em->createQueryBuilder()
            ->select('s')
            ->from($strClass, 's');

        if ($onlyFrom) {
            $qb->andWhere('s.srcLocale = :onlyFrom')->setParameter('onlyFrom', $onlyFrom);
        }
        if ($context !== null) {
            $qb->andWhere('s.context = :ctx')->setParameter('ctx', $context);
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $strRows = $qb->getQuery()->toIterable([], \Doctrine\ORM\AbstractQuery::HYDRATE_OBJECT);

        $repoTr = $this->em->getRepository($trClass);

        $total   = 0;
        $created = 0;
        $skipped = 0;
        $iFlush  = 0;

        foreach ($strRows as $str) {
            ++$total;

            $srcLocale = $str->srcLocale ?? $from ?? 'en';

            // Skip if translation already exists
            $existing = $repoTr->findOneBy(['hash' => $str->hash, 'locale' => $targetLocale]);
            if ($existing) {
                ++$skipped;
                continue;
            }

            // Skip empties
            $valueToTranslate = trim((string)$str->original);
            if ($valueToTranslate === '') {
                ++$skipped;
                continue;
            }

            // Translate (current quick path uses LibreTranslate)
            if (!$lts || !\method_exists($lts, 'translateLine')) {
                $io->error('LibreTranslate service not available. Install/configure Survos\\LibreTranslateBundle or inject your engine.');
                return 1;
            }

            try {
                /** @var string $translatedValue */
                $translatedValue = $lts->translateLine(
                    $valueToTranslate,
                    to: $targetLocale,
                    from: $srcLocale,
                    engine: $engine
                );
            } catch (\Throwable $e) {
                $io->warning(sprintf('Failed [%s->%s] "%s": %s',
                    $srcLocale, $targetLocale, mb_strimwidth($valueToTranslate, 0, 60, '…'), $e->getMessage()
                ));
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('DRY: %s %s "%s" -> "%s"',
                    $str->hash, $srcLocale,
                    mb_strimwidth($valueToTranslate, 0, 40, '…'),
                    mb_strimwidth($translatedValue,   0, 40, '…')
                ));
                ++$created;
                continue;
            }

            // Persist via TranslationStore to keep behavior consistent
            $this->store->upsert(
                hash:      $str->hash,
                original:  $str->original,
                srcLocale: $srcLocale,
                context:   $str->context,
                locale:    $targetLocale,
                text:      $translatedValue
            );

            $this->em->flush(); // write Str (if new) + StrTranslation
            ++$created;

            if ((++$iFlush % $batch) === 0) {
                $this->em->clear();
                $iFlush = 0;
            }
        }

        $io->success(sprintf('Processed %d, created %d, skipped %d.', $total, $created, $skipped));
        return 0;
    }
}
