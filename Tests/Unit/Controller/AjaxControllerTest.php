<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Controller;

use Mautic\EmailBundle\Entity\Copy;
use Mautic\EmailBundle\Entity\CopyRepository;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\N8nDispatchBundle\Controller\AjaxController;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollNow;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\EmailBundle\Entity\Email;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\CoreBundle\Twig\Helper\DateHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * getTemplateCopyAction() doesn't touch any of the base AjaxController's own
 * dependencies (no sendJsonResponse(), no container access) — only its own
 * $request/$emailModel arguments — so the controller is instantiated via
 * reflection, skipping CommonController's unrelated, heavy constructor
 * (ManagerRegistry, MauticFactory, UserHelper, ...) entirely.
 */
class AjaxControllerTest extends TestCase
{
    private function buildController(): AjaxController
    {
        return (new \ReflectionClass(AjaxController::class))->newInstanceWithoutConstructor();
    }

    private function mockEmailModel(CopyRepository $copyRepository): EmailModel
    {
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getCopyRepository')->willReturn($copyRepository);

        return $emailModel;
    }

    public function testReturnsStoredHtmlByHash(): void
    {
        $copy = new Copy();
        $copy->setSubject('Welcome');
        $copy->setBody('<html><body>Hi {{name}}</body></html>');

        $copyRepository = $this->createMock(CopyRepository::class);
        $copyRepository->expects($this->once())->method('find')->with('abc123')->willReturn($copy);

        $response = $this->buildController()->getTemplateCopyAction(
            new Request(['hash' => 'abc123']),
            $this->mockEmailModel($copyRepository)
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertSame('<html><body>Hi {{name}}</body></html>', $response->getContent());
    }

    public function testWrapsBodyFragmentWithMinimalHtmlDocument(): void
    {
        $copy = new Copy();
        $copy->setSubject('Plain body');
        $copy->setBody('<p>Just a fragment</p>');

        $copyRepository = $this->createMock(CopyRepository::class);
        $copyRepository->method('find')->willReturn($copy);

        $response = $this->buildController()->getTemplateCopyAction(
            new Request(['hash' => 'xyz']),
            $this->mockEmailModel($copyRepository)
        );

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<!DOCTYPE html>', $content);
        $this->assertStringContainsString('<title>Plain body</title>', $content);
        $this->assertStringContainsString('<p>Just a fragment</p>', $content);
    }

    public function testReturns404WhenHashNotFound(): void
    {
        $copyRepository = $this->createMock(CopyRepository::class);
        $copyRepository->method('find')->willReturn(null);

        $response = $this->buildController()->getTemplateCopyAction(
            new Request(['hash' => 'missing']),
            $this->mockEmailModel($copyRepository)
        );

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns404WhenHashIsMissingFromTheRequest(): void
    {
        $copyRepository = $this->createMock(CopyRepository::class);
        $copyRepository->expects($this->never())->method('find');

        $response = $this->buildController()->getTemplateCopyAction(
            new Request(),
            $this->mockEmailModel($copyRepository)
        );

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function emailVariablesResponse(Request $request, ?string $savedHtml): array
    {
        $email = $this->createMock(Email::class);
        $email->method('getCustomHtml')->willReturn($savedHtml);

        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($email);

        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->method('getFieldList')->willReturn([]);

        $customObjectModel = $this->createMock(CustomObjectModel::class);
        $customObjectModel->method('fetchAllPublishedEntities')->willReturn([]);

        // sendJsonResponse() needs the container, so a plain JsonResponse stands in for it here.
        $controller = new class() extends AjaxController {
            public function __construct()
            {
            }

            protected function sendJsonResponse($dataArray, $statusCode = null, $addIgnoreWdt = true): JsonResponse
            {
                return new JsonResponse($dataArray);
            }
        };

        $response = $controller->getEmailVariablesAction(
            $request,
            $emailModel,
            $fieldModel,
            $customObjectModel,
            new TemplateVariableScanner()
        );

        return json_decode((string) $response->getContent(), true);
    }

    public function testEmailVariablesListsTheOnesWithoutThePrefix(): void
    {
        $data = $this->emailVariablesResponse(new Request([], ['emailId' => 7]), '<p>{{n8n_nome}} {{curso}} {{ data }}</p>');

        $this->assertSame(['n8n_nome', 'curso', 'data'], $data['variables']);
        $this->assertSame(['curso', 'data'], $data['invalidVariables']);
    }

    public function testEmailVariablesHasNoInvalidOnesWhenEveryVariableHasThePrefix(): void
    {
        $data = $this->emailVariablesResponse(new Request([], ['emailId' => 7]), '<p>{{n8n_nome}}</p><a href="{{n8ndispatch_unsubscribe_url}}">x</a>');

        $this->assertSame(['n8n_nome'], $data['variables']);
        $this->assertSame([], $data['invalidVariables']);
    }

    public function testEmailVariablesChecksTheBuilderHtmlWhenItIsSent(): void
    {
        $data = $this->emailVariablesResponse(new Request([], ['emailId' => 7, 'html' => '<p>{{novo}}</p>']), '<p>{{n8n_nome}}</p>');

        $this->assertSame(['novo'], $data['invalidVariables']);
    }

    /**
     * @return array<string, mixed>
     */
    private function hsmVariablesResponse(string $text): array
    {
        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->method('getFieldList')->willReturn([]);

        $customObjectModel = $this->createMock(CustomObjectModel::class);
        $customObjectModel->method('fetchAllPublishedEntities')->willReturn([]);

        // sendJsonResponse() needs the container, so a plain JsonResponse stands in for it here.
        $controller = new class() extends AjaxController {
            public function __construct()
            {
            }

            protected function sendJsonResponse($dataArray, $statusCode = null, $addIgnoreWdt = true): JsonResponse
            {
                return new JsonResponse($dataArray);
            }
        };

        $response = $controller->getHsmVariablesAction(
            new Request([], ['text' => $text]),
            $fieldModel,
            $customObjectModel,
            new TemplateVariableScanner()
        );

        return json_decode((string) $response->getContent(), true);
    }

    public function testHsmVariablesFlagsPlaceholdersThatAreNotNumeric(): void
    {
        $data = $this->hsmVariablesResponse('Hi {{1}}, {{valor}}');

        $this->assertSame(['valor'], $data['nonNumericVariables']);
    }

    public function testHsmVariablesHasNothingToFlagWhenEveryPlaceholderIsNumeric(): void
    {
        $data = $this->hsmVariablesResponse('Hi {{1}}, {{2}}');

        $this->assertSame([], $data['nonNumericVariables']);
    }

    /**
     * @return array<string, mixed>
     */
    private function smsVariablesResponse(string $text): array
    {
        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->method('getFieldList')->willReturn([]);

        $customObjectModel = $this->createMock(CustomObjectModel::class);
        $customObjectModel->method('fetchAllPublishedEntities')->willReturn([]);

        $controller = new class() extends AjaxController {
            public function __construct()
            {
            }

            protected function sendJsonResponse($dataArray, $statusCode = null, $addIgnoreWdt = true): JsonResponse
            {
                return new JsonResponse($dataArray);
            }
        };

        $response = $controller->getSmsVariablesAction(
            new Request([], ['text' => $text]),
            $fieldModel,
            $customObjectModel,
            new TemplateVariableScanner()
        );

        return json_decode((string) $response->getContent(), true);
    }

    public function testSmsVariablesFlagsPlaceholdersThatAreNotNumeric(): void
    {
        $this->assertSame(['valor'], $this->smsVariablesResponse('Hi {{1}}, {{valor}}')['nonNumericVariables']);
    }

    public function testSmsVariablesHasNothingToFlagWhenEveryPlaceholderIsNumeric(): void
    {
        $this->assertSame([], $this->smsVariablesResponse('Hi {{1}}, {{2}}')['nonNumericVariables']);
    }

    /**
     * The "cancelled by / rescheduled by" time is stored as a UTC string (Y-m-d H:i:s). The date helper reads a bare
     * string in the system's "local" timezone by default, which showed that UTC clock time as if it were local: 3 hours
     * ahead with the system in America/Sao_Paulo. A real DateHelper is used (the class is final), with the local
     * timezone set the way the Mautic setting does it.
     */
    private function auditMessage(?string $email): ?string
    {
        $property = new \ReflectionProperty(DateTimeHelper::class, 'defaultLocalTimezone');
        $previous = $property->getValue();
        $property->setValue(null, 'America/Sao_Paulo');

        try {
            $dateHelper = new DateHelper('F j, Y g:i a', 'D, M d', 'F j, Y', 'g:i a', $this->createMock(TranslatorInterface::class), $this->createMock(CoreParametersHelper::class));

            // The controller's $translator property is core's own Translator class, not the interface.
            $translator = $this->createMock(Translator::class);
            $translator->method('trans')->willReturnCallback(
                fn (string $key, array $params): string => 'Cancelado por '.$params['%email%'].' em '.$params['%date%'].'.'
            );

            $controller = $this->buildController();
            (new \ReflectionProperty($controller, 'translator'))->setValue($controller, $translator);

            return (new \ReflectionMethod($controller, 'translateAuditMessage'))
                ->invoke($controller, 'mautic.n8ndispatch.timeline.cancelled_by', $email, '2026-10-01 17:32:37', $dateHelper);
        } finally {
            $property->setValue(null, $previous);
        }
    }

    public function testAuditMessageShowsTheStoredUtcTimeInTheSystemTimezone(): void
    {
        // 17:32 UTC is 14:32 in America/Sao_Paulo (UTC-3). Showing 5:32 pm would be the UTC time read as local.
        $this->assertSame('Cancelado por a@b.c em October 1, 2026 2:32 pm.', $this->auditMessage('a@b.c'));
    }

    public function testAuditMessageIsNullWithoutAUserEmail(): void
    {
        $this->assertNull($this->auditMessage(null));
    }

    public function testRunStatusPollNeedsThePermissionToManagePlugins(): void
    {
        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->with('plugin:plugins:manage')->willReturn(false);
        $runner = $this->createMock(StatusPollNow::class);
        $runner->expects($this->never())->method('run');

        $response = $this->buildController()->runStatusPollAction($security, $runner);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame(0, json_decode((string) $response->getContent(), true)['success']);
    }

    public function testRunStatusPollReturnsWhatTheRunnerFound(): void
    {
        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->with('plugin:plugins:manage')->willReturn(true);
        $runner = $this->createMock(StatusPollNow::class);
        $channels = ['email' => ['requested' => 3, 'changed' => 1, 'unchanged' => 2, 'unknown' => 0, 'invalid' => 0, 'error' => null]];
        $runner->expects($this->once())->method('run')->willReturn(['ok' => true, 'channels' => $channels]);

        $response = $this->buildController()->runStatusPollAction($security, $runner);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(1, $body['success']);
        $this->assertTrue($body['ok']);
        $this->assertSame($channels, $body['channels']);
    }
}
