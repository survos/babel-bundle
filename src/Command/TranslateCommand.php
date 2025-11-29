<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Entity\Base\StrBase;
use Survos\BabelBundle\Entity\Base\StrTranslationBase;
use Survos\BabelBundle\Event\TranslateStringEvent;
use Survos\BabelBundle\Service\ExternalTranslatorBridge;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand('babel:translate', 'Translate blank StrTranslation rows for target locale(s) (event-first, optional TranslatorBundle fallback)')]
final class TranslateCommand
{
    public function __construct(
        private readonly EntityManagerInterface   $em,
        private readonly LoggerInterface          $logger,
        private readonly LocaleContext            $localeContext,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TranslatableIndex        $index,
        private readonly ?ExternalTranslatorBridge $bridge = null, // soft dep; may be null
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('Target locales (comma-delimited) or empty to use --all')] ?string $localesArg = null,
        #[Option('Use all framework.enabled_locales when locales argument is empty')] bool $all = false,
        #[Option('Limit number to translate per locale (0 = unlimited)')] int $limit = 0,
        #[Option('batch flush')] int $batch = 10,
        #[Option('Dry-run: do not write')] bool $dryRun = false,
        #[Option('Engine name (TranslatorBundle). If omitted and bundle is present, uses default engine.')] ?string $engine = null,
        #[Option('Str entity FQCN (must extend Survos\\BabelBundle\\Entity\\Base\\StrBase)')] string $strClass = 'App\\Entity\\Str',
        #[Option('StrTranslation entity FQCN (must extend Survos\\BabelBundle\\Entity\\Base\\StrTranslationBase)')] string $trClass  = 'App\\Entity\\StrTranslation',
        #[Option('Override *source* locale when Str.srcLocale is null')] ?string $srcLocaleOverride = null,
    ): int {
        // Optional global source-locale override for this run
        if ($srcLocaleOverride) {
            $this->localeContext->set($srcLocaleOverride);
        }

        // Resolve target locales
        $targets = $this->resolveTargetLocales($io, $localesArg, $all);
        if ($targets === null) {
            return Command::FAILURE;
        }

        // If the user explicitly asked for an engine but the bridge is unavailable, fail early
        if ($engine !== null && (!$this->bridge || !$this->bridge->isAvailable())) {
            $io->error(implode("\n", [
                'The --engine option requires SurvosTranslatorBundle.',
                'Install it first:',
                '  composer require survos/translator-bundle',
            ]));
            return Command::FAILURE;
        }

        // Entity checks
        if (!class_exists($strClass) || !class_exists($trClass)) {
            $io->error('Str/StrTranslation classes not found.');
            return Command::FAILURE;
        }
        if (!is_a($strClass, StrBase::class, true)) {
            $io->error(sprintf('Str class must extend %s.', StrBase::class));
            return Command::FAILURE;
        }
        if (!is_a($trClass, StrTranslationBase::class, true)) {
            $io->error(sprintf('StrTranslation class must extend %s.', StrTranslationBase::class));
            return Command::FAILURE;
        }

        $strRepo = $this->em->getRepository($strClass);
        $trRepo  = $this->em->getRepository($trClass);

        $grandTotal = 0;

        foreach ($targets as $targetLocale) {
            $io->section(sprintf('Locale: %s', $targetLocale));

            // Only blank texts (NULL or empty string)
            $qb = $trRepo->createQueryBuilder('t')
                ->andWhere('t.locale = :loc')->setParameter('loc', $targetLocale)
                ->andWhere('(t.text IS NULL OR t.text = \'\')')
                ->orderBy('t.hash', 'ASC');
            if ($limit > 0) {
                $qb->setMaxResults($limit);
            }

            $iter = $qb->getQuery()->toIterable();
            $done = 0;

            foreach ($iter as $tr) {
                /** @var StrTranslationBase $tr */
                $trHash    = $tr->hash;      // translation key (e.g. "<strHash>-<locale>")
                $sourceKey = $tr->strHash ?? null; // ✅ canonical pointer to Str.hash

                if (!$sourceKey) {
                    $this->logger->warning('StrTranslation missing str_hash; skipping.', [
                        'tr.hash' => $trHash,
                        'locale'  => $targetLocale,
                    ]);
                    $done++;
                    continue;
                }

                /** @var StrBase|null $str */
                $str = $strRepo->find($sourceKey); // ✅ look up by Str.hash, not by TR.hash
                if (!$str) {
                    $this->logger->warning('Missing Str for StrTranslation; skipping.', [
                        'tr.hash'   => $trHash,
                        'tr.locale' => $targetLocale,
                        'str_hash'  => $sourceKey,
                    ]);
                    $done++;
                    continue;
                }

                // Skip same-locale “translation”
                if ($str->srcLocale === $tr->locale) {
                    $done++;
                    continue;
                }

                $original  = (string) $str->original;
                $srcLocale = $this->resolveSourceLocaleForStr($str);

                // 1) EVENT FIRST: let listeners provide a translation
                $evt = new TranslateStringEvent(
                    hash:         $sourceKey,     // ✅ pass the STR key (source), not the TR key
                    original:     $original,
                    sourceLocale: $srcLocale,
                    targetLocale: $targetLocale,
                    translated:   null
                );
                $this->dispatcher->dispatch($evt);
                $translated = $evt->translated;

                // 2) FALLBACK: TranslatorBundle (if present). If no --engine passed, use default engine.
                if (($translated === null || $translated === '') && $this->bridge && $this->bridge->isAvailable()) {
                    try {
                        $result     = $this->bridge->translate($original, $srcLocale, $targetLocale, $engine);
                        $translated = (string) ($result['translatedText'] ?? '');
                    } catch (\Throwable $e) {
                        $this->logger->warning('TranslatorBundle fallback failed', [
                            'str_hash' => $sourceKey, 'src' => $srcLocale, 'dst' => $targetLocale, 'err' => $e->getMessage(),
                        ]);
                        $translated = null;
                    }
                }

                // Nothing to write?
                if ($translated === null || $translated === '') {
                    $done++;
                    continue;
                }

                if (!$dryRun) {
                    // public props; if your entity has updatedAt, touch it
                    $tr->text = $translated;
                    if (\property_exists($tr, 'updatedAt')) {
                        $tr->updatedAt = new \DateTimeImmutable();
                    }
                }

                $done++;
                $grandTotal++;

                if (!$dryRun && ($done % $batch) === 0) {
                    $this->em->flush();
                    $this->em->clear();
                    $strRepo = $this->em->getRepository($strClass);
                    $trRepo  = $this->em->getRepository($trClass);
                    $io->success(sprintf('Locale %s: processed %d row(s).', $targetLocale, $done));
                }
            }

            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
                $strRepo = $this->em->getRepository($strClass);
                $trRepo  = $this->em->getRepository($trClass);
            }

            $io->success(sprintf('Locale %s: processed %d row(s).', $targetLocale, $done));
        }

        $io->success(sprintf('Total translations written: %d', $grandTotal));
        if ($dryRun) {
            $io->note('Dry-run: no changes were written.');
        }

        return Command::SUCCESS;
    }

    /** @return list<string>|null */
    private function resolveTargetLocales(SymfonyStyle $io, ?string $localesArg, bool $all): ?array
    {
        if ($localesArg && \trim($localesArg) !== '') {
            $targets = array_values(array_filter(array_map('trim', explode(',', $localesArg))));
            return $targets !== [] ? $targets : null;
        }
        if ($all) {
            $locales = $this->localeContext->getEnabled() ?: [$this->localeContext->getDefault()];
            if ($locales === []) {
                $io->warning('No enabled locales found.');
                return null;
            }
            return array_values(array_unique($locales));
        }
        $io->warning('Specify locales (e.g. "es,fr") or pass --all.');
        return null;
    }

    private function resolveSourceLocaleForStr(object $str): string
    {
        $acc = $this->index->localeAccessorFor($str::class) ?? ['type'=>'prop','name'=>'srcLocale','format'=>null];
        if ($acc['type']==='prop' && \property_exists($str, $acc['name'])) {
            $v = $str->{$acc['name']} ?? null;
            if (\is_string($v) && $v !== '') return $v;
        }
        if ($acc['type']==='method' && \method_exists($str, $acc['name'])) {
            $v = $str->{$acc['name']}();
            if (\is_string($v) && $v !== '') return $v;
        }
        return $this->localeContext->getDefault();
    }
}
