<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use App\Entity\Str;
use App\Entity\StrTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Contract\TextTranslatorInterface; // optional; provide your own
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:translate', 'Translate blank StrTranslation rows for the target locale(s) using a text provider')]
final class TranslateCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ?TextTranslatorInterface $textProvider = null, // may be null
        private readonly string $defaultLocale = 'en',
        private readonly array $enabledLocales = [],
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Target locales (comma-delimited) or empty to use --all')] ?string $localesArg = null,
        #[Option('Use all framework.enabled_locales when locales argument is empty')] bool $all = false,
        #[Option('Limit number to translate per locale (0 = unlimited)')] int $limit = 0,
        #[Option('Dry-run: do not write')] bool $dryRun = false,
    ): int {
        $targets = [];
        if ($localesArg && \trim($localesArg) !== '') {
            $targets = array_values(array_filter(array_map('trim', explode(',', $localesArg))));
        } elseif ($all) {
            $targets = $this->enabledLocales ?: [$this->defaultLocale];
        } else {
            $io->warning('Specify locales (e.g. "es,fr") or pass --all to use framework.enabled_locales.');
            return 1;
        }

        if (!$this->textProvider) {
            $io->warning('No text translation provider is configured (service implementing TextTranslatorInterface). Skipping.');
//            return 0;
        }

        $strRepo = $this->em->getRepository(Str::class);
        $trRepo  = $this->em->getRepository(StrTranslation::class);

        $total = 0;
        foreach ($targets as $locale) {
            $io->section(sprintf('Locale: %s', $locale));

            // blank translations for this locale
            $qb = $trRepo->createQueryBuilder('t')
                ->andWhere('t.locale = :loc')->setParameter('loc', $locale)
                ->andWhere("(t.text IS NULL OR t.text = '')");

            if ($limit > 0) $qb->setMaxResults($limit);

            $rows = $qb->getQuery()->toIterable();
            $done = 0;

            foreach ($rows as $tr) {
                // Join Str to get original + srcLocale
                $hash = null;
                if (\method_exists($tr, 'getHash')) $hash = $tr->getHash();
                elseif (\property_exists($tr, 'hash')) $hash = $tr->hash ?? null;

                if (!$hash) continue;

                /** @var Str|null $str */
                $str = $strRepo->find($hash);
                if (!$str) continue;

                $original  = \method_exists($str, 'getOriginal') ? $str->getOriginal() : (\property_exists($str, 'original') ? $str->original : null);
                $srcLocale = \method_exists($str, 'getSrcLocale') ? $str->getSrcLocale() : (\property_exists($str, 'srcLocale') ? $str->srcLocale : $this->defaultLocale);

                if (!\is_string($original) || $original === '') continue;

                // Translate
                try {
                    $translated = $this->textProvider->translate($original, (string)$srcLocale, $locale);
                } catch (\Throwable $e) {
                    $this->logger->warning('Translate failed for hash='.$hash, ['err'=>$e->getMessage()]);
                    continue;
                }

                if (!\is_string($translated) || $translated === '') {
                    continue; // keep blank; maybe manual later
                }

                if (!$dryRun) {
                    $tr->text = $translated;
                    if (\property_exists($tr, 'status')) {
                        $tr->status = 'translated';
                    }
                    if (\property_exists($tr, 'updatedAt')) {
                        $tr->updatedAt = new \DateTimeImmutable();
                    }
                }

                $done++;
                $total++;

                if (!$dryRun && ($done % 200) === 0) {
                    $this->em->flush();
                    $this->em->clear(StrTranslation::class);
                }
            }

            if (!$dryRun) $this->em->flush();
            $io->success(sprintf('Locale %s: translated %d rows', $locale, $done));
        }

        $io->success(sprintf('Total translations written: %d', $total));
        if ($dryRun) $io->note('Dry-run: no changes were written.');
        return 0;
    }
}
