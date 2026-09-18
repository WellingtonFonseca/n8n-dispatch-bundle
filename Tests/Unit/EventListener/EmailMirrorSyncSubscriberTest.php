<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Event\EmailEvent;
use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\N8nDispatchBundle\EventListener\EmailMirrorSyncSubscriber;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class EmailMirrorSyncSubscriberTest extends TestCase
{
    private IntegrationHelper $integrationHelper;

    private HttpClientInterface $httpClient;

    private LoggerInterface $logger;

    private UserHelper $userHelper;

    private EntityManagerInterface $entityManager;

    private UnitOfWork $unitOfWork;

    private EmailMirrorSyncSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->userHelper        = $this->createMock(UserHelper::class);
        $this->entityManager     = $this->createMock(EntityManagerInterface::class);
        // Mautic's entities use Doctrine's DEFERRED_EXPLICIT change tracking
        // policy — flush() alone never persists an in-place mutation on an
        // already-managed entity, it also needs scheduleForDirtyCheck()
        // first (see ensureUnsubscribeFooter()). Confirmed live: without
        // this call, flush() silently issues no SQL at all for the change.
        $this->unitOfWork = $this->createMock(UnitOfWork::class);
        $this->entityManager->method('getUnitOfWork')->willReturn($this->unitOfWork);

        $this->subscriber = new EmailMirrorSyncSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->userHelper,
            $this->entityManager,
        );
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

    private function buildEmail(): Email
    {
        $email = new Email();
        $email->setId(6);
        $email->setSubject('Não identificamos o seu pagamento.');
        $email->setCustomHtml('<html><body><h1>Olá {{aluno_nome}}</h1></body></html>');
        $email->setFromName('Patrícia da Pós');
        $email->setFromAddress('cm.posdigital@pucpr.br');

        return $email;
    }

    private function dispatch(Email $email): void
    {
        $event = new EmailEvent($email, false);
        $this->subscriber->onEmailPostSave($event);
    }

    public function testNoIntegrationConfiguredSkipsSyncSilently(): void
    {
        $this->integrationHelper->method('getIntegrationObject')->willReturn(null);
        $this->httpClient->expects($this->never())->method('request');

        $this->dispatch($this->buildEmail());
    }

    public function testUnpublishedIntegrationSkipsSyncSilently(): void
    {
        $this->mockIntegration(false, ['webhook_url' => 'https://example.test/hook']);
        $this->httpClient->expects($this->never())->method('request');

        $this->dispatch($this->buildEmail());
    }

    public function testMissingWebhookUrlSkipsSyncAndLogsWarning(): void
    {
        $this->mockIntegration(true, ['webhook_url' => '']);
        $this->httpClient->expects($this->never())->method('request');
        $this->logger->expects($this->once())->method('warning');

        $this->dispatch($this->buildEmail());
    }

    public function testSuccessfulSyncSendsTheExpectedPayloadAndHeaders(): void
    {
        $this->mockIntegration(true, [
            'webhook_url'   => 'https://n8n.example.test/webhook/mirror',
            'webhook_token' => 'super-secret-token',
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://n8n.example.test/webhook/mirror',
                $this->callback(function (array $options): bool {
                    $this->assertSame('email.save', $options['headers']['X-N8n-Dispatch-Action']);
                    $this->assertSame('super-secret-token', $options['headers']['X-N8n-Dispatch-Token']);

                    $payload = $options['json'];
                    $this->assertSame(6, $payload['mautic_template_id']);
                    $this->assertSame('Não identificamos o seu pagamento.', $payload['subject']);
                    $this->assertSame('Patrícia da Pós', $payload['from_name']);
                    $this->assertSame('cm.posdigital@pucpr.br', $payload['from_address']);
                    $this->assertSame(hash('sha256', $payload['html']), $payload['hash']);
                    $this->assertStringContainsString('Olá {{aluno_nome}}', $payload['html']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->logger->expects($this->never())->method('error');

        $this->dispatch($this->buildEmail());
    }

    public function testNoWebhookTokenOmitsAuthHeader(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/mirror']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://n8n.example.test/webhook/mirror',
                $this->callback(function (array $options): bool {
                    $this->assertArrayNotHasKey('X-N8n-Dispatch-Token', $options['headers']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->dispatch($this->buildEmail());
    }

    public function testNonSuccessStatusCodeIsLoggedAsError(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/mirror']);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $this->httpClient->method('request')->willReturn($response);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('HTTP 500'));

        $this->dispatch($this->buildEmail());
    }

    public function testTransportExceptionIsCaughtAndLoggedNotThrown(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/mirror']);

        $this->httpClient->method('request')->willThrowException(new \RuntimeException('Connection refused'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Connection refused'));

        // Must not throw — a sync failure must never surface as a save error.
        $this->dispatch($this->buildEmail());
    }

    public function testPreheaderIsInjectedIntoHtmlWhenSet(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/mirror']);

        $email = $this->buildEmail();
        $email->setCustomHtml('<html><body><p>content</p></body></html>');
        $email->setPreheaderText('Veja como regularizar.');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertStringContainsString('Veja como regularizar.', $options['json']['html']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->dispatch($email);
    }

    public function testPayloadIncludesModifiedByEmailWhenUserIsPresent(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/mirror']);

        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('editor@example.test');
        $this->userHelper->method('getUser')->with(true)->willReturn($user);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertSame('editor@example.test', $options['json']['modified_by_email']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->dispatch($this->buildEmail());
    }

    public function testPayloadIncludesNullModifiedByEmailWhenNoUserIsPresent(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/mirror']);

        $this->userHelper->method('getUser')->with(true)->willReturn(null);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('modified_by_email', $options['json']);
                    $this->assertNull($options['json']['modified_by_email']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->dispatch($this->buildEmail());
    }

    public function testUnsubscribeFooterIsInsertedOnFirstSaveAndNotDuplicatedOnASecondSave(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/mirror']);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->method('request')->willReturn($response);

        $email = $this->buildEmail();
        $email->setCustomHtml('<html><body><p>content</p></body></html>');

        $this->unitOfWork->expects($this->once())->method('scheduleForDirtyCheck')->with($email);
        $this->entityManager->expects($this->once())->method('flush');
        $this->dispatch($email);

        $htmlAfterFirstSave = (string) $email->getCustomHtml();
        $this->assertSame(1, substr_count($htmlAfterFirstSave, 'n8ndispatch:unsubscribe-footer:start'));
        $this->assertStringContainsString('{{n8ndispatch_unsubscribe_url}}', $htmlAfterFirstSave);

        // Re-save the same (now footer-containing) email through a second
        // subscriber instance with its own EntityManager mock, so the two
        // flush() expectations don't collide on one shared mock.
        $secondEntityManager = $this->createMock(EntityManagerInterface::class);
        $secondEntityManager->expects($this->never())->method('flush');
        $secondSubscriber = new EmailMirrorSyncSubscriber(
            $this->integrationHelper,
            $this->httpClient,
            $this->logger,
            $this->userHelper,
            $secondEntityManager,
        );
        $secondSubscriber->onEmailPostSave(new EmailEvent($email, false));

        $this->assertSame($htmlAfterFirstSave, $email->getCustomHtml());
    }

    public function testUnsubscribeFooterIsResyncedIfContentBecomesStale(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/webhook/mirror']);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->method('request')->willReturn($response);

        $email = $this->buildEmail();
        $email->setCustomHtml(
            '<html><body><p>content</p>'
            .'<!-- n8ndispatch:unsubscribe-footer:start -->stale<!-- n8ndispatch:unsubscribe-footer:end -->'
            .'</body></html>'
        );

        $this->unitOfWork->expects($this->once())->method('scheduleForDirtyCheck')->with($email);
        $this->entityManager->expects($this->once())->method('flush');
        $this->dispatch($email);

        $html = (string) $email->getCustomHtml();
        $this->assertSame(1, substr_count($html, 'n8ndispatch:unsubscribe-footer:start'));
        $this->assertStringNotContainsString('stale', $html);
        $this->assertStringContainsString('{{n8ndispatch_unsubscribe_url}}', $html);
    }
}
