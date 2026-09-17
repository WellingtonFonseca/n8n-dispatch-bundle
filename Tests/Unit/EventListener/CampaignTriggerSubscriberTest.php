<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\EventListener\CampaignTriggerSubscriber;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CampaignTriggerSubscriberTest extends TestCase
{
    private IntegrationHelper $integrationHelper;

    private HttpClientInterface $httpClient;

    private LoggerInterface $logger;

    private EmailModel $emailModel;

    private VariableResolver $variableResolver;

    private CampaignTriggerSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->emailModel        = $this->createMock(EmailModel::class);
        $this->variableResolver  = $this->createMock(VariableResolver::class);

        $this->subscriber = new CampaignTriggerSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->emailModel,
            $this->variableResolver,
        );

        $this->variableResolver->method('resolveAll')->willReturn(['foo' => 'bar']);
    }

    private function mockIntegration(bool $isPublished, array $keys): void
    {
        $settings = $this->createMock(IntegrationSettings::class);
        $settings->method('isPublished')->willReturn($isPublished);

        $integration = $this->createMock(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $integration->method('getKeys')->willReturn($keys);

        $this->integrationHelper->method('getIntegrationObject')
            ->with(N8nDispatchIntegration::NAME)
            ->willReturn($integration);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function buildPendingEvent(array $properties): PendingEvent
    {
        $campaign = new Campaign();

        $event = new Event();
        $event->setType('n8ndispatch.email.send');
        $event->setCampaign($campaign);
        $event->setProperties($properties);

        $contact = new Lead();
        $contact->setEmail('contact@example.test');

        $log = new LeadEventLog();
        $log->setLead($contact);

        return new PendingEvent(new ActionAccessor([]), $event, new ArrayCollection([$log]));
    }

    public function testPausedStatusSkipsDispatchAndFailsAllPending(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => 'paused']);

        $this->httpClient->expects($this->never())->method('request');
        $this->emailModel->expects($this->never())->method('getEntity');

        $this->subscriber->onEmailSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getFailures());
        $this->assertCount(0, $pendingEvent->getSuccessful());
    }

    /**
     * @dataProvider provideNonPausedStatuses
     */
    public function testNonPausedStatusDispatchesAndIncludesStatusInPayload(string $status): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1, 'status' => $status]);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://n8n.example.test/webhook/dispatch',
                $this->callback(function (array $options) use ($status): bool {
                    $this->assertSame($status, $options['json']['status']);
                    $this->assertSame(1, $options['json']['mautic_template_id']);
                    $this->assertSame('contact@example.test', $options['json']['contact_email']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);

        $this->assertCount(1, $pendingEvent->getSuccessful());
        $this->assertCount(0, $pendingEvent->getFailures());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function provideNonPausedStatuses(): array
    {
        return [
            'test'       => ['test'],
            'production' => ['production'],
        ];
    }

    public function testMissingStatusDefaultsToTestAndStillDispatches(): void
    {
        $pendingEvent = $this->buildPendingEvent(['email' => 1]);

        $this->emailModel->method('getEntity')->with(1)->willReturn(new Email());
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/dispatch']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertSame('test', $options['json']['status']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->subscriber->onEmailSend($pendingEvent);
    }
}
