<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use MauticPlugin\N8nDispatchBundle\EventListener\EmailDeleteGuardSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The guard that keeps an Email in use by a "Send via n8n (Email)" step from
 * being deleted, on the screen (one and batch) and on the REST API.
 */
class EmailDeleteGuardSubscriberTest extends TestCase
{
    private const SCREEN_DELETE = 'Mautic\EmailBundle\Controller\EmailController::deleteAction';
    private const SCREEN_BATCH  = 'Mautic\EmailBundle\Controller\EmailController::batchDeleteAction';
    private const API_DELETE    = 'Mautic\EmailBundle\Controller\Api\EmailApiController::deleteEntityAction';
    private const API_BATCH     = 'Mautic\EmailBundle\Controller\Api\EmailApiController::deleteEntitiesAction';

    /** @var MockObject&FlashBag */
    private MockObject $flashBag;

    /**
     * @param list<int> $inUse the Email ids a step uses
     */
    private function subscriber(array $inUse, bool $mayDelete = true): EmailDeleteGuardSubscriber
    {
        $finder = $this->createMock(EmailTemplateUsageFinder::class);
        $finder->method('inUseAmong')->willReturnCallback(static function (array $ids) use ($inUse): array {
            // The real finder takes ints only (strict types): a string id would be a TypeError there.
            foreach ($ids as $id) {
                self::assertIsInt($id);
            }

            return array_values(array_intersect($ids, $inUse));
        });

        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->with(['email:emails:deleteown', 'email:emails:deleteother'], 'MATCH_ONE')->willReturn($mayDelete);

        $this->flashBag = $this->createMock(FlashBag::class);

        return new EmailDeleteGuardSubscriber($finder, $this->flashBag, new EnUsTranslator(), $security);
    }

    private function event(string $controller, string $method, array $attributes = [], array $query = []): ControllerEvent
    {
        $request = Request::create('/x'.([] === $query ? '' : '?'.http_build_query($query)), $method);
        $request->attributes->add($attributes + ['_controller' => $controller]);

        return new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn () => 'core', $request, HttpKernelInterface::SUB_REQUEST);
    }

    public function testItListensToTheControllerEvent(): void
    {
        $this->assertSame([KernelEvents::CONTROLLER => 'guard'], EmailDeleteGuardSubscriber::getSubscribedEvents());
    }

    public function testDeletingOneEmailInUseOnTheScreenBecomesAHarmlessGetWithAnError(): void
    {
        $subscriber = $this->subscriber([5]);
        $this->flashBag->expects($this->once())->method('add')->with('mautic.n8ndispatch.email.error.in_use', [], 'error');
        $event = $this->event(self::SCREEN_DELETE, 'POST', ['objectId' => '5']);

        $subscriber->guard($event);

        $this->assertSame('GET', $event->getRequest()->getMethod());
        $this->assertSame('core', ($event->getController())());
    }

    public function testDeletingOneEmailNotInUseOnTheScreenIsLeftAlone(): void
    {
        $subscriber = $this->subscriber([5]);
        $this->flashBag->expects($this->never())->method('add');
        $event = $this->event(self::SCREEN_DELETE, 'POST', ['objectId' => '6']);

        $subscriber->guard($event);

        $this->assertSame('POST', $event->getRequest()->getMethod());
    }

    public function testOnlyThePostOfTheScreenDeleteIsChecked(): void
    {
        $subscriber = $this->subscriber([5]);
        $this->flashBag->expects($this->never())->method('add');
        $event = $this->event(self::SCREEN_DELETE, 'GET', ['objectId' => '5']);

        $subscriber->guard($event);

        $this->assertSame('GET', $event->getRequest()->getMethod());
    }

    public function testTheScreenBatchDropsTheEmailsInUseWithOneErrorEach(): void
    {
        $subscriber = $this->subscriber([2, 4]);
        $this->flashBag->expects($this->exactly(2))->method('add')->with('mautic.n8ndispatch.email.error.in_use', [], 'error');
        $event = $this->event(self::SCREEN_BATCH, 'POST', [], ['ids' => '[1,2,3,4]']);

        $subscriber->guard($event);

        $this->assertSame('[1,3]', $event->getRequest()->query->get('ids'));
    }

    public function testTheScreenBatchWithNothingInUseIsLeftAlone(): void
    {
        $subscriber = $this->subscriber([9]);
        $this->flashBag->expects($this->never())->method('add');
        $event = $this->event(self::SCREEN_BATCH, 'POST', [], ['ids' => '[1,2]']);

        $subscriber->guard($event);

        $this->assertSame('[1,2]', $event->getRequest()->query->get('ids'));
    }

    public function testAScreenBatchWithoutReadableIdsIsLeftAlone(): void
    {
        $subscriber = $this->subscriber([1]);
        $this->flashBag->expects($this->never())->method('add');
        $event = $this->event(self::SCREEN_BATCH, 'POST', [], ['ids' => 'not json']);

        $subscriber->guard($event);

        $this->assertSame('not json', $event->getRequest()->query->get('ids'));
    }

    public function testTheScreenBatchIsOnlyCheckedOnPost(): void
    {
        $subscriber = $this->subscriber([1]);
        $this->flashBag->expects($this->never())->method('add');
        $event = $this->event(self::SCREEN_BATCH, 'GET', [], ['ids' => '[1]']);

        $subscriber->guard($event);

        $this->assertSame('[1]', $event->getRequest()->query->get('ids'));
    }

    public function testTheApiRefusesToDeleteAnEmailInUse(): void
    {
        $subscriber = $this->subscriber([5]);
        $event      = $this->event(self::API_DELETE, 'DELETE', ['id' => '5']);

        $subscriber->guard($event);

        $response = ($event->getController())();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            ['errors' => [['code' => 409, 'message' => "The Email can't be deleted: a 'Send via n8n (Email)' step uses it.", 'type' => null]]],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testTheApiDeletesAnEmailNotInUse(): void
    {
        $subscriber = $this->subscriber([5]);
        $event      = $this->event(self::API_DELETE, 'DELETE', ['id' => '6']);

        $subscriber->guard($event);

        $this->assertSame('core', ($event->getController())());
    }

    public function testTheApiBatchIsRefusedAsAWholeWhenOneEmailIsInUse(): void
    {
        $subscriber = $this->subscriber([2]);
        $event      = $this->event(self::API_BATCH, 'DELETE', [], ['ids' => '1,2,3']);

        $subscriber->guard($event);

        $this->assertSame(409, ($event->getController())()->getStatusCode());
    }

    public function testTheApiBatchWithNothingInUseGoesThrough(): void
    {
        $subscriber = $this->subscriber([9]);
        $event      = $this->event(self::API_BATCH, 'DELETE', [], ['ids' => '1,2,3']);

        $subscriber->guard($event);

        $this->assertSame('core', ($event->getController())());
    }

    public function testAUserWhoMayNotDeleteGetsNoAnswerFromTheGuard(): void
    {
        $subscriber = $this->subscriber([5], false);
        $this->flashBag->expects($this->never())->method('add');
        $screen = $this->event(self::SCREEN_DELETE, 'POST', ['objectId' => '5']);
        $api    = $this->event(self::API_DELETE, 'DELETE', ['id' => '5']);

        $subscriber->guard($screen);
        $subscriber->guard($api);

        $this->assertSame('POST', $screen->getRequest()->getMethod());
        $this->assertSame('core', ($api->getController())());
    }

    public function testNonNumericOrNonListIdsAreLeftToCore(): void
    {
        $subscriber = $this->subscriber([5]);
        $this->flashBag->expects($this->never())->method('add');
        $scalar = $this->event(self::SCREEN_BATCH, 'POST', [], ['ids' => '5']);
        $mixed  = $this->event(self::SCREEN_BATCH, 'POST', [], ['ids' => '["abc",[5]]']);
        $api    = $this->event(self::API_DELETE, 'DELETE', ['id' => 'abc']);

        $subscriber->guard($scalar);
        $subscriber->guard($mixed);
        $subscriber->guard($api);

        $this->assertSame('5', $scalar->getRequest()->query->get('ids'));
        $this->assertSame('["abc",[5]]', $mixed->getRequest()->query->get('ids'));
        $this->assertSame('core', ($api->getController())());
    }

    public function testOtherControllersAreNeverTouched(): void
    {
        $subscriber = $this->subscriber([5]);
        $this->flashBag->expects($this->never())->method('add');
        $event = $this->event('Mautic\EmailBundle\Controller\EmailController::cloneAction', 'POST', ['objectId' => '5', 'id' => '5'], ['ids' => '5']);

        $subscriber->guard($event);

        $this->assertSame('POST', $event->getRequest()->getMethod());
        $this->assertSame('core', ($event->getController())());
    }
}
