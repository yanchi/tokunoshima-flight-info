<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Lock;

use App\Application\Port\TimetableLockUnavailable;
use App\Infrastructure\Lock\SymfonyTimetableLock;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;

final class SymfonyTimetableLockTest extends TestCase
{
    private string $directory;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tkn-lock-test-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    public function testReturnsWhatTheCallbackReturns(): void
    {
        $result = new \stdClass();

        self::assertSame($result, $this->lock()->runExclusively(static fn (): \stdClass => $result));
    }

    public function testFailsWithoutWaitingWhileAnotherHolderHasIt(): void
    {
        $other = new LockFactory(new FlockStore($this->directory))->createLock('tkn-timetable');
        self::assertTrue($other->acquire(false));

        try {
            $this->expectException(TimetableLockUnavailable::class);
            $this->lock()->runExclusively(static fn (): null => null);
        } finally {
            $other->release();
        }
    }

    public function testIsReleasedWhenTheCallbackThrows(): void
    {
        try {
            $this->lock()->runExclusively(self::throwing(...));
            self::fail('The callback exception should have been rethrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('TEST ONLY', $e->getMessage());
        }

        $other = new LockFactory(new FlockStore($this->directory))->createLock('tkn-timetable');
        self::assertTrue($other->acquire(false));
        $other->release();
    }

    public function testFailedReleaseIsLoggedAndKeepsTheResult(): void
    {
        $lock = new SymfonyTimetableLock(new LockFactory(self::storeFailingOnRelease()), $this->logger);

        $result = new \stdClass();

        self::assertSame($result, $lock->runExclusively(static fn (): \stdClass => $result));
        self::assertTrue($this->logger->has('error', 'could not be released'));
    }

    public function testFailedReleaseDoesNotHideTheCallbackException(): void
    {
        $lock = new SymfonyTimetableLock(new LockFactory(self::storeFailingOnRelease()), $this->logger);

        $this->expectExceptionMessage('TEST ONLY: from the callback');
        $lock->runExclusively(static function (): never {
            throw new \RuntimeException('TEST ONLY: from the callback');
        });
    }

    /**
     * Declared to return, so the call site is not seen as dead code.
     */
    private static function throwing(): string
    {
        throw new \RuntimeException('TEST ONLY');
    }

    private function lock(): SymfonyTimetableLock
    {
        return new SymfonyTimetableLock(new LockFactory(new FlockStore($this->directory)), $this->logger);
    }

    private static function storeFailingOnRelease(): PersistingStoreInterface
    {
        return new class implements PersistingStoreInterface {
            private bool $held = false;

            public function save(Key $key): void
            {
                $this->held = true;
            }

            public function delete(Key $key): void
            {
                throw new LockReleasingException('TEST ONLY: release failed');
            }

            public function exists(Key $key): bool
            {
                return $this->held;
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
            }
        };
    }
}
