<?php
declare(strict_types=1);

namespace Survos\BabelBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as SymfonyKernelTestCase;

/**
 * KernelTestCase shim for PHPUnit 11/12:
 * makes sure Symfony's ErrorHandler exception handler
 * is removed after each test.
 */
abstract class KernelTestCase extends SymfonyKernelTestCase
{
    /**
     * Ensures we clean up the error handler while shutting
     * the kernel down, to keep PHPUnit 11/12 happy.
     */
    protected static function ensureKernelShutdown(): void
    {
        $wasBooted = static::$booted;

        parent::ensureKernelShutdown();

        if ($wasBooted) {
            // Undo FrameworkBundle's ErrorHandler::register()
            restore_exception_handler();
        }
    }
}
