<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KernelCacheDirTest extends TestCase
{
    private ?string $savedCacheDir = null;

    protected function setUp(): void
    {
        $saved = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->savedCacheDir = \is_string($saved) ? $saved : null;
        unset($_SERVER['APP_CACHE_DIR']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_CACHE_DIR']);
        if (null !== $this->savedCacheDir) {
            $_SERVER['APP_CACHE_DIR'] = $this->savedCacheDir;
        }
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function kernels(): iterable
    {
        yield 'prod' => ['prod', false, 'prod'];
        yield 'dev' => ['dev', true, 'dev'];
        yield 'test with debug' => ['test', true, 'test'];
        yield 'test without debug' => ['test', false, 'test_nodebug'];
    }

    #[DataProvider('kernels')]
    public function testDefaultCacheDir(string $environment, bool $debug, string $leaf): void
    {
        $kernel = new Kernel($environment, $debug);

        self::assertSame($kernel->getProjectDir().'/var/cache/'.$leaf, $kernel->getCacheDir());
    }

    #[DataProvider('kernels')]
    public function testHonoursAppCacheDir(string $environment, bool $debug, string $leaf): void
    {
        $_SERVER['APP_CACHE_DIR'] = '/srv/cache';
        $kernel = new Kernel($environment, $debug);

        self::assertSame('/srv/cache/'.$leaf, $kernel->getCacheDir());
    }
}
