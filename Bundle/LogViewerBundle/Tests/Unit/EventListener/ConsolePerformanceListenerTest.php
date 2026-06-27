<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\EventListener;

use Genaker\Bundle\LogViewerBundle\Entity\HttpPerformance;
use Genaker\Bundle\LogViewerBundle\EventListener\ConsolePerformanceListener;
use Genaker\Bundle\LogViewerBundle\Service\HttpPerformanceRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConsolePerformanceListenerTest extends TestCase
{
    private HttpPerformanceRecorder&MockObject $recorder;
    private ConsolePerformanceListener $listener;

    protected function setUp(): void
    {
        $this->recorder = $this->createMock(HttpPerformanceRecorder::class);
        $this->listener = new ConsolePerformanceListener($this->recorder);
    }

    // ── Normal flow ──────────────────────────────────────────────────────────

    /** @test */
    public function testRecordIsCalledWithCommandNameAndCliType(): void
    {
        $command = $this->makeCommand('oro:cron');

        $this->recorder->expects($this->once())
            ->method('record')
            ->with(
                'oro:cron',
                HttpPerformance::TYPE_CLI,
                $this->greaterThan(0.0),
                null
            );

        $this->listener->onConsoleCommand($this->makeCommandEvent($command));
        $this->listener->onConsoleTerminate($this->makeTerminateEvent($command));
    }

    /** @test */
    public function testResponseTimeIsPositive(): void
    {
        $command  = $this->makeCommand('app:import');
        $recorded = [];

        $this->recorder->method('record')
            ->willReturnCallback(function (string $path, string $type, float $ms) use (&$recorded) {
                $recorded = ['path' => $path, 'ms' => $ms];
            });

        $this->listener->onConsoleCommand($this->makeCommandEvent($command));
        usleep(1000);
        $this->listener->onConsoleTerminate($this->makeTerminateEvent($command));

        $this->assertGreaterThan(0.0, $recorded['ms']);
    }

    /** @test */
    public function testCommandNameIsUsedAsPath(): void
    {
        $command  = $this->makeCommand('egerdau:import-orders');
        $captured = null;

        $this->recorder->method('record')
            ->willReturnCallback(function (string $path) use (&$captured) { $captured = $path; });

        $this->listener->onConsoleCommand($this->makeCommandEvent($command));
        $this->listener->onConsoleTerminate($this->makeTerminateEvent($command));

        $this->assertSame('egerdau:import-orders', $captured);
    }

    // ── Guard: terminate without prior command event ──────────────────────────

    /** @test */
    public function testRecordIsNotCalledWhenTerminateFiresWithoutCommandEvent(): void
    {
        $this->recorder->expects($this->never())->method('record');

        $this->listener->onConsoleTerminate($this->makeTerminateEvent($this->makeCommand('app:foo')));
    }

    // ── Start time reset ──────────────────────────────────────────────────────

    /** @test */
    public function testStartTimeIsResetAfterTerminate(): void
    {
        $command = $this->makeCommand('app:reset-test');

        // First full cycle records once
        $this->recorder->expects($this->once())->method('record');

        $this->listener->onConsoleCommand($this->makeCommandEvent($command));
        $this->listener->onConsoleTerminate($this->makeTerminateEvent($command));

        // Second terminate without a new onConsoleCommand: no extra call
        $this->listener->onConsoleTerminate($this->makeTerminateEvent($command));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCommand(string $name): Command
    {
        $command = $this->createMock(Command::class);
        $command->method('getName')->willReturn($name);

        return $command;
    }

    private function makeCommandEvent(Command $command): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(
            $command,
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class)
        );
    }

    private function makeTerminateEvent(Command $command): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent(
            $command,
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class),
            0
        );
    }
}
