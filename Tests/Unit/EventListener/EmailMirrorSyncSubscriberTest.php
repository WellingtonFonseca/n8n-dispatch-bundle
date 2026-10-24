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
use Mautic\EmailBundle\EventListener\EmailSubscriber as CoreEmailSubscriber;
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

    private const FOOTER = "<!-- n8ndispatch:unsubscribe-footer:start -->\n"
        .'<p style="font-size:11px;color:#888888;text-align:center;margin:16px 0 0;"><a href="{{n8ndispatch_unsubscribe_url}}" style="color:#888888;">Unsubscribe</a></p>'."\n"
        .'<!-- n8ndispatch:unsubscribe-footer:end -->';

    /**
     * Saves the Email and returns what was sent to the webhook: ['url' => ..., 'options' => ...].
     *
     * @param array<string, string> $keys
     *
     * @return array{url: string, options: array<string, mixed>}
     */
    private function sent(Email $email, array $keys = ['webhook_url' => 'https://n8n.example.test/hook']): array
    {
        $this->mockIntegration(true, $keys);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $sent = [];
        $this->httpClient->method('request')->willReturnCallback(function (string $method, string $url, array $options) use (&$sent, $response) {
            $sent = ['url' => $url, 'options' => $options];

            return $response;
        });

        $this->dispatch($email);

        return $sent;
    }

    public function testTheUrlAndTheTokenAreTrimmedAndTheHeadersAreExactlyThese(): void
    {
        $sent = $this->sent($this->buildEmail(), ['webhook_url' => "  https://n8n.example.test/hook \n", 'webhook_token' => '  tok  ']);

        $this->assertSame('https://n8n.example.test/hook', $sent['url']);
        $this->assertSame(['Content-Type' => 'application/json', 'X-N8n-Dispatch-Action' => 'email.save', 'X-N8n-Dispatch-Token' => 'tok'], $sent['options']['headers']);
    }

    public function testATokenOfOnlySpacesSendsNoTokenHeader(): void
    {
        $sent = $this->sent($this->buildEmail(), ['webhook_url' => 'https://n8n.example.test/hook', 'webhook_token' => '   ']);

        $this->assertSame(['Content-Type' => 'application/json', 'X-N8n-Dispatch-Action' => 'email.save'], $sent['options']['headers']);
    }

    public function testHttp300IsLoggedWithTheEmailIdAndHttp299IsNot(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $codes = [299, 300];
        $this->httpClient->method('request')->willReturnCallback(function () use (&$codes) {
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(array_shift($codes));

            return $response;
        });
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: webhook returned HTTP 300 for email 6.');

        $this->dispatch($this->buildEmail());
        $this->dispatch($this->buildEmail());
    }

    public function testATransportFailureIsLoggedWithTheEmailIdAndTheReason(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: failed to sync email 6 to the configured webhook: boom');

        $this->dispatch($this->buildEmail());
    }

    public function testTheFooterGoesJustBeforeTheClosingBodyTagWhateverItsCase(): void
    {
        $email = $this->buildEmail();
        $email->setCustomHtml('<HTML><BODY><p>x</p></BODY></HTML>');

        $this->sent($email);

        $this->assertSame('<HTML><BODY><p>x</p>'.self::FOOTER."\n".'</BODY></HTML>', $email->getCustomHtml());
    }

    public function testWithoutABodyTagTheFooterIsAppendedAfterTheTrimmedHtml(): void
    {
        $email = $this->buildEmail();
        $email->setCustomHtml("<p>x</p>  \n");

        $this->sent($email);

        $this->assertSame("<p>x</p>\n".self::FOOTER, $email->getCustomHtml());
    }

    public function testAnEmailWithoutHtmlGetsJustTheFooter(): void
    {
        $email = $this->buildEmail();
        $email->setCustomHtml(null);

        $this->sent($email);

        $this->assertSame("\n".self::FOOTER, $email->getCustomHtml());
    }

    public function testThePreheaderGoesRightAfterTheOpeningBodyTagWhateverItsCase(): void
    {
        $email = $this->buildEmail();
        $email->setCustomHtml('<HTML><BODY class="a"><p>x</p></HTML>');
        $email->setPreheaderText('Resumo');

        $sent = $this->sent($email);

        $this->assertStringContainsString('<BODY class="a">'."\n".CoreEmailSubscriber::PREHEADER_HTML_ELEMENT_BEFORE.'Resumo'.CoreEmailSubscriber::PREHEADER_HTML_ELEMENT_AFTER.'<p>x</p>', $sent['options']['json']['html']);
    }

    public function testAnExistingPreheaderIsReplacedNotDuplicated(): void
    {
        $email = $this->buildEmail();
        $email->setCustomHtml('<body>'.CoreEmailSubscriber::PREHEADER_HTML_ELEMENT_BEFORE.'old'.CoreEmailSubscriber::PREHEADER_HTML_ELEMENT_AFTER.'<p>x</p></body>');
        $email->setPreheaderText('new');

        $html = $this->sent($email)['options']['json']['html'];

        $this->assertSame(1, substr_count($html, 'class="preheader"'));
        $this->assertStringContainsString('>new</div>', $html);
        $this->assertStringNotContainsString('old', $html);
    }

    public function testWithoutPreheaderTextTheHtmlIsSentAsItIs(): void
    {
        $email = $this->buildEmail();
        $email->setPreheaderText(null);

        $html = $this->sent($email)['options']['json']['html'];

        $this->assertSame($email->getCustomHtml(), $html);
        $this->assertStringNotContainsString('class="preheader"', $html);
    }

    public function testPreheaderTextWithoutABodyTagLeavesTheHtmlAlone(): void
    {
        $email = $this->buildEmail();
        $email->setCustomHtml('<p>no body</p>');
        $email->setPreheaderText('Resumo');

        $html = $this->sent($email)['options']['json']['html'];

        $this->assertStringNotContainsString('class="preheader"', $html);
    }
}
