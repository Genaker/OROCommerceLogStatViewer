<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\GuidelineProvider;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithGuidelines\FixtureBundleWithGuidelines;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithMoreGuidelines\FixtureBundleWithMoreGuidelines;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithoutGuidelines\FixtureBundleWithoutGuidelines;
use Oro\Component\Config\CumulativeResourceManager;
use PHPUnit\Framework\TestCase;

/**
 * GuidelineProvider enumerates every bundle registered in
 * CumulativeResourceManager (the same singleton DocFilesRagProvider and
 * Oro's own cross-bundle Resources/config/oro/*.yml loading rely on), so
 * these tests point that singleton at fixture "bundle" classes under
 * Fixtures/ instead of the real bundle list, then restore the original
 * bundles afterwards so later tests in the same process see real state.
 */
final class GuidelineProviderTest extends TestCase
{
    private array $originalBundles;

    protected function setUp(): void
    {
        $this->originalBundles = CumulativeResourceManager::getInstance()->getBundles();
    }

    protected function tearDown(): void
    {
        CumulativeResourceManager::getInstance()->setBundles($this->originalBundles);
    }

    private function useFixtureBundles(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithGuidelines' => FixtureBundleWithGuidelines::class,
            'FixtureBundleWithMoreGuidelines' => FixtureBundleWithMoreGuidelines::class,
            'FixtureBundleWithoutGuidelines' => FixtureBundleWithoutGuidelines::class,
        ]);
    }

    public function testGetGuidelinesMergesAcrossBundles(): void
    {
        $this->useFixtureBundles();

        $guidelines = (new GuidelineProvider())->getGuidelines();

        self::assertContains('First fixture guideline.', $guidelines);
        self::assertContains('Second fixture guideline.', $guidelines);
        self::assertContains('Third fixture guideline from a different bundle.', $guidelines);
    }

    public function testGetGuidelinesSkipsBlankEntries(): void
    {
        $this->useFixtureBundles();

        $guidelines = (new GuidelineProvider())->getGuidelines();

        foreach ($guidelines as $guideline) {
            self::assertNotSame('', trim($guideline), 'Blank guideline entries must be filtered out.');
        }
    }

    public function testGetGuidelinesSkipsBundlesWithoutTheFile(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithoutGuidelines' => FixtureBundleWithoutGuidelines::class,
        ]);

        self::assertSame([], (new GuidelineProvider())->getGuidelines());
    }

    public function testGetGuidelinesReturnsEmptyArrayWhenNoBundlesRegistered(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([]);

        self::assertSame([], (new GuidelineProvider())->getGuidelines());
    }
}
