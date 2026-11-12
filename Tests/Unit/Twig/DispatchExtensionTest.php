<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Twig;

use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use MauticPlugin\N8nDispatchBundle\Twig\DispatchExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DispatchExtensionTest extends TestCase
{
    public function testTheFunctionIsRegistered(): void
    {
        $names = array_map(static fn ($function): string => $function->getName(), (new DispatchExtension($this->createMock(DispatchListReader::class)))->getFunctions());

        $this->assertSame(['n8ndispatch_dispatch'], $names);
    }

    public function testTheTimelineReadsTheDispatchOfItsLogLikeTheDetailsModal(): void
    {
        $row    = ['id' => 5, 'campaignName' => 'Cf - X'];
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->once())->method('find')->with(5)->willReturn($row);

        $this->assertSame($row, (new DispatchExtension($reader))->dispatchOf(5));
    }

    public function testALogWithoutADispatchRowGivesNone(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->method('find')->willReturn(null);

        $this->assertNull((new DispatchExtension($reader))->dispatchOf(9));
    }
}
