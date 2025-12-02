<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Survos\BabelBundle\Command\BabelScanCommand;
use Survos\BabelBundle\Command\CarriersListCommand;
use Survos\BabelBundle\Tests\App\Entity\TestOwner;
use Survos\BabelBundle\Tests\KernelTestCase;
use Survos\BabelBundle\Tests\TestCase\DbTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class BabelCommandsTest extends KernelTestCase
{
    use DbTestCaseTrait;

    private Application $app;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => true, 'kernel_class' => TestKernel::class]);
        $this->app = new Application(self::$kernel);

        // Build schema
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $this->createSchema($em);
    }


    #[Test]
    public function carriers_command_lists_property_mode_entities(): void
    {
        $cmd = $this->app->find('babel:carriers');
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Property-mode carriers', $display);
        $this->assertStringContainsString(TestOwner::class, $display);
    }

    #[Test]
    public function scan_command_outputs_fields_and_caches_map(): void
    {
        $cmd = $this->app->find('babel:scan');
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString(TestOwner::class, $display);
        $this->assertStringContainsString('- label', $display);
        $this->assertStringContainsString('- description', $display);
        $this->assertStringContainsString('Cached entries:', $display);
    }

    #[Test]
    public function translate_command_writes_missing_translations_via_fake_translator(): void
    {
        // Persist an owner with English text
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $o = new TestOwner();
        $o->label = 'Museum of Things';
        $o->description = 'A place with stuff.';
        $em->persist($o);
        $em->flush();

        // Run translate to es
        $cmd = $this->app->find('babel:translate');
        $tester = new CommandTester($cmd);
        $tester->execute([
            'locale' => 'es',
            '--class' => TestOwner::class,
            '--only-missing' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        // Reload and assert i18n blob updated by fake translator
        $em->clear();
        $reloaded = $em->getRepository(TestOwner::class)->find($o->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('[es] A place with stuff.', $reloaded->getText('description', 'es'));
    }
}
