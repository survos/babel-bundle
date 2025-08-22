<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\BabelBundle\Tests\Fixtures\Entity\SimpleOwner;

final class TranslatableTraitTest extends TestCase
{
    #[Test]
    public function it_stores_and_reads_translations(): void
    {
        $o = new SimpleOwner();
        $o->label = 'Museum of Things';
        $o->description = 'A place with stuff.';

        self::assertSame('Museum of Things', $o->getText('label', 'en'));
        self::assertSame('A place with stuff.', $o->getText('description', 'en'));

        $o->setText('description', 'es', 'Un lugar con cosas.');
        self::assertSame('Un lugar con cosas.', $o->getText('description', 'es'));

        $i18n = $o->_i18n();
        self::assertArrayHasKey('description', $i18n);
        self::assertArrayHasKey('es', $i18n['description']);
    }
}
