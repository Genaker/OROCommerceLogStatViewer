<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Genaker\Bundle\LogViewerBundle\Service\BrowserConsoleLogger;
use PHPUnit\Framework\TestCase;

class BrowserConsoleLoggerTest extends TestCase
{
    private BrowserConsoleLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new BrowserConsoleLogger();
    }

    public function testInitiallyEnabledAndEmpty(): void
    {
        self::assertTrue($this->logger->isEnabled());
        self::assertFalse($this->logger->hasEntries());
        self::assertSame([], $this->logger->getEntries());
    }

    public function testLogAddsEntry(): void
    {
        $this->logger->log('test message', ['key' => 'value']);

        self::assertTrue($this->logger->hasEntries());
        $entries = $this->logger->getEntries();
        self::assertCount(1, $entries);
        self::assertSame('log', $entries[0]['method']);
        self::assertSame('test message', $entries[0]['label']);
        self::assertSame(['key' => 'value'], $entries[0]['data']);
    }

    public function testAllLogLevels(): void
    {
        $this->logger->log('log msg');
        $this->logger->info('info msg');
        $this->logger->warn('warn msg');
        $this->logger->error('error msg');
        $this->logger->debug('debug msg');

        $entries = $this->logger->getEntries();
        self::assertCount(5, $entries);
        self::assertSame('log', $entries[0]['method']);
        self::assertSame('info', $entries[1]['method']);
        self::assertSame('warn', $entries[2]['method']);
        self::assertSame('error', $entries[3]['method']);
        self::assertSame('debug', $entries[4]['method']);
    }

    public function testTableEntry(): void
    {
        $rows = [['id' => 1, 'name' => 'foo'], ['id' => 2, 'name' => 'bar']];
        $this->logger->table('Users', $rows);

        $entries = $this->logger->getEntries();
        self::assertCount(1, $entries);
        self::assertSame('table', $entries[0]['method']);
        self::assertSame($rows, $entries[0]['data']);
    }

    public function testGroupAndGroupEnd(): void
    {
        $this->logger->group('MyGroup');
        $this->logger->log('inside');
        $this->logger->groupEnd();

        $entries = $this->logger->getEntries();
        self::assertCount(3, $entries);
        self::assertSame('group', $entries[0]['method']);
        self::assertSame('MyGroup', $entries[0]['label']);
        self::assertSame('log', $entries[1]['method']);
        self::assertSame('groupEnd', $entries[2]['method']);
    }

    public function testCollapsedGroup(): void
    {
        $this->logger->group('Collapsed', true);

        $entries = $this->logger->getEntries();
        self::assertSame('groupCollapsed', $entries[0]['method']);
    }

    public function testDisabledLoggerDoesNotCollect(): void
    {
        $this->logger->setEnabled(false);
        $this->logger->log('should not appear');

        self::assertFalse($this->logger->hasEntries());
    }

    public function testClear(): void
    {
        $this->logger->log('message');
        self::assertTrue($this->logger->hasEntries());

        $this->logger->clear();
        self::assertFalse($this->logger->hasEntries());
    }

    public function testRenderScriptEmpty(): void
    {
        self::assertSame('', $this->logger->renderScript());
    }

    public function testRenderScriptDisabled(): void
    {
        $this->logger->setEnabled(false);
        self::assertSame('', $this->logger->renderScript());
    }

    public function testRenderScriptContainsConsoleCall(): void
    {
        $this->logger->log('Hello', 'world');

        $script = $this->logger->renderScript();
        self::assertStringContainsString('<script data-browser-console-logger', $script);
        self::assertStringContainsString('</script>', $script);
        self::assertStringContainsString('c.log(', $script);
        self::assertStringContainsString('[PHP] Hello', $script);
        self::assertStringContainsString('"world"', $script);
    }

    public function testRenderScriptTableOutputsBothLogAndTable(): void
    {
        $this->logger->table('Rows', [['a' => 1]]);

        $script = $this->logger->renderScript();
        self::assertStringContainsString('c.log(', $script);
        self::assertStringContainsString('c.table(', $script);
    }

    public function testRenderScriptGroupOutput(): void
    {
        $this->logger->group('G');
        $this->logger->groupEnd();

        $script = $this->logger->renderScript();
        self::assertStringContainsString('c.group(', $script);
        self::assertStringContainsString('c.groupEnd()', $script);
    }

    public function testNormalizesThrowable(): void
    {
        $exception = new \RuntimeException('test error', 42);
        $this->logger->error('Exception caught', $exception);

        $entries = $this->logger->getEntries();
        $data = $entries[0]['data'];
        self::assertSame(\RuntimeException::class, $data['class']);
        self::assertSame('test error', $data['message']);
        self::assertSame(42, $data['code']);
    }

    public function testNormalizesObject(): void
    {
        $obj = new \stdClass();
        $this->logger->log('Object', $obj);

        $entries = $this->logger->getEntries();
        self::assertSame('[object stdClass]', $entries[0]['data']);
    }

    public function testNormalizesStringableObject(): void
    {
        $obj = new class () {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };
        $this->logger->log('Stringable', $obj);

        $entries = $this->logger->getEntries();
        self::assertSame('stringable-value', $entries[0]['data']);
    }

    public function testLogWithNullData(): void
    {
        $this->logger->log('No data');

        $script = $this->logger->renderScript();
        self::assertStringContainsString('c.log("[PHP] No data")', $script);
        self::assertStringNotContainsString('null', $script);
    }

    public function testMaxEntriesLimit(): void
    {
        $this->logger->setMaxEntries(3);

        $this->logger->log('one');
        $this->logger->log('two');
        $this->logger->log('three');
        $this->logger->log('four');
        $this->logger->log('five');

        self::assertCount(3, $this->logger->getEntries());
        self::assertTrue($this->logger->isTruncated());
    }

    public function testMaxPayloadBytesLimit(): void
    {
        $this->logger->setMaxPayloadBytes(1024);

        $bigData = str_repeat('x', 1100);
        $this->logger->log('first', $bigData);
        $this->logger->log('second', 'should be dropped');

        self::assertCount(1, $this->logger->getEntries());
        self::assertTrue($this->logger->isTruncated());
    }

    public function testTruncationWarningInScript(): void
    {
        $this->logger->setMaxEntries(1);
        $this->logger->log('only');
        $this->logger->log('dropped');

        $script = $this->logger->renderScript();
        self::assertStringContainsString('output truncated', $script);
        self::assertStringContainsString('c.warn(', $script);
    }

    public function testNoTruncationWarningWhenWithinLimits(): void
    {
        $this->logger->setMaxEntries(10);
        $this->logger->log('fits');

        $script = $this->logger->renderScript();
        self::assertStringNotContainsString('truncated', $script);
    }

    public function testClearResetsTruncation(): void
    {
        $this->logger->setMaxEntries(1);
        $this->logger->log('a');
        $this->logger->log('b');
        self::assertTrue($this->logger->isTruncated());

        $this->logger->clear();
        self::assertFalse($this->logger->isTruncated());
    }

    public function testGroupRespectsMaxEntries(): void
    {
        $this->logger->setMaxEntries(1);
        $this->logger->log('fills it');
        $this->logger->group('should be dropped');

        self::assertCount(1, $this->logger->getEntries());
        self::assertSame('log', $this->logger->getEntries()[0]['method']);
    }

    public function testDefaultLimits(): void
    {
        self::assertSame(200, $this->logger->getMaxEntries());
        self::assertSame(1048576, $this->logger->getMaxPayloadBytes());
    }

    public function testSetMaxEntriesFloor(): void
    {
        $this->logger->setMaxEntries(0);
        self::assertSame(1, $this->logger->getMaxEntries());
    }

    public function testSetMaxPayloadBytesFloor(): void
    {
        $this->logger->setMaxPayloadBytes(100);
        self::assertSame(1024, $this->logger->getMaxPayloadBytes());
    }

    public function testRenderScriptWithNonce(): void
    {
        $this->logger->log('test');

        $script = $this->logger->renderScript('abc123def456');
        self::assertStringContainsString('nonce="abc123def456"', $script);
        self::assertStringContainsString('<script data-browser-console-logger nonce="abc123def456">', $script);
    }

    public function testRenderScriptWithoutNonce(): void
    {
        $this->logger->log('test');

        $script = $this->logger->renderScript();
        self::assertStringNotContainsString('nonce', $script);
        self::assertStringContainsString('<script data-browser-console-logger>', $script);
    }

    public function testRenderScriptNonceEscapesSpecialChars(): void
    {
        $this->logger->log('test');

        $script = $this->logger->renderScript('abc"<>&\'xyz');
        self::assertStringNotContainsString('abc"', $script);
        self::assertStringContainsString('nonce=', $script);
    }
}
