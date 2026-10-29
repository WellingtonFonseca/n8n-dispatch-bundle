<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\EventListener\CampaignEmailStepGuardSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The guard that keeps the REST API from saving a "Send via n8n (Email)" step with no Email, or with an Email that
 * does not exist (the screen's form already requires one; the API does not look at the step's properties).
 */
class CampaignEmailStepGuardSubscriberTest extends TestCase
{
    private const NEW        = 'Mautic\CampaignBundle\Controller\Api\CampaignApiController::newEntityAction';
    private const EDIT       = 'Mautic\CampaignBundle\Controller\Api\CampaignApiController::editEntityAction';
    private const NEW_BATCH  = 'Mautic\CampaignBundle\Controller\Api\CampaignApiController::newEntitiesAction';
    private const EDIT_BATCH = 'Mautic\CampaignBundle\Controller\Api\CampaignApiController::editEntitiesAction';
    private const TYPE       = 'n8ndispatch.email.send';

    protected function setUp(): void
    {
        // Set by Mautic's boot; empty on the stacks.
        if (!defined('MAUTIC_TABLE_PREFIX')) {
            define('MAUTIC_TABLE_PREFIX', '');
        }
    }

    /**
     * @param list<int> $existing the Email ids that exist
     */
    private function subscriber(array $existing, ?int &$queries = null, ?array &$asked = null, bool $asText = false): CampaignEmailStepGuardSubscriber
    {
        $queries    = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(static function (string $sql, array $params) use ($existing, &$queries, &$asked, $asText): array {
            ++$queries;
            $asked = $params[0];
            $found = array_values(array_intersect($params[0], $existing));

            // The driver may hand the ids back as text.
            return $asText ? array_map('strval', $found) : $found;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new CampaignEmailStepGuardSubscriber($em, new EnUsTranslator());
    }

    /**
     * @param array<mixed> $body
     */
    private function event(string $controller, array $body): ControllerEvent
    {
        $request = Request::create('/api/campaigns/new', 'POST', $body);
        $request->attributes->set('_controller', $controller);

        return new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn () => 'core', $request, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function step(array $properties, string $type = self::TYPE, string $name = 'Welcome'): array
    {
        return ['id' => 'new1', 'name' => $name, 'type' => $type, 'properties' => $properties];
    }

    private function refusal(ControllerEvent $event): ?string
    {
        $response = ($event->getController())();

        if ('core' === $response) {
            return null;
        }

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(400, $payload['errors'][0]['code']);
        $this->assertNull($payload['errors'][0]['type']);

        return $payload['errors'][0]['message'];
    }

    public function testItListensToTheControllerEvent(): void
    {
        $this->assertSame([KernelEvents::CONTROLLER => 'guard'], CampaignEmailStepGuardSubscriber::getSubscribedEvents());
    }

    public function testAStepWithNoEmailIsRefused(): void
    {
        $event = $this->event(self::NEW, ['name' => 'C', 'events' => [$this->step(['status' => 'production'])]]);

        $this->subscriber([])->guard($event);

        $this->assertSame("The 'Send via n8n (Email)' step 'Welcome' has no Email picked.", $this->refusal($event));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyValues(): iterable
    {
        yield 'zero' => [0];
        yield 'zero as text' => ['0'];
        yield 'empty text' => [''];
        yield 'null' => [null];
        yield 'not a number' => ['abc'];
        yield 'negative' => [-3];
        yield 'a list' => [[5]];
    }

    /**
     * @dataProvider emptyValues
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyValues')]
    public function testAnEmailThatIsNotAPositiveNumberCountsAsNoEmail(mixed $value): void
    {
        $event = $this->event(self::NEW, ['events' => [$this->step(['email' => $value])]]);

        $this->subscriber([5])->guard($event);

        $this->assertSame("The 'Send via n8n (Email)' step 'Welcome' has no Email picked.", $this->refusal($event));
    }

    public function testAnEmailThatDoesNotExistIsRefusedWithItsNumber(): void
    {
        $event = $this->event(self::NEW, ['events' => [$this->step(['email' => 77])]]);

        $this->subscriber([5])->guard($event);

        $this->assertSame("The 'Send via n8n (Email)' step 'Welcome' points at Email #77, which does not exist.", $this->refusal($event));
    }

    public function testAnExistingEmailGoesThroughAsNumberOrText(): void
    {
        $asNumber = $this->event(self::NEW, ['events' => [$this->step(['email' => 5])]]);
        $asText   = $this->event(self::NEW, ['events' => [$this->step(['email' => '5'])]]);

        $this->subscriber([5])->guard($asNumber);
        $this->subscriber([5])->guard($asText);

        $this->assertNull($this->refusal($asNumber));
        $this->assertNull($this->refusal($asText));
    }

    public function testAStepWithoutNameIsNamedByItsPosition(): void
    {
        $event = $this->event(self::NEW, ['events' => [
            $this->step(['email' => 5]),
            ['id' => 'new2', 'type' => self::TYPE, 'properties' => []],
        ]]);

        $this->subscriber([5])->guard($event);

        $this->assertSame("The 'Send via n8n (Email)' step '2' has no Email picked.", $this->refusal($event));
    }

    public function testTheStepsAfterAnIgnoredOneAreStillChecked(): void
    {
        $afterOtherStep = $this->event(self::NEW, ['events' => [$this->step([], 'lead.changepoints'), 'x', $this->step([])]]);
        $afterNoEvents  = $this->event(self::NEW_BATCH, [['name' => 'No steps'], 'x', ['events' => [$this->step([])]]]);

        $this->subscriber([])->guard($afterOtherStep);
        $this->subscriber([])->guard($afterNoEvents);

        $this->assertNotNull($this->refusal($afterOtherStep));
        $this->assertNotNull($this->refusal($afterNoEvents));
    }

    public function testABlankNameFallsBackToThePosition(): void
    {
        $event = $this->event(self::NEW, ['events' => [$this->step(['email' => 5]), $this->step([], self::TYPE, '  ')]]);

        $this->subscriber([5])->guard($event);

        $this->assertSame("The 'Send via n8n (Email)' step '2' has no Email picked.", $this->refusal($event));
    }

    public function testEachEmailIsAskedOnceAsAListOfNumbersAndTheAnswerMayComeAsText(): void
    {
        $event = $this->event(self::NEW, ['events' => [$this->step(['email' => '5']), $this->step(['email' => 5]), $this->step(['email' => 6])]]);

        $this->subscriber([5, 6], $queries, $asked, true)->guard($event);

        $this->assertNull($this->refusal($event));
        $this->assertSame([5, 6], $asked);
    }

    public function testTheMissingEmailIsReportedBeforeAnyLookup(): void
    {
        $event = $this->event(self::NEW, ['events' => [$this->step(['email' => 77]), $this->step([])]]);

        $this->subscriber([], $queries)->guard($event);

        $this->assertStringContainsString('has no Email picked', (string) $this->refusal($event));
        $this->assertSame(0, $queries);
    }

    public function testOtherStepsAndCampaignsWithoutStepsAreLeftAlone(): void
    {
        $other = $this->event(self::NEW, ['events' => [$this->step([], 'lead.changepoints')]]);
        $none  = $this->event(self::NEW, ['name' => 'C']);

        $this->subscriber([])->guard($other);
        $this->subscriber([])->guard($none);

        $this->assertNull($this->refusal($other));
        $this->assertNull($this->refusal($none));
    }

    public function testEditsAreCheckedToo(): void
    {
        $event = $this->event(self::EDIT, ['events' => [$this->step(['email' => 77])]]);

        $this->subscriber([])->guard($event);

        $this->assertNotNull($this->refusal($event));
    }

    public function testABatchIsRefusedAsAWholeWhenOneCampaignHasABadStep(): void
    {
        $good = ['name' => 'Good', 'events' => [$this->step(['email' => 5])]];
        $bad  = ['name' => 'Bad', 'events' => [$this->step([])]];

        foreach ([self::NEW_BATCH, self::EDIT_BATCH] as $controller) {
            $event = $this->event($controller, [$good, $bad]);

            $this->subscriber([5])->guard($event);

            $this->assertNotNull($this->refusal($event), $controller);
        }

        $allGood = $this->event(self::NEW_BATCH, [$good, $good]);
        $this->subscriber([5])->guard($allGood);
        $this->assertNull($this->refusal($allGood));
    }

    public function testTheEmailsAreLookedUpOnceForTheWholeBody(): void
    {
        $event = $this->event(self::NEW_BATCH, [
            ['events' => [$this->step(['email' => 5]), $this->step(['email' => 6])]],
            ['events' => [$this->step(['email' => 5])]],
        ]);

        $this->subscriber([5, 6], $queries)->guard($event);

        $this->assertNull($this->refusal($event));
        $this->assertSame(1, $queries);
    }

    public function testNothingIsLookedUpWhenNoStepNeedsAnEmail(): void
    {
        $event = $this->event(self::NEW, ['events' => [$this->step(['points' => 1], 'lead.changepoints')]]);

        $this->subscriber([], $queries)->guard($event);

        $this->assertSame(0, $queries);
    }

    public function testBodiesThatAreNotListsOfStepsAreLeftToCore(): void
    {
        $this->assertNull($this->refusal($this->guarded(self::NEW, ['events' => 'x'])));
        $this->assertNull($this->refusal($this->guarded(self::NEW, ['events' => ['x']])));
        $this->assertNull($this->refusal($this->guarded(self::NEW_BATCH, ['x', ['events' => 'y']])));
    }

    public function testAStepWhosePropertiesAreNotAnArrayHasNoEmail(): void
    {
        $event = $this->guarded(self::NEW, ['events' => [['type' => self::TYPE, 'name' => 'Welcome', 'properties' => 'x']]]);

        $this->assertSame("The 'Send via n8n (Email)' step 'Welcome' has no Email picked.", $this->refusal($event));
    }

    public function testOtherControllersAreNeverTouched(): void
    {
        $event = $this->event('Mautic\CampaignBundle\Controller\Api\CampaignApiController::getEntitiesAction', ['events' => [$this->step([])]]);

        $this->subscriber([])->guard($event);

        $this->assertNull($this->refusal($event));
    }

    /**
     * @param array<mixed> $body
     */
    private function guarded(string $controller, array $body): ControllerEvent
    {
        $event = $this->event($controller, $body);
        $this->subscriber([])->guard($event);

        return $event;
    }

    public function testAnEmailGivenAsNumericTextIsLookedUpAsANumber(): void
    {
        $event = $this->event(self::NEW, ['name' => 'C', 'events' => [$this->step(['email' => '7'])]]);

        $this->subscriber([7], $queries, $asked)->guard($event);

        $this->assertNull($this->refusal($event), 'the text "7" is the Email 7, found by its number');
        $this->assertSame([7], $asked, 'the ids go to the query as numbers');
    }

    public function testTheLookupIsOneQueryOnTheEmailsTableWithTheIdsAsIntegers(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchFirstColumn')
            ->with('SELECT id FROM '.MAUTIC_TABLE_PREFIX.'emails WHERE id IN (?)', [[7]], [ArrayParameterType::INTEGER])
            ->willReturn([7]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $event = $this->event(self::NEW, ['name' => 'C', 'events' => [$this->step(['email' => 7])]]);

        (new CampaignEmailStepGuardSubscriber($em, new EnUsTranslator()))->guard($event);

        $this->assertNull($this->refusal($event));
    }
}
