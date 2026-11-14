<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Provider\Dialog;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\N8nDispatchBundle\Service\Provider\Dialog\DialogHsmGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmSendRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Needs the DialogHSM plugin next to this one: the gateway names its classes. Without it the test is skipped, which is
 * the same situation the container is in (NullHsmProviderGateway), covered by the plugin boot test.
 */
#[Group('area:dispatch-hsm')]
#[Group('area:dispatch-history')]
#[Group('risk:CRITICO')]
class DialogHsmGatewayTest extends TestCase
{
    /** @var IntegrationsHelper&MockObject */
    private IntegrationsHelper $integrations;

    /** @var EntityRepository<WhatsAppNumber>&MockObject */
    private EntityRepository $repository;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private EntityManagerInterface $entityManager;

    /** @var SendWhatsAppMessageHandler&MockObject */
    private SendWhatsAppMessageHandler $handler;

    protected function setUp(): void
    {
        if (!class_exists(DialogHSMIntegration::class)) {
            $this->markTestSkipped('The DialogHSM plugin is not installed.');
        }

        $this->integrations  = $this->createMock(IntegrationsHelper::class);
        $this->repository    = $this->createMock(EntityRepository::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->handler       = $this->createMock(SendWhatsAppMessageHandler::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->with(WhatsAppNumber::class)->willReturn($this->repository);
    }

    private function gateway(): DialogHsmGateway
    {
        return new DialogHsmGateway($this->integrations, $this->entityManager, $this->handler, $this->logger);
    }

    /**
     * @param array<string, string> $apiKeys the keys saved on the integration (base_url is the one the gateway reads)
     */
    private function integration(bool $published, array $apiKeys = []): object
    {
        return new class($published, $apiKeys) {
            /**
             * @param array<string, string> $apiKeys
             */
            public function __construct(private bool $published, private array $apiKeys)
            {
            }

            public function getIntegrationConfiguration(): object
            {
                return new class($this->published, $this->apiKeys) {
                    /**
                     * @param array<string, string> $apiKeys
                     */
                    public function __construct(private bool $published, private array $apiKeys)
                    {
                    }

                    public function getIsPublished(): bool
                    {
                        return $this->published;
                    }

                    /**
                     * @return array<string, string>
                     */
                    public function getApiKeys(): array
                    {
                        return $this->apiKeys;
                    }
                };
            }
        };
    }

    private function number(int $id, string $name, bool $published = true, ?string $apiKey = 'key-1', ?string $baseUrl = null): WhatsAppNumber
    {
        $number = (new WhatsAppNumber())->setName($name)->setApiKey($apiKey)->setBaseUrl($baseUrl);
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

    // ---- send() ----

    /**
     * @param list<array{card_index: int, header_image_link: string}> $cards
     * @param array<int|string, string>                                $variables
     */
    private function request(string $type = 'text', array $variables = ['2' => 'Curso X', '1' => 'Ana'], array $cards = []): HsmSendRequest
    {
        return new HsmSendRequest(7, '11999990001', 'pt_BR', 'welcome_v1', $type, $variables, $cards, 3, 11, 22);
    }

    /**
     * The gateway is available and number 3 exists: what a send needs before it reaches the handler.
     *
     * @param array<string, string> $apiKeys
     */
    private function readyToSend(?WhatsAppNumber $number = null, array $apiKeys = []): void
    {
        $this->integrations->method('getIntegration')->willReturn($this->integration(true, $apiKeys));
        $this->repository->method('find')->with(3)->willReturn($number ?? $this->number(3, 'ESPM A'));
    }

    /**
     * @param array<string, mixed> $answer what the handler returns
     */
    private function handlerAnswers(array $answer): \ArrayObject
    {
        $captured = new \ArrayObject();
        $this->handler->method('__invoke')->willReturnCallback(function (SendWhatsAppMessage $message, bool $housekeeping, bool $rateLimit, bool $retry) use ($captured, $answer): array {
            $captured['message'] = $message;
            $captured['flags']   = [$housekeeping, $rateLimit, $retry];

            return $answer;
        });

        return $captured;
    }

    public function testASendNeedsTheOtherPluginToBeAvailable(): void
    {
        $this->integrations->method('getIntegration')->willReturn($this->integration(false));
        $this->handler->expects($this->never())->method('__invoke');

        $result = $this->gateway()->send($this->request());

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('not available', (string) $result->error);
    }

    public function testASendToANumberThatIsGoneOrUnpublishedIsRefused(): void
    {
        $this->integrations->method('getIntegration')->willReturn($this->integration(true));
        $this->repository->method('find')->willReturnMap([[3, null, null, null]]);
        $this->handler->expects($this->never())->method('__invoke');

        $this->assertStringContainsString('3', (string) $this->gateway()->send($this->request())->error);
    }

    public function testAnUnpublishedNumberIsRefused(): void
    {
        $this->readyToSend($this->number(3, 'ESPM off', false));
        $this->handler->expects($this->never())->method('__invoke');

        $this->assertFalse($this->gateway()->send($this->request())->accepted);
    }

    public function testANumberWithoutAnApiKeyIsRefusedWithItsName(): void
    {
        $this->readyToSend($this->number(3, 'ESPM A', true, ''));
        $this->handler->expects($this->never())->method('__invoke');

        $result = $this->gateway()->send($this->request());

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('ESPM A', (string) $result->error);
    }

    public function testATextMessageGoesToTheHandlerAsTheDialogHsmExpectsIt(): void
    {
        $this->readyToSend();
        $captured = $this->handlerAnswers(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'wamid' => 'wamid.X', 'retryable' => false]);

        $this->gateway()->send($this->request());

        /** @var SendWhatsAppMessage $message */
        $message = $captured['message'];
        $this->assertSame(7, $message->leadId);
        $this->assertSame('11999990001', $message->phone);
        $this->assertSame('key-1', $message->apiKey);
        $this->assertSame('https://waba-v2.360dialog.io/messages', $message->baseUrl);
        $this->assertSame('welcome_v1', $message->templateName);
        $this->assertSame('ESPM A', $message->whatsAppNumberName);
        $this->assertSame(11, $message->campaignId);
        $this->assertSame(22, $message->campaignEventId);
        // The variables in the order of their numbers, named by them: vars is the list of the names.
        $this->assertSame(['content' => 'welcome_v1', 'language' => 'pt_BR', 'vars' => '1,2', 1 => 'Ana', 2 => 'Curso X'], $message->payloadData);
        // Housekeeping on, the rate limit of the number kept, no retry exception: the answer comes back to the step.
        $this->assertSame([false, false, true], $captured['flags']);
    }

    public function testTheBaseUrlIsTheNumbersOwnWithoutTheTrailingSlash(): void
    {
        $this->readyToSend($this->number(3, 'ESPM A', true, 'key-1', 'https://hub.example.com/messages/'), ['base_url' => 'https://integration.example.com/m']);
        $captured = $this->handlerAnswers(['success' => true, 'wamid' => 'w', 'http_status' => 200]);

        $this->gateway()->send($this->request());

        $this->assertSame('https://hub.example.com/messages', $captured['message']->baseUrl);
    }

    public function testTheBaseUrlFallsBackToTheOneSavedOnTheIntegration(): void
    {
        $this->readyToSend(null, ['base_url' => 'https://integration.example.com/m/']);
        $captured = $this->handlerAnswers(['success' => true, 'wamid' => 'w', 'http_status' => 200]);

        $this->gateway()->send($this->request());

        $this->assertSame('https://integration.example.com/m', $captured['message']->baseUrl);
    }

    public function testAnImageGoesAsTheHeaderFile(): void
    {
        $this->readyToSend();
        $captured = $this->handlerAnswers(['success' => true, 'wamid' => 'w', 'http_status' => 200]);

        $this->gateway()->send($this->request('image', ['1' => 'Ana'], [['card_index' => 0, 'header_image_link' => 'https://x.test/a.png?v=2']]));

        $this->assertSame('https://x.test/a.png?v=2', $captured['message']->payloadData['url_arquivo']);
        $this->assertArrayNotHasKey('cards', $captured['message']->payloadData);
    }

    public function testAnImageTheDialogHsmWouldDropFromTheMessageIsRefusedBeforeTheSend(): void
    {
        $this->readyToSend();
        $this->handler->expects($this->never())->method('__invoke');

        $result = $this->gateway()->send($this->request('image', ['1' => 'Ana'], [['card_index' => 0, 'header_image_link' => 'https://x.test/media/123']]));

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('jpg', (string) $result->error);
    }

    public function testACarouselGoesAsTheCardsList(): void
    {
        $this->readyToSend();
        $captured = $this->handlerAnswers(['success' => true, 'wamid' => 'w', 'http_status' => 200]);
        $cards    = [['card_index' => 0, 'header_image_link' => 'https://x.test/a'], ['card_index' => 1, 'header_image_link' => 'https://x.test/b']];

        $this->gateway()->send($this->request('carousel', ['1' => 'Ana'], $cards));

        $this->assertSame($cards, $captured['message']->payloadData['cards']);
        $this->assertArrayNotHasKey('url_arquivo', $captured['message']->payloadData);
    }

    public function testAnAcceptedAnswerKeepsTheWamidAndTheStatus(): void
    {
        $this->readyToSend();
        $this->handlerAnswers(['success' => true, 'response' => ['messages' => [['id' => 'wamid.X']]], 'error' => null, 'http_status' => 200, 'wamid' => 'wamid.X', 'retryable' => false]);

        $result = $this->gateway()->send($this->request());

        $this->assertTrue($result->accepted);
        $this->assertSame('wamid.X', $result->wamid);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame(['messages' => [['id' => 'wamid.X']]], $result->response);
    }

    public function testARefusedAnswerKeepsTheReasonAndTheStatus(): void
    {
        $this->readyToSend();
        $this->handlerAnswers(['success' => false, 'response' => ['error' => 'x'], 'error' => '[API 360dialog] template not found', 'http_status' => 404, 'wamid' => null, 'retryable' => false]);

        $result = $this->gateway()->send($this->request());

        $this->assertFalse($result->accepted);
        $this->assertSame('[API 360dialog] template not found', $result->error);
        $this->assertSame(404, $result->httpStatus);
    }

    public function testARefusedAnswerWithoutAReasonStillSaysSomething(): void
    {
        $this->readyToSend();
        $this->handlerAnswers(['success' => false, 'response' => null, 'error' => null, 'http_status' => null, 'wamid' => null]);

        $this->assertNotSame('', (string) $this->gateway()->send($this->request())->error);
    }

    public function testAnExceptionOfTheHandlerIsARefusalAndIsLogged(): void
    {
        $this->readyToSend();
        $this->handler->method('__invoke')->willThrowException(new \RuntimeException('database gone'));
        $this->logger->expects($this->once())->method('error')->with(
            $this->stringContains('DialogHSM'),
            $this->callback(static fn (array $context): bool => 'database gone' === ($context['error'] ?? null) && 7 === ($context['contact_id'] ?? null))
        );

        $result = $this->gateway()->send($this->request());

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('database gone', (string) $result->error);
    }
}
