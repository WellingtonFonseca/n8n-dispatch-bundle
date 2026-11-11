<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Provider\Dialog;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\N8nDispatchBundle\Service\Provider\Dialog\DialogHsmGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Needs the DialogHSM plugin next to this one: the gateway names its classes. Without it the test is skipped, which is
 * the same situation the container is in (NullHsmProviderGateway), covered by the plugin boot test.
 */
class DialogHsmGatewayTest extends TestCase
{
    /** @var IntegrationsHelper&MockObject */
    private IntegrationsHelper $integrations;

    /** @var EntityRepository<WhatsAppNumber>&MockObject */
    private EntityRepository $repository;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        if (!class_exists(DialogHSMIntegration::class)) {
            $this->markTestSkipped('The DialogHSM plugin is not installed.');
        }

        $this->integrations  = $this->createMock(IntegrationsHelper::class);
        $this->repository    = $this->createMock(EntityRepository::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->with(WhatsAppNumber::class)->willReturn($this->repository);
    }

    private function gateway(): DialogHsmGateway
    {
        return new DialogHsmGateway($this->integrations, $this->entityManager, $this->logger);
    }

    private function integration(bool $published): object
    {
        return new class($published) {
            public function __construct(private bool $published)
            {
            }

            public function getIntegrationConfiguration(): object
            {
                return new class($this->published) {
                    public function __construct(private bool $published)
                    {
                    }

                    public function getIsPublished(): bool
                    {
                        return $this->published;
                    }
                };
            }
        };
    }

    private function number(int $id, string $name, bool $published = true): WhatsAppNumber
    {
        $number = (new WhatsAppNumber())->setName($name);
        $number->setIsPublished($published);
        (new \ReflectionProperty(WhatsAppNumber::class, 'id'))->setValue($number, $id);

        return $number;
    }

    public function testItIsAGateway(): void
    {
        $this->assertInstanceOf(HsmProviderGateway::class, $this->gateway());
    }

    public function testItIsAvailableWhenTheIntegrationIsPublished(): void
    {
        $this->integrations->expects($this->once())->method('getIntegration')->with('DialogHSM')->willReturn($this->integration(true));

        $this->assertTrue($this->gateway()->isAvailable());
    }

    public function testTheIntegrationIsAskedOnceNoMatterHowManyTimesTheFormAsks(): void
    {
        $this->integrations->expects($this->once())->method('getIntegration')->willReturn($this->integration(true));
        $gateway = $this->gateway();

        $this->assertTrue($gateway->isAvailable());
        $this->assertTrue($gateway->isAvailable());
        $this->assertTrue($gateway->isAvailable());
    }

    public function testItIsNotAvailableWhenTheIntegrationIsNotPublished(): void
    {
        $this->integrations->method('getIntegration')->willReturn($this->integration(false));

        $this->assertFalse($this->gateway()->isAvailable());
    }

    public function testItIsNotAvailableAndLogsWhenTheIntegrationIsNotFound(): void
    {
        $this->integrations->method('getIntegration')->willThrowException(new IntegrationNotFoundException('DialogHSM'));
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('DialogHSM'), $this->arrayHasKey('error'));

        $this->assertFalse($this->gateway()->isAvailable());
    }

    public function testTheNumbersAreThePublishedOnesByName(): void
    {
        $this->integrations->method('getIntegration')->willReturn($this->integration(true));
        $this->repository->expects($this->once())->method('findBy')
            ->with(['isPublished' => true], ['name' => 'ASC'])
            ->willReturn([$this->number(3, 'ESPM A'), $this->number(5, 'ESPM B')]);

        $this->assertSame([3 => 'ESPM A', 5 => 'ESPM B'], $this->gateway()->numbers());
    }

    public function testNothingIsReadFromTheOtherPluginWhenItIsNotAvailable(): void
    {
        $this->integrations->method('getIntegration')->willReturn($this->integration(false));
        $this->repository->expects($this->never())->method('findBy');
        $this->repository->expects($this->never())->method('find');

        $gateway = $this->gateway();

        $this->assertSame([], $gateway->numbers());
        $this->assertNull($gateway->numberName(3));
    }

    public function testANumberIsFoundByIdAndNamed(): void
    {
        $this->integrations->method('getIntegration')->willReturn($this->integration(true));
        $this->repository->method('find')->with(3)->willReturn($this->number(3, 'ESPM A'));

        $this->assertSame('ESPM A', $this->gateway()->numberName(3));
    }

    public function testAnUnknownOrUnpublishedNumberHasNoName(): void
    {
        $this->integrations->method('getIntegration')->willReturn($this->integration(true));
        $this->repository->method('find')->willReturnMap([
            [9, null, null, null],
            [4, null, null, $this->number(4, 'ESPM off', false)],
        ]);

        $this->assertNull($this->gateway()->numberName(9));
        $this->assertNull($this->gateway()->numberName(4));
    }
}
