<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Repository\Base;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * Marker abstract for read-only helpers. No write-side logic here.
 */
abstract class AbstractReadOnlyRepository extends ServiceEntityRepository
{
    // put shared finders here if you like; keep them ORM/DQL only (no writes)
}
