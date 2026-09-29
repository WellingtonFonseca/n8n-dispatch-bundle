<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use PHPUnit\Framework\TestCase;

/**
 * Moved from Tests/Unit/Controller/AjaxControllerTest.php (used to call this
 * logic via Reflection, back when it was a private method on that
 * controller) when the scan was extracted into its own service so
 * EventListener/EmailTabSubscriber.php could reuse it too.
 */
class TemplateVariableScannerTest extends TestCase
{
    private TemplateVariableScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new TemplateVariableScanner();
    }

    public function testReturnsTemplateVariables(): void
    {
        $html = '<p>Hi {{aluno_nome}}, your course is {{maivar_curso_nome}}.</p>';

        $this->assertSame(['aluno_nome', 'maivar_curso_nome'], $this->scanner->extract($html));
    }

    public function testExcludesTheReservedUnsubscribeVariable(): void
    {
        $html = '<p>Hi {{aluno_nome}}</p><p><a href="{{n8ndispatch_unsubscribe_url}}">Unsubscribe</a></p>';

        $this->assertSame(['aluno_nome'], $this->scanner->extract($html));
    }

    public function testDeduplicatesRepeatedVariables(): void
    {
        $html = '<p>{{aluno_nome}}</p><p>{{aluno_nome}}</p>';

        $this->assertSame(['aluno_nome'], $this->scanner->extract($html));
    }

    public function testReturnsEmptyArrayWhenNoVariablesPresent(): void
    {
        $this->assertSame([], $this->scanner->extract('<p>Plain text, no placeholders here.</p>'));
    }
}
