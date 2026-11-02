<?php

declare(strict_types=1);

namespace Survos\BabelBundle\Search;

use Doctrine\ORM\QueryBuilder;
use Survos\BabelBundle\Contract\BabelHooksInterface;
use Survos\BabelBundle\Entity\Str;
use Survos\BabelBundle\Entity\StrTranslation;
use Survos\BabelBundle\Service\LocaleContext;
use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;

/** Source-set subqueries keep translated matching inside SQL without duplicate hits or facet counts. */
final readonly class TranslatedFieldPredicate
{
    public function __construct(private TranslatableIndex $index, private LocaleContext $locale)
    {
    }

    public function predicate(QueryBuilder $qb, string $field, string $parameter): ?string
    {
        if (!preg_match('/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)$/D', $field, $parts)) {
            return null;
        }
        [, $alias, $property] = $parts;
        $classes = array_combine($qb->getRootAliases(), $qb->getRootEntities());
        foreach ($qb->getDQLPart('join') as $joins) {
            foreach ($joins as $join) {
                $path = explode('.', $join->getJoin(), 2);
                if (2 === count($path) && isset($classes[$path[0]])) {
                    $parent = $qb->getEntityManager()->getClassMetadata($classes[$path[0]]);
                    if ($parent->hasAssociation($path[1])) {
                        $classes[$join->getAlias()] = $parent->getAssociationTargetClass($path[1]);
                    }
                }
            }
        }
        if (!isset($classes[$alias])) {
            return null;
        }
        $class = $classes[$alias];
        if (!is_a($class, BabelHooksInterface::class, true)) {
            return null;
        }
        $fields = $this->index->fieldsFor($class);
        $logical = str_ends_with($property, 'Backing') ? substr($property, 0, -7) : $property;
        if (!in_array($logical, $fields, true)) {
            return null;
        }
        $backing = $logical.'Backing';
        $metadata = $qb->getEntityManager()->getClassMetadata($class);
        if (!$metadata->hasField($backing)) {
            throw new \LogicException(sprintf('Babel search requires a mapped %s::%s field.', $class, $backing));
        }
        $value = $alias.'.'.$backing;
        $likeSource = sprintf('LOWER(%s) LIKE LOWER(:%s)', $value, $parameter);
        $display = HashUtil::normalizeLocale($this->locale->get());
        $source = $this->index->sourceLocaleFor($class);
        $accessor = $this->index->localeAccessorFor($class);
        $key = 'babel_search_'.substr(hash('sha256', $field), 0, 12);
        if (null !== $source && '' !== $source) {
            $source = HashUtil::normalizeLocale($source);
        } elseif (null === $accessor) {
            $source = HashUtil::normalizeLocale($this->locale->getDefault());
        } else {
            if ('prop' !== $accessor['type'] || !$metadata->hasField($accessor['name'])) {
                throw new \LogicException('Locale-aware Babel search needs a fixed source locale or a mapped locale property for '.$class);
            }
            // Source-locale properties must store normalized locale codes, as required by Babel hashing.
            $qb->setParameter($key.'_default', HashUtil::normalizeLocale($this->locale->getDefault()));
            $sourceExpression = 'COALESCE(NULLIF(LOWER(TRIM('.$alias.'.'.$accessor['name'].")), ''), :".$key.'_default)';
        }
        if (isset($source)) {
            if ($source === $display) {
                // Source-language searches need neither translation joins nor translation tables.
                return $likeSource;
            }
            $qb->setParameter($key.'_source', $source);
            $sourceExpression = ':'.$key.'_source';
        }
        $qb->setParameter($key.'_target', $display);
        $s = $key.'_s';
        $t = $key.'_t';
        $newest = $key.'_newest';
        // The hydrator reads nonblank provider rows in id order; the newest nonblank row wins.
        $translation = sprintf(
            "SELECT %4\$s.source FROM %2\$s %1\$s JOIN %3\$s %4\$s WITH %4\$s.code = %1\$s.strCode
             WHERE %4\$s.sourceLocale = %6\$s
               AND %1\$s.targetLocale = :%7\$s_target AND %1\$s.text IS NOT NULL AND %1\$s.text <> ''
               AND %1\$s.id = (SELECT MAX(%8\$s.id) FROM %2\$s %8\$s WHERE %8\$s.strCode = %1\$s.strCode
                   AND %8\$s.targetLocale = %1\$s.targetLocale AND %8\$s.text IS NOT NULL AND %8\$s.text <> '')",
            $t, StrTranslation::class, Str::class, $s, $value, $sourceExpression, $key, $newest,
        );
        $translated = sprintf('%s IN (%s AND LOWER(%s.text) LIKE LOWER(:%s))', $value, $translation, $t, $parameter);
        // Doctrine aliases are unique across subqueries, including the fallback check.
        $missing = preg_replace_callback('/\\b(?:'.preg_quote($s, '/').'|'.preg_quote($t, '/').'|'.preg_quote($newest, '/').')\\b/', static fn (array $match): string => $match[0].'_fallback', $translation);
        $fallback = sprintf('(%s AND %s NOT IN (%s))', $likeSource, $value, $missing);
        $predicate = '('.$translated.' OR '.$fallback.')';
        if (!isset($source)) {
            $predicate = sprintf('((%s = :%s_target AND %s) OR (%s <> :%s_target AND %s))', $sourceExpression, $key, $likeSource, $sourceExpression, $key, $predicate);
        }

        return $predicate;
    }
}
