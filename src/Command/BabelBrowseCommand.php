<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Command;

use Survos\BabelBundle\Service\LocaleContext;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('babel:browse', 'Browse missing translations by locale (engine-aware)')]
final class BabelBrowseCommand
{
    public function __construct(
        private readonly LocaleContext $ctx,
        // New engine (preferred). If not present in your app, this will be null and we'll use legacy store.
        ?object $stringStorage = null,                // Survos\BabelBundle\Service\Engine\StringStorage
        ?object $translationStore = null,             // Survos\BabelBundle\Service\TranslationStore (legacy)
    ) {
        // promote to properties but keep them optional
        $this->engine = $stringStorage;
        $this->legacy = $translationStore;
    }

    private ?object $engine = null; // expects iterateMissing(string $locale, ?string $source = null, int $limit = 0): iterable
    private ?object $legacy = null; // expects iterateMissing(string $locale, ?string $source = null, int $limit = 0): iterable

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('locale', 'Target locale (e.g. en, es, de). Defaults to current context')] ?string $locale = null,
        #[Option('source', 'Filter by source locale (if tracked on your Str records)')] ?string $source = null,
        #[Option('limit', 'Max rows to display (0 = no limit)')] int $limit = 50,
        #[Option('entity', 'Optional entity class to show in the header only (no filtering)')] ?string $entity = null,
    ): int {
        $locale = $this->normalize($locale ?? $this->ctx->get());

            // Pick the right backend
            $iterable = null;
            if ($this->engine && \method_exists($this->engine, 'iterateMissing')) {
                // New preferred engine
                $iterable = $this->engine->iterateMissing($locale, $source, $limit);
                $backend = 'engine:StringStorage';
            } elseif ($this->legacy && \method_exists($this->legacy, 'iterateMissing')) {
                // Legacy TranslationStore fallback
                $iterable = $this->legacy->iterateMissing($locale, $source, $limit);
                $backend = 'legacy:TranslationStore';
            } else {
                $io->error('No suitable backend found. Expecting either Service\Engine\StringStorage or Service\TranslationStore.');
                return 2;
            }

            $title = sprintf('Missing translations%s for [%s] via %s',
                $entity ? " in $entity" : '',
                $locale,
                $backend
            );
            $io->title($title);

        $rows = [];
        foreach ($iterable as $str) {
            // We expect Str-like records with public props; adapt if your app maps getters instead.
            $hash    = $str->hash ?? null;
            $orig    = (string)($str->original ?? '');
            $context = (string)($str->context ?? '');
            $srcLoc  = (string)($str->srcLocale ?? '');

            $rows[] = [
                $hash,
                mb_strimwidth($orig, 0, 80, '…'),
                $context,
                $srcLoc,
            ];
        }

        if (!$rows) {
            $io->success('No missing translations found.');
            return 0;
        }

        $io->table(['Hash', 'Original', 'Context', 'Src'], $rows);
        $io->note(sprintf('Locale: %s  • Source filter: %s  • Rows: %d',
            $locale,
            $source ?: '(any)',
            count($rows)
        ));

        return 0;
    }

    private function normalize(string $locale): string
    {
        $locale = \str_replace('_', '-', \trim($locale));
        if (\preg_match('/^([a-zA-Z]{2,3})(?:-([A-Za-z]{2}))?$/', $locale, $m)) {
            $lang = \strtolower($m[1]);
            $reg  = isset($m[2]) ? '-'.\strtoupper($m[2]) : '';
            return $lang.$reg;
        }
        return $locale;
    }
}
