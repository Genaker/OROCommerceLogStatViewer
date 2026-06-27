<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Tests\Unit\Service;

use Genaker\Bundle\LogViewerBundle\Service\LogFileValidator;
use PHPUnit\Framework\TestCase;

class LogFileValidatorTest extends TestCase
{
    private LogFileValidator $validator;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/log_validator_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->validator = new LogFileValidator($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*') ?: []);
            rmdir($this->tempDir);
        }
    }

    /** @test */
    public function testValidatePassesForValidLogFile(): void
    {
        file_put_contents($this->tempDir . '/app.log', 'some log content');

        $this->validator->validate('app.log');

        $this->assertTrue(true);
    }

    /** @test */
    public function testValidateThrowsForNonExistentFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid log file path');

        $this->validator->validate('nonexistent.log');
    }

    /** @test */
    public function testValidateThrowsForNonLogExtension(): void
    {
        file_put_contents($this->tempDir . '/config.php', '<?php');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only .log files are allowed.');

        $this->validator->validate('config.php');
    }

    /** @test */
    public function testValidateThrowsForPathTraversalWithDotDot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid log file path');

        $this->validator->validate('../../../etc/passwd');
    }

    /** @test */
    public function testValidateThrowsForAbsolutePathOutsideLogDir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid log file path');

        $this->validator->validate('/etc/passwd');
    }

    /** @test */
    public function testValidatePassesForLogFileInSubdirectory(): void
    {
        mkdir($this->tempDir . '/subfolder', 0777, true);
        file_put_contents($this->tempDir . '/subfolder/nested.log', 'content');

        $this->validator->validate('subfolder/nested.log');

        $this->assertTrue(true);

        unlink($this->tempDir . '/subfolder/nested.log');
        rmdir($this->tempDir . '/subfolder');
    }
}
