<?php

declare(strict_types=1);

namespace App\Tests\Admin\Service;

use App\Admin\Service\AdminKpiBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Locks the KPI payload shape + trend math: division-by-zero handling,
 * sign of deltaPercent, zero/zero short-circuit, and the null-previous
 * neutral case used for all-time cards.
 */
final class AdminKpiBuilderTest extends TestCase
{
    private AdminKpiBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new AdminKpiBuilder();
    }

    public function testReturnsNeutralWhenPreviousIsNull(): void
    {
        $kpi = $this->builder->build(
            title: 'All-time',
            period: 'Bilan',
            current: 42,
            previous: null,
        );

        self::assertSame(42, $kpi['value']);
        self::assertNull($kpi['previous']);
        self::assertNull($kpi['deltaPercent']);
        self::assertSame('neutral', $kpi['trend']);
    }

    public function testTrendIsUpWhenCurrentGrows(): void
    {
        $kpi = $this->builder->build(
            title: 't',
            period: 'p',
            current: 120,
            previous: 100,
        );

        self::assertSame(20, $kpi['deltaPercent']);
        self::assertSame('up', $kpi['trend']);
    }

    public function testTrendIsDownAndDeltaIsNegativeWhenCurrentShrinks(): void
    {
        $kpi = $this->builder->build(
            title: 't',
            period: 'p',
            current: 80,
            previous: 100,
        );

        self::assertSame(-20, $kpi['deltaPercent']);
        self::assertSame('down', $kpi['trend']);
    }

    public function testTrendIsNeutralWhenCurrentEqualsPrevious(): void
    {
        $kpi = $this->builder->build(
            title: 't',
            period: 'p',
            current: 100,
            previous: 100,
        );

        self::assertSame(0, $kpi['deltaPercent']);
        self::assertSame('neutral', $kpi['trend']);
    }

    public function testFromZeroToPositiveIsUpWithoutPercent(): void
    {
        $kpi = $this->builder->build(
            title: 't',
            period: 'p',
            current: 50,
            previous: 0,
        );

        // Percent is undefined when previous == 0; templates render
        // the arrow without a percentage in that case.
        self::assertNull($kpi['deltaPercent']);
        self::assertSame('up', $kpi['trend']);
        self::assertSame(0, $kpi['previous']);
    }

    public function testFromZeroToZeroStaysNeutralWithoutPercent(): void
    {
        $kpi = $this->builder->build(
            title: 't',
            period: 'p',
            current: 0,
            previous: 0,
        );

        self::assertNull($kpi['deltaPercent']);
        self::assertSame('neutral', $kpi['trend']);
    }

    public function testCustomBarLabelsArePropagated(): void
    {
        $kpi = $this->builder->build(
            title: 't',
            period: 'p',
            current: 10,
            previous: 5,
            currentLabel: 'Mai',
            previousLabel: 'Avril',
        );

        self::assertSame('Mai', $kpi['currentLabel']);
        self::assertSame('Avril', $kpi['previousLabel']);
    }

    public function testDeltaPercentIsRoundedToInt(): void
    {
        // 100 → 133 should round to +33% (not 33.0 or 32).
        $kpi = $this->builder->build(
            title: 't',
            period: 'p',
            current: 133,
            previous: 100,
        );

        self::assertSame(33, $kpi['deltaPercent']);
        self::assertIsInt($kpi['deltaPercent']);
    }
}
