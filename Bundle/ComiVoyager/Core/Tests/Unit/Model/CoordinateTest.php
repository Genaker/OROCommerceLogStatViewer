<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Tests\Unit\Model;

use Genaker\Bundle\ComiVoyager\Core\Model\Coordinate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Genaker\Bundle\ComiVoyager\Core\Model\Coordinate
 */
final class CoordinateTest extends TestCase
{
    public function testToArrayReturnsLatAndLng(): void
    {
        $coordinate = new Coordinate(51.5074, -0.1278);

        self::assertSame(['lat' => 51.5074, 'lng' => -0.1278], $coordinate->toArray());
    }

    /**
     * @dataProvider validCoordinateProvider
     */
    public function testAcceptsBoundaryValues(float $lat, float $lng): void
    {
        $coordinate = new Coordinate($lat, $lng);

        self::assertSame($lat, $coordinate->lat);
        self::assertSame($lng, $coordinate->lng);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function validCoordinateProvider(): iterable
    {
        yield 'north pole' => [90.0, 0.0];
        yield 'south pole' => [-90.0, 0.0];
        yield 'date line east' => [0.0, 180.0];
        yield 'date line west' => [0.0, -180.0];
    }

    public function testThrowsForLatitudeAboveRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Coordinate(90.0001, 0.0);
    }

    public function testThrowsForLatitudeBelowRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Coordinate(-90.0001, 0.0);
    }

    public function testThrowsForLongitudeAboveRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Coordinate(0.0, 180.0001);
    }

    public function testThrowsForLongitudeBelowRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Coordinate(0.0, -180.0001);
    }
}
