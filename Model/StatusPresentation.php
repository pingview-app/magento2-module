<?php
declare(strict_types=1);

namespace PingView\Monitoring\Model;

final class StatusPresentation
{
    /**
     * Label and icon for a status. The vocabulary and the tone come from
     * {@see StatusView}, which the PrestaShop and WordPress panels use too, so
     * a status can never read as operational in one panel and unknown in
     * another (CT-PARITY). Only the label text and the glyph live here,
     * because those are this panel's own presentation.
     *
     * Renders BR-STATUS-01 / CT-STATUS; unknown input fails closed to unknown.
     */
    public function describe(string $status): array
    {
        $labels = [
            'operational' => ['label' => 'Store is operational', 'icon' => '✓'],
            'degraded' => ['label' => 'Performance degraded', 'icon' => '!'],
            'partial' => ['label' => 'Partial outage', 'icon' => '!'],
            'offline' => ['label' => 'Store is offline', 'icon' => '×'],
            'maintenance' => ['label' => 'Maintenance', 'icon' => 'i'],
            'unknown' => ['label' => 'Status unknown', 'icon' => '?'],
        ];

        $normalized = StatusView::normalizeStatus($status);

        return $labels[$normalized] + ['tone' => StatusView::tone($normalized)];
    }
}
