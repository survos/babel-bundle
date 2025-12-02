<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use Survos\BabelBundle\Service\Scanner\TranslatableScanner;
use Survos\BabelBundle\Tests\App\Entity\TestOwner;
//use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Survos\BabelBundle\Tests\App\Kernel;
use Survos\BabelBundle\Tests\KernelTestCase;

final class TranslatableScannerTest extends KernelTestCase
{

//    protected function tearDown(): void
//    {
//        self::ensureKernelShutdown();
//        parent::tearDown();
//    }

    #[Test]
    public function it_discovers_translatable_fields_from_attributes(): void
    {
        self::bootKernel();

        /** @var TranslatableScanner $scanner */
        $scanner = self::getContainer()->get(TranslatableScanner::class);
        $map = $scanner->buildMap();

        self::assertArrayHasKey(TestOwner::class, $map);
        self::assertSame(['label', 'description'], $map[TestOwner::class]);
    }

//    #[Test]
//    public function it_discovers_translatable_fields_from_attributes(): void
//    {
//        self::bootKernel(['environment' => 'test', 'debug' => true, 'kernel_class' => TestKernel::class]);
//
//        /** @var TranslatableScanner $scanner */
//        $scanner = self::getContainer()->get(TranslatableScanner::class);
//        $map = $scanner->buildMap();
//
//        self::assertArrayHasKey(TestOwner::class, $map);
//        self::assertSame(['label','description'], $map[TestOwner::class]);
//    }
}
