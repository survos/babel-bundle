<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\TestCase;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

trait DbTestCaseTrait
{
    protected function createSchema(EntityManagerInterface $em): void
    {
        $metas = $em->getMetadataFactory()->getAllMetadata();
        (new SchemaTool($em))->dropDatabase();
        if ($metas) {
            (new SchemaTool($em))->createSchema($metas);
        }
    }
}
