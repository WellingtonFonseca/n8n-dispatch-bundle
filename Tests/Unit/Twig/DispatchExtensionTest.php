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

    public function testQueuedLogsAreReadInOneQueryOnlyWhenACardAsks(): void
    {
        $row    = ['id' => 5];
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->once())->method('findMany')->with([5, 6])->willReturn([5 => $row]);
        $reader->expects($this->never())->method('find');

        $extension = new DispatchExtension($reader);
        $extension->queue([5, 6]);

        $this->assertSame($row, $extension->dispatchOf(5));
        $this->assertNull($extension->dispatchOf(6), 'a log that is not a dispatch is known as none, not read again');
    }

    public function testQueuedLogsAreNotReadWhenNoCardRenders(): void
    {
        // the CSV export generates the timeline and draws no card
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->never())->method('findMany');

        (new DispatchExtension($reader))->queue([5, 6]);
    }

    public function testALogOutsideTheQueueReadsItsOwn(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->never())->method('findMany');
        $reader->expects($this->once())->method('find')->with(9)->willReturn(['id' => 9]);

        $extension = new DispatchExtension($reader);
        $extension->queue([5]);

        $this->assertSame(['id' => 9], $extension->dispatchOf(9));
        $this->assertSame(['id' => 9], $extension->dispatchOf(9), 'kept: a second render does not read it again');
    }

    public function testTheTimelinesOfSeveralContactsBuiltBeforeRenderingAreAllReadInOneQuery(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->once())->method('findMany')->with([5, 6])->willReturn([5 => ['id' => 5], 6 => ['id' => 6]]);
        $reader->expects($this->never())->method('find');

        $extension = new DispatchExtension($reader);
        $extension->queue([5]);
        $extension->queue([6]);

        $this->assertSame(['id' => 5], $extension->dispatchOf(5));
        $this->assertSame(['id' => 6], $extension->dispatchOf(6));
    }

    public function testALaterQueueKeepsWhatWasReadAndDoesNotReadItAgain(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->exactly(2))->method('findMany')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, ['id' => $ids[0]]));
        $reader->expects($this->never())->method('find');

        $extension = new DispatchExtension($reader);
        $extension->queue([5]);
        $extension->dispatchOf(5);
        $extension->queue([5, 6]);

        $this->assertSame(['id' => 6], $extension->dispatchOf(6), 'only 6 is read the second time');
        $this->assertSame(['id' => 5], $extension->dispatchOf(5));
    }

    public function testResetForgetsWhatWasRead(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->method('findMany')->willReturn([5 => ['id' => 5, 'old' => true]]);
        $reader->expects($this->once())->method('find')->with(5)->willReturn(['id' => 5]);

        $extension = new DispatchExtension($reader);
        $extension->queue([5]);
        $extension->dispatchOf(5);
        $extension->reset();

        $this->assertSame(['id' => 5], $extension->dispatchOf(5), 'read again, not the row of the earlier request');
    }
}
