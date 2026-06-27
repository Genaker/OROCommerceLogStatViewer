<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Tests\Unit\Command;

use Genaker\Bundle\ComiVoyager\Command\OptimizeRouteCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Command\OptimizeRouteCommand
 */
final class OptimizeRouteCommandTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $fixturePath = tempnam(sys_get_temp_dir(), 'comivoyager_');
        self::assertIsString($fixturePath);
        $this->fixturePath = $fixturePath;

        file_put_contents($this->fixturePath, json_encode([
            ['label' => 'London', 'lat' => 51.5074, 'lng' => -0.1278],
            ['label' => 'Paris', 'lat' => 48.8566, 'lng' => 2.3522],
            ['label' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050],
            ['label' => 'Madrid', 'lat' => 40.4168, 'lng' => -3.7038],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        unlink($this->fixturePath);
    }

    private function createTester(): CommandTester
    {
        return new CommandTester(new OptimizeRouteCommand());
    }

    public function testExecuteWithHaversineReturnsRequestedRouteCount(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'input' => $this->fixturePath,
            '--method' => 'haversine',
            '--routes' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['routes']);
        self::assertSame(2, $data['requestedCount']);
        self::assertSame(0, $data['shortestIndex']);
        self::assertTrue($data['routes'][0]['isShortest']);
    }

    public function testExecuteWithVincentyMethod(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'input' => $this->fixturePath,
            '--method' => 'vincenty',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(3, $data['routes']);
    }

    public function testExecuteWithReturnToStartAddsClosingStop(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'input' => $this->fixturePath,
            '--routes' => '1',
            '--return-to-start' => true,
            '--depot' => '0',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $stops = $data['routes'][0]['stops'];

        self::assertCount(5, $stops);
        self::assertTrue(end($stops)['isEnd']);
        self::assertSame('London', end($stops)['addressLabel']);
    }

    public function testExecuteWithDepotPinsFirstStop(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'input' => $this->fixturePath,
            '--depot' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($data['routes'] as $route) {
            self::assertSame('Berlin', $route['stops'][0]['addressLabel']);
        }
    }

    public function testExecuteWithUnknownMethodFails(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute([
            'input' => $this->fixturePath,
            '--method' => 'unknown',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown method', $tester->getDisplay());
    }

    public function testExecuteWithInsufficientAddressesFails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'comivoyager_single_');
        self::assertIsString($path);
        file_put_contents($path, json_encode([
            ['label' => 'Only', 'lat' => 0.0, 'lng' => 0.0],
        ], \JSON_THROW_ON_ERROR));

        try {
            $tester = $this->createTester();

            $exitCode = $tester->execute(['input' => $path]);

            self::assertSame(Command::FAILURE, $exitCode);
        } finally {
            unlink($path);
        }
    }

    public function testExecuteWithMissingFileFails(): void
    {
        $tester = $this->createTester();

        $exitCode = $tester->execute(['input' => '/nonexistent/path.json']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Could not read input', $tester->getDisplay());
    }

    public function testExecuteWithInvalidJsonFails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'comivoyager_invalid_');
        self::assertIsString($path);
        file_put_contents($path, 'not json');

        try {
            $tester = $this->createTester();

            $exitCode = $tester->execute(['input' => $path]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('Invalid input', $tester->getDisplay());
        } finally {
            unlink($path);
        }
    }
}
