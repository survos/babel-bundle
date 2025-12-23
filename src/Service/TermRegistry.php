<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Entity\StrTranslation;
use Survos\BabelBundle\Entity\Term;
use Survos\BabelBundle\Entity\TermSet;

/**
 * Minimal WIP registry for controlled vocabulary.
 *
 * Important: repository queries won't see scheduled inserts until flush(), so we cache ensures.
 * Also: to participate in babel:push/babel:pull (stub-driven), we create STR_TR stubs for term labels.
 */
final class TermRegistry
{
    /** @var array<string, TermSet> */
    private array $setCache = [];

    /** @var array<string, Term> */
    private array $termCache = [];

    /** @var array<string, string> */
    private array $strCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocaleContext $localeContext,
    ) {}

    public function ensureTermSet(string $setCode, ?string $label = null, ?string $description = null): TermSet
    {
        if (isset($this->setCache[$setCode])) {
            $set = $this->setCache[$setCode];
        } else {
            /** @var TermSet|null $set */
            $set = $this->entityManager->getRepository(TermSet::class)->findOneBy(['code' => $setCode]);
            if (!$set) {
                $set = new TermSet();
                $set->code = $setCode;
                $set->enabled = true;
                $this->entityManager->persist($set);
            }
            $this->setCache[$setCode] = $set;
        }

        if ($label !== null) {
            $set->labelCode = $this->ensureStrCode($label, context: "term_set:$setCode:label", createStubs: true);
        }
        if ($description !== null) {
            $set->descriptionCode = $this->ensureStrCode($description, context: "term_set:$setCode:description", createStubs: true);
        }

        return $set;
    }

    public function ensureTerm(
        string $setCode,
        string $termCode,
        ?string $label = null,
        ?string $description = null,
        ?string $path = null,
    ): Term {
        $cacheKey = $setCode . '|' . $termCode;

        if (isset($this->termCache[$cacheKey])) {
            $term = $this->termCache[$cacheKey];
        } else {
            $set = $this->ensureTermSet($setCode);

            /** @var Term|null $term */
            $term = $this->entityManager->getRepository(Term::class)->findOneBy([
                'termSet' => $set,
                'code' => $termCode,
            ]);

            if (!$term) {
                $term = new Term();
                $term->termSet = $set;
                $term->code = $termCode;
                $term->path = $path ?? $termCode;
                $term->enabled = true;
                $this->entityManager->persist($term);
            } elseif (($term->path ?? '') === '') {
                $term->path = $path ?? $termCode;
            }

            $this->termCache[$cacheKey] = $term;
        }

        if ($label !== null) {
            $term->labelCode = $this->ensureStrCode($label, context: "term:$setCode:$termCode:label", createStubs: true);
        }
        if ($description !== null) {
            $term->descriptionCode = $this->ensureStrCode($description, context: "term:$setCode:$termCode:description", createStubs: true);
        }

        return $term;
    }

    /**
     * Creates (or returns existing) Str.code for the given source text + locale + context.
     *
     * If createStubs=true, also creates STR_TR stub rows (engine=babel, text NULL) for target locales so
     * babel:push can find them.
     */
    public function ensureStrCode(string $source, ?string $context = null, bool $createStubs = false): string
    {
        $sourceLocale = $this->localeContext->get();
        assert($sourceLocale);

        $cacheKey = $sourceLocale . '|' . ($context ?? '') . '|' . $source;
        if (isset($this->strCache[$cacheKey])) {
            return $this->strCache[$cacheKey];
        }

        $code = hash('xxh3', $cacheKey);

        /** @var Str|null $str */
        $str = $this->entityManager->getRepository(Str::class)->findOneBy(['code' => $code]);
        if ($str) {
            if ($createStubs) {
                $this->ensureTranslationStubs($code, $sourceLocale);
            }
            return $this->strCache[$cacheKey] = $code;
        }

        $str = new Str();
        $str->code = $code;
        $str->sourceLocale = $sourceLocale;
        $str->source = $source;
        $str->context = $context;
        $this->entityManager->persist($str);

        if ($createStubs) {
            $this->ensureTranslationStubs($code, $sourceLocale);
        }

        return $this->strCache[$cacheKey] = $code;
    }

    private function ensureTranslationStubs(string $strCode, string $sourceLocale, string $engine = 'babel'): void
    {
        $targets = $this->targetLocales();
        foreach ($targets as $loc) {
            if ($loc === $sourceLocale) {
                continue;
            }

            /** @var StrTranslation|null $tr */
            $tr = $this->entityManager->getRepository(StrTranslation::class)->findOneBy([
                'strCode' => $strCode,
                'targetLocale' => $loc,
                'engine' => $engine,
            ]);

            if ($tr) {
                continue;
            }

            $tr = new StrTranslation();
            $tr->strCode = $strCode;
            $tr->targetLocale = $loc;
            $tr->engine = $engine;
            $tr->text = null;

            $this->entityManager->persist($tr);
        }
    }

    /**
     * Best-effort target locale list.
     * Prefer LocaleContext facilities if present; otherwise fall back to enabled_locales.
     *
     * This method is intentionally defensive to avoid hard coupling while things are in flux.
     *
     * @return list<string>
     */
    private function targetLocales(): array
    {
        // If LocaleContext grows a first-class API later, use it.
        foreach (['getTargetLocales', 'getEnabledLocales'] as $m) {
            if (\method_exists($this->localeContext, $m)) {
                $v = $this->localeContext->{$m}();
                if (\is_array($v) && $v) {
                    return array_values(array_unique(array_map('strval', $v)));
                }
            }
        }

        // Last resort: use the currently configured enabled locales if LocaleContext exposes them via property.
        if (\property_exists($this->localeContext, 'enabledLocales')) {
            $v = $this->localeContext->enabledLocales;
            if (\is_array($v) && $v) {
                return array_values(array_unique(array_map('strval', $v)));
            }
        }

        // Safe default: do nothing (no stubs => no pushes)
        return [];
    }
}
