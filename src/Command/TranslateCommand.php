<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Entity\Base\StrBase;
use Survos\BabelBundle\Entity\Base\StrTranslationBase;
use Survos\BabelBundle\Contract\TextTranslatorInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:translate', 'Translate blank StrTranslation rows for the target locale(s) using a selected engine')]
final class TranslateCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        // Optional: if you wire a default engine into the bundle later, it will be used unless overridden via --engine/--engine-class
        private readonly ?TextTranslatorInterface $textProvider = null,
        private readonly string $defaultLocale = 'en',
        /** @var string[] */
        private readonly array $enabledLocales = [],
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Target locales (comma-delimited) or empty to use --all')] ?string $localesArg = null,
        #[Option('Use all framework.enabled_locales when locales argument is empty')] bool $all = false,
        #[Option('Limit number to translate per locale (0 = unlimited)')] int $limit = 0,
        #[Option('Dry-run: do not write')] bool $dryRun = false,
        #[Option('Engine short name: libre|dummy (overridden by --engine-class)')] ?string $engine = null,
        #[Option('Engine FQCN implementing TextTranslatorInterface')] ?string $engineClass = null,
        #[Option('Str entity FQCN (must extend StrBase)')] string $strClass = 'App\\Entity\\Str',
        #[Option('StrTranslation entity FQCN (must extend StrTranslationBase)')] string $trClass  = 'App\\Entity\\StrTranslation',
    ): int {
        // Resolve locales
        $targets = $this->resolveTargetLocales($io, $localesArg, $all);
        if ($targets === null) {
            return 1; // message already shown
        }

        // Resolve engine
        $provider = $this->resolveProvider($io, $engine, $engineClass);
        if (!$provider) {
            $io->warning('No text translation provider is available. I will still scan and report, but won’t write translations.');
        }

        // Resolve entities and verify inheritance
        if (!class_exists($strClass)) {
            $io->error(sprintf('Str class "%s" was not found.', $strClass));
            return 1;
        }
        if (!class_exists($trClass)) {
            $io->error(sprintf('StrTranslation class "%s" was not found.', $trClass));
            return 1;
        }
        if (!is_a($strClass, StrBase::class, true)) {
            $io->error(sprintf('Str class "%s" must extend %s.', $strClass, StrBase::class));
            return 1;
        }
        if (!is_a($trClass, StrTranslationBase::class, true)) {
            $io->error(sprintf('StrTranslation class "%s" must extend %s.', $trClass, StrTranslationBase::class));
            return 1;
        }

        $strRepo = $this->em->getRepository($strClass);
        $trRepo  = $this->em->getRepository($trClass);

        $grandTotal = 0;

        foreach ($targets as $locale) {
            $io->section(sprintf('Locale: %s', $locale));

            // Only blank texts in existing rows (string-backed flow)
            $qb = $trRepo->createQueryBuilder('t')
                ->andWhere('t.locale = :loc')->setParameter('loc', $locale)
                ->andWhere('(t.text IS NULL OR t.text = \'\')')
                ->orderBy('t.hash', 'ASC');

            if ($limit > 0) {
                $qb->setMaxResults($limit);
            }

            $iterable = $qb->getQuery()->toIterable();
            $done = 0;

            foreach ($iterable as $row) {
                /** @var StrTranslationBase $tr */
                $tr = $row;

                $hash = $tr->getHash();
                if (!$hash) {
                    continue;
                }

                /** @var StrBase|null $str */
                $str = $strRepo->find($hash);
                if (!$str) {
                    continue;
                }

                $original  = $str->getOriginal();
                if (!\is_string($original) || $original === '') {
                    continue;
                }
                $srcLocale = $str->getSrcLocale() ?? $this->defaultLocale;

                // If no provider, we can only report
                if (!$provider) {
                    $done++;
                    continue;
                }

                try {
                    $translated = $provider->translate($original, (string) $srcLocale, $locale);
                } catch (\Throwable $e) {
                    $this->logger->warning('Translate failed', [
                        'hash' => $hash,
                        'src'  => $srcLocale,
                        'dst'  => $locale,
                        'err'  => $e->getMessage(),
                    ]);
                    continue;
                }

                if (!\is_string($translated) || $translated === '') {
                    // keep blank; maybe manual later
                    continue;
                }

                if (!$dryRun) {
                    // Property hooks/public properties are supported by the base class, but we use its API for clarity.
                    $tr->setText($translated);
                    $tr->touchUpdatedAt(); // convenience in StrTranslationBase (no-op if you didn’t add it)
                    $tr->markTranslated(); // convenience in StrTranslationBase (no-op if you didn’t add it)
                }

                $done++;
                $grandTotal++;

                if (!$dryRun && ($done % 200) === 0) {
                    $this->em->flush();
                    // Clear only the TR class to keep Str cache warm
                    $this->em->clear($trClass);
                }
            }

            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear($trClass);
            }

            $io->success(sprintf('Locale %s: %s %d row(s).',
                $locale,
                $provider ? 'translated' : 'scanned',
                $done
            ));
        }

        $io->success(sprintf('Total %s: %d', $provider ? 'translations written' : 'rows scanned', $grandTotal));
        if ($dryRun) {
            $io->note('Dry-run: no changes were written.');
        }

        return 0;
    }

    /**
     * @return list<string>|null
     */
    private function resolveTargetLocales(SymfonyStyle $io, ?string $localesArg, bool $all): ?array
    {
        if ($localesArg && \trim($localesArg) !== '') {
            $targets = array_values(array_filter(array_map('trim', explode(',', $localesArg))));
            return $targets !== [] ? $targets : null;
        }

        if ($all) {
            $locales = $this->enabledLocales ?: [$this->defaultLocale];
            if ($locales === []) {
                $io->warning('No enabled locales found. Provide locales or configure framework.enabled_locales.');
                return null;
            }
            return array_values(array_unique($locales));
        }

        $io->warning('Specify locales (e.g. "es,fr") or pass --all to use framework.enabled_locales.');
        return null;
    }

    private function resolveProvider(SymfonyStyle $io, ?string $engine, ?string $engineClass): ?TextTranslatorInterface
    {
        // 1) explicit class wins
        if ($engineClass) {
            if (!class_exists($engineClass)) {
                $io->error(sprintf('Engine class "%s" not found.', $engineClass));
                return null;
            }
            $instance = new $engineClass();
            if (!$instance instanceof TextTranslatorInterface) {
                $io->error(sprintf('Engine class "%s" must implement %s.', $engineClass, TextTranslatorInterface::class));
                return null;
            }
            return $instance;
        }

        // 2) short names
        if ($engine) {
            $map = [
                'dummy' => \Survos\BabelBundle\Translator\DummyTranslator::class,
                'libre' => \Survos\BabelBundle\Translator\LibreTranslateClient::class,
            ];
            $class = $map[strtolower($engine)] ?? null;
            if (!$class) {
                $io->error(sprintf('Unknown engine "%s". Try --engine=libre or supply --engine-class=FQCN.', $engine));
                return null;
            }
            if (!class_exists($class)) {
                $io->error(sprintf('Engine "%s" is not installed in this app (missing class %s).', $engine, $class));
                return null;
            }
            $instance = new $class();
            if (!$instance instanceof TextTranslatorInterface) {
                $io->error(sprintf('Mapped engine class "%s" must implement %s.', $class, TextTranslatorInterface::class));
                return null;
            }
            return $instance;
        }

        // 3) fallback to injected default (may be null)
        return $this->textProvider;
    }
}
