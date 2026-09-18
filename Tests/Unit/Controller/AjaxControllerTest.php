<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Controller;

use Mautic\EmailBundle\Entity\Copy;
use Mautic\EmailBundle\Entity\CopyRepository;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\N8nDispatchBundle\Controller\AjaxController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
}
