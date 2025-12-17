<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\BabelBundle\Tests\App\Entity\SimpleOwner;

final class TranslatableTraitTest extends TestCase
{
    #[Test]
    public function it_uses_backing_values_when_runtime_locale_is_not_initialized(): void
    {
        $o = new SimpleOwner();
        $o->label = 'Museum of Things';
        $o->description = 'A place with stuff.';

        // Without a BabelRuntime locale, property hooks must return source/backing values.
        self::assertSame('Museum of Things', $o->label);
        self::assertSame('A place with stuff.', $o->description);

        // Listener-facing accessor must follow <field>Backing convention
        self::assertSame('Museum of Things', $o->getBackingValue('label'));
        self::assertSame('A place with stuff.', $o->getBackingValue('description'));

        // Runtime cache helpers (used by hydrator)
        $o->setResolvedTranslation('label', 'Museo de Cosas');
        self::assertSame('Museo de Cosas', $o->getResolvedTranslation('label'));
    }
}
