<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Entity\StrTranslation;
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
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    'babel:translate',
    'Translate blank StrTranslation rows for target locale(s) (event-first, optional TranslatorBundle fallback)'
)]
final class TranslateCommand
{
    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly LoggerInterface           $logger,
        private readonly LocaleContext             $localeContext,
        private readonly EventDispatcherInterface  $dispatcher,
        private readonly TranslatableIndex         $index,
        private readonly ?ExternalTranslatorBridge $bridge = null, // soft dependency
    ) {
    }

    /**
     * @return list<string>
     */
    private function enabledLocales(): array
    {
        $enabled = $this->localeContext->getEnabled();
        if ($enabled !== []) {
            return array_values(array_unique($enabled));
        }

        $default = $this->localeContext->getDefault();
        return $default ? [$default] : [];
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Target locales CSV (e.g. "es,fr"). If omitted, you will be prompted.')]
        ?string $localesArg = null,

        #[Option('Use all framework.enabled_locales when locales argument is empty')]
        bool $all = false,

        #[Option('Limit number to translate per locale (0 = unlimited)')]
        int $limit = 0,

        #[Option('Batch size for flush/clear')]
        int $batch = 50,

        #[Option('Dry-run: do not write')]
        bool $dryRun = false,

        #[Option('Engine name (TranslatorBundle). If omitted and bundle is present, uses default engine.')]
        ?string $engine = null,

        #[Option('Str entity FQCN (must extend StrBase)')]
        string $strClass = Str::class,

        #[Option('StrTranslation entity FQCN (must extend StrTranslationBase)')]
        string $trClass = StrTranslation::class,

        #[Option('Override *source* locale when Str.sourceLocale is empty')]
        ?string $srcLocaleOverride = null,
    ): int {
        // Prompt if no locales provided and not --all
        if (!$localesArg && !$all) {
            $choices = $this->enabledLocales();
            if ($choices === []) {
                $io->error('No enabled locales found (and no default locale).');
                return Command::FAILURE;
            }

            $localesArg = (string) $io->askQuestion(
                new ChoiceQuestion('Translate to which locale?', $choices)
            );
        }

        $targets = $this->resolveTargetLocales($io, $localesArg, $all);
        if ($targets === null) {
            $io->warning('No target locales found.');
            return Command::FAILURE;
        }

        // Optional global source-locale override
        if ($srcLocaleOverride) {
            $this->localeContext->set($srcLocaleOverride);
        }

        // Engine requested but TranslatorBundle missing
        if ($engine !== null && (!$this->bridge || !$this->bridge->isAvailable())) {
            $io->error(implode("\n", [
                'The --engine option requires SurvosTranslatorBundle.',
                'Install it first:',
                '  composer require survos/translator-bundle',
            ]));
            return Command::FAILURE;
        }

        // Entity sanity checks
        if (!class_exists($strClass) || !class_exists($trClass)) {
            $io->error('Str / StrTranslation class not found.');
            return Command::FAILURE;
        }
        if (!is_a($strClass, StrBase::class, true)) {
            $io->error('Str class must extend StrBase.');
            return Command::FAILURE;
        }
        if (!is_a($trClass, StrTranslationBase::class, true)) {
            $io->error('StrTranslation class must extend StrTranslationBase.');
            return Command::FAILURE;
        }

        $strRepo = $this->em->getRepository($strClass);
        $trRepo  = $this->em->getRepository($trClass);

        $grandTotal = 0;

        foreach ($targets as $targetLocale) {
            $io->section(sprintf('Locale: %s', $targetLocale));

            $qb = $trRepo->createQueryBuilder('t')
                ->andWhere('t.targetLocale = :loc')->setParameter('loc', $targetLocale)
                ->andWhere('(t.text IS NULL OR t.text = \'\')')
                ->andWhere('t.status = :status')->setParameter('status', StrTranslationBase::STATUS_NEW)
                ->orderBy('t.strCode', 'ASC');

            if ($limit > 0) {
                $qb->setMaxResults($limit);
            }

            $iter = $qb->getQuery()->toIterable();
            $done = 0;

            foreach ($iter as $tr) {
                /** @var StrTranslationBase $tr */
                $code = $tr->strCode ?? null;

                if (!$code) {
                    $this->logger->warning('StrTranslation missing strCode; skipping.', [
                        'locale' => $targetLocale,
                    ]);
                    $done++;
                    continue;
                }

                /** @var StrBase|null $str */
                $str = $strRepo->findOneBy(['code' => $code]);
                if (!$str) {
                    $this->logger->warning('Missing Str for StrTranslation; skipping.', [
                        'str_code' => $code,
                        'locale'   => $targetLocale,
                    ]);
                    $done++;
                    continue;
                }

                $srcLocale = $this->resolveSourceLocaleForStr($str);

                // Skip same-locale “translation”
                if ($srcLocale === $targetLocale) {
                    $done++;
                    continue;
                }

                $original = $str->source;

                // 1) EVENT FIRST
                $event = new TranslateStringEvent(
                    hash:         $code,          // canonical STR key
                    original:     $original,
                    sourceLocale: $srcLocale,
                    targetLocale: $targetLocale,
                    translated:   null
                );
                $this->dispatcher->dispatch($event);
                $translated = $event->translated;

                // 2) FALLBACK: TranslatorBundle
                if (($translated === null || $translated === '')
                    && $this->bridge
                    && $this->bridge->isAvailable()
                ) {
                    try {
                        $result     = $this->bridge->translate($original, $srcLocale, $targetLocale, $engine);
                        $translated = (string) ($result['translatedText'] ?? '');
                    } catch (\Throwable $e) {
                        $this->logger->warning('Translator fallback failed', [
                            'str_code' => $code,
                            'src'      => $srcLocale,
                            'dst'      => $targetLocale,
                            'err'      => $e->getMessage(),
                        ]);
                        $translated = null;
                    }
                }

                if ($translated === null || $translated === '') {
                    $done++;
                    continue;
                }

                if (!$dryRun) {
                    $tr->text      = $translated;
                    $tr->status    = StrTranslationBase::STATUS_TRANSLATED;
                    $tr->engine    = $engine;
                    $tr->updatedAt = new \DateTimeImmutable();
                }

                $done++;
                $grandTotal++;

                if (!$dryRun && ($done % $batch) === 0) {
                    $this->em->flush();
                    $this->em->clear();
                    $strRepo = $this->em->getRepository($strClass);
                    $trRepo  = $this->em->getRepository($trClass);
                }
            }

            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
            }

            $io->success(sprintf('Locale %s: processed %d row(s).', $targetLocale, $done));
        }

        $io->success(sprintf('Total translations written: %d', $grandTotal));
        if ($dryRun) {
            $io->note('Dry-run: no changes were written.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function resolveTargetLocales(
        SymfonyStyle $io,
        ?string $localesArg,
        bool $all
    ): ?array {
        if ($localesArg && trim($localesArg) !== '') {
            $targets = array_values(array_filter(array_map('trim', explode(',', $localesArg))));
            return $targets !== [] ? $targets : null;
        }

        if ($all) {
            $locales = $this->enabledLocales();
            if ($locales === []) {
                $io->warning('No enabled locales found.');
                return null;
            }
            return $locales;
        }

        $io->warning('Specify locales (e.g. "es,fr") or pass --all.');
        return null;
    }

    private function resolveSourceLocaleForStr(object $str): string
    {
        $acc = $this->index->localeAccessorFor($str::class)
            ?? ['type' => 'prop', 'name' => 'sourceLocale'];

        if ($acc['type'] === 'prop' && property_exists($str, $acc['name'])) {
            $v = $str->{$acc['name']} ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        if ($acc['type'] === 'method' && method_exists($str, $acc['name'])) {
            $v = $str->{$acc['name']}();
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return $this->localeContext->getDefault();
    }
}
