<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Contract\TranslatorInterface as TextTranslatorInterface;
use Survos\BabelBundle\Entity\Base\StrBase;
use Survos\BabelBundle\Entity\Base\StrTranslationBase;
use Survos\BabelBundle\Event\TranslateStringEvent;
use Survos\BabelBundle\Service\ExternalTranslatorBridge;
use Survos\BabelBundle\Service\FakeTranslatorService;
use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('babel:translate', 'Translate blank StrTranslation rows for target locale(s) (event-first, optional provider fallback)')]
final class TranslateCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LocaleContext $localeContext,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?ExternalTranslatorBridge $bridge = null,
        private readonly ?TextTranslatorInterface $textProvider = null, // optional default provider
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('Target locales (comma-delimited) or empty to use --all')] ?string $localesArg = null,
        #[Option('Use all framework.enabled_locales when locales argument is empty')] bool $all = false,
        #[Option('Limit number to translate per locale (0 = unlimited)')] int $limit = 0,
        #[Option('Dry-run: do not write')] bool $dryRun = false,
        #[Option('Engine short name: libre|dummy|fake (overridden by --engine-class)')] ?string $engine = null,
        #[Option('Engine FQCN implementing Survos\BabelBundle\Contract\TextTranslatorInterface')] ?string $engineClass = null,
        #[Option('Str entity FQCN (must extend StrBase)')] string $strClass = 'App\\Entity\\Str',
        #[Option('StrTranslation entity FQCN (must extend StrTranslationBase)')] string $trClass  = 'App\\Entity\\StrTranslation',
        #[Option('Override *source* locale when Str.srcLocale is null')] ?string $srcLocaleOverride = null,
    ): int {
        // Allow per-run source-locale override
        if ($srcLocaleOverride) {
            $this->localeContext->set($srcLocaleOverride);
        }

        // Resolve target locales
        $targets = $this->resolveTargetLocales($io, $localesArg, $all);
        if ($targets === null) return 1;

        // Resolve provider (optional fallback after event)
        $provider = $this->resolveProvider($io, $engine, $engineClass);

        // Entity checks (inheritance)
        if (!class_exists($strClass) || !class_exists($trClass)) {
            $io->error('Str/StrTranslation classes not found.'); return 1;
        }
        if (!is_a($strClass, StrBase::class, true)) {
            $io->error(sprintf('Str class must extend %s.', StrBase::class)); return 1;
        }
        if (!is_a($trClass, StrTranslationBase::class, true)) {
            $io->error(sprintf('StrTranslation class must extend %s.', StrTranslationBase::class)); return 1;
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
            if ($limit > 0) $qb->setMaxResults($limit);

            $iter = $qb->getQuery()->toIterable();
            $done = 0;

            foreach ($iter as $tr) {
                /** @var StrTranslationBase $tr */
                $hash = $tr->hash;

                /** @var StrBase|null $str */
                $str = $strRepo->find($hash);
                if (!$str) {
                    dd($hash, $str, $tr);
                    continue;
                }
                // @todo: remove this or handle better
                if ($str->srcLocale  === $tr->locale) {
                    continue;
                }

                $original  = $str->original;
                $srcLocale = $str->srcLocale ?: $this->localeContext->getDefault();

                // 1) EVENT FIRST: let listeners provide a translation
                $evt = new TranslateStringEvent(
                    hash:         $hash,
                    original:     $original,
                    sourceLocale: $srcLocale,
                    targetLocale: $targetLocale,
                    translated:   null
                );
                $this->dispatcher->dispatch($evt);
                $translated = $evt->translated;

                // 2) FALLBACK PROVIDER (optional)
                if (($translated === null || $translated === '') && $provider) {
                    if ($engine !== null && !$this->bridge->isAvailable()) {
                        $io->error(implode("\n", [
                            'The --engine option requires SurvosTranslatorBundle.',
                            'Install it first:',
                            '  composer require survos/translator-bundle',
                        ]));
                        return Command::FAILURE;
                    }
                    try {
                        $translated = $provider->translate($original, $srcLocale, $targetLocale);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Provider translate failed', [
                            'hash'=>$hash,'src'=>$srcLocale,'dst'=>$targetLocale,'err'=>$e->getMessage(),
                        ]);
                        $translated = null;
                    }
                }

                // Nothing to write?
                if ($translated === null || $translated === '') {
                    $done++; // still counted as processed
                    continue;
                }

                if (!$dryRun) {
                    // public props; property hooks are fine
                    $tr->text      = $translated;
                    if (\property_exists($tr, 'updatedAt')) {
                        $tr->updatedAt = new \DateTimeImmutable();
                    }
                }

                $done++;
                $grandTotal++;

                if (!$dryRun && ($done % 200) === 0) {
                    $this->em->flush();
                    $this->em->clear(); // ORM 3: clear all
                    $strRepo = $this->em->getRepository($strClass);
                    $trRepo  = $this->em->getRepository($trClass);
                }
            }

            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
                $strRepo = $this->em->getRepository($strClass);
                $trRepo  = $this->em->getRepository($trClass);
            }

            $io->success(sprintf('Locale %s: translated %d row(s).', $targetLocale, $done));
        }

        $io->success(sprintf('Total translations written: %d', $grandTotal));
        if ($dryRun) $io->note('Dry-run: no changes were written.');
        return 0;
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
            if ($locales === []) { $io->warning('No enabled locales found.'); return null; }
            return array_values(array_unique($locales));
        }
        $io->warning('Specify locales (e.g. "es,fr") or pass --all.');
        return null;
    }

    private function resolveProvider(SymfonyStyle $io, ?string $engine, ?string $engineClass): ?TextTranslatorInterface
    {
        // explicit class wins
        if ($engineClass) {
            if (!class_exists($engineClass)) {
                $io->error("Engine class {$engineClass} not found."); return null;
            }
            $instance = new $engineClass();
            return $instance instanceof TextTranslatorInterface ? $instance : null;
        }

        if ($engine) {
            $engine = strtolower($engine);
            if ($engine === 'fake') {
                // Wrap FakeTranslatorService (Symfony\Contracts\Translation\TranslatorInterface) into TextTranslatorInterface
                if (!class_exists(FakeTranslatorService::class)) {
                    $io->error('Fake translator not available in this build.'); return null;
                }
                $symfonyTranslator = new FakeTranslatorService();
                return new class($symfonyTranslator) implements TextTranslatorInterface {
                    public function __construct(private TranslatorInterface $t) {}
                    public function translate(string $text, string $fromLocale, string $toLocale): string
                    {
                        // Delegate via Symfony TranslatorInterface (“id = text” is fine for fake)
                        return $this->t->trans($text, [], null, $toLocale);
                    }
                };
            }

            $map = [
                'dummy' => \Survos\BabelBundle\Translator\DummyTranslator::class,
                'libre' => \Survos\BabelBundle\Translator\LibreTranslateClient::class,
            ];
            $class = $map[$engine] ?? null;
            if (!$class || !class_exists($class)) {
                $io->error(sprintf('Unknown/missing engine "%s". Try --engine=fake|libre or use --engine-class=FQCN.', $engine));
                return null;
            }
            $instance = new $class();
            return $instance instanceof TextTranslatorInterface ? $instance : null;
        }

        return $this->textProvider;
    }
}
