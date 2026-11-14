<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Provider;

use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\NullHsmProviderGateway;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:dispatch-hsm')]
#[Group('area:dispatch-history')]
#[Group('risk:CRITICO')]
class NullHsmProviderGatewayTest extends TestCase
{
    public function testItIsAGateway(): void
    {
        $this->assertInstanceOf(HsmProviderGateway::class, new NullHsmProviderGateway());
    }

    public function testItIsNeverAvailable(): void
    {
        $this->assertFalse((new NullHsmProviderGateway())->isAvailable());
    }

    public function testItHasNoNumbers(): void
    {
        $gateway = new NullHsmProviderGateway();

        $this->assertSame([], $gateway->numbers());
        $this->assertNull($gateway->numberName(1));
    }
}
