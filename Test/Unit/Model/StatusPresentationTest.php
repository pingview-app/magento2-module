<?php
declare(strict_types=1);

namespace PingView\Monitoring\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PingView\Monitoring\Model\StatusPresentation;

final class StatusPresentationTest extends TestCase
{
    public function testCanonicalStatusHasAccessiblePresentation(): void
    {
        foreach (self::canonicalStatuses() as $status) {
            $result = (new StatusPresentation())->describe($status);

            self::assertNotSame('', $result['label'], $status);
            self::assertNotSame('', $result['tone'], $status);
            self::assertNotSame('', $result['icon'], $status);
        }
    }

    /**
     * Values the backend stopped writing but old rows still carry. Rendering
     * these as "unknown" is what the shared StatusView::legacyAliases() exists
     * to prevent, and this panel must inherit that, not re-decide it.
     */
    public function testLegacyStatusValuesStillRender(): void
    {
        $presentation = new StatusPresentation();

        self::assertSame($presentation->describe('operational'), $presentation->describe('up'));
        self::assertSame($presentation->describe('offline'), $presentation->describe('down'));
        self::assertSame($presentation->describe('partial'), $presentation->describe('partial_outage'));
    }

    public function testUnknownInputFallsBackToUnknown(): void
    {
        self::assertSame(
            (new StatusPresentation())->describe('unknown'),
            (new StatusPresentation())->describe('invented-status')
        );
    }

    /**
     * The consumed CT-STATUS / BR-STATUS-01 vocabulary. Asserted in a loop
     * rather than through a data provider: PHPUnit 11 ignores the annotation
     * form, which silently turned this into a test that could not run.
     *
     * @return string[]
     */
    public static function canonicalStatuses(): array
    {
        return ['operational', 'degraded', 'partial', 'offline', 'maintenance', 'unknown'];
    }
}
