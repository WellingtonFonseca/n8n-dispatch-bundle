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

    public function testListsOnlyVariablesWithoutThePrefix(): void
    {
        $html = '<p>{{aluno_nome}} {{ n8n_curso }} {{n8n_data}} {{ matricula }}</p>';

        $this->assertSame(['aluno_nome', 'matricula'], $this->scanner->extractWithoutPrefix($html));
    }

    public function testPrefixCheckIsExactAndCaseSensitive(): void
    {
        $html = '{{n8nx_nome}} {{N8N_nome}} {{n8n-nome}} {{n8n_nome}}';

        $this->assertSame(['n8nx_nome', 'N8N_nome'], $this->scanner->extractWithoutPrefix($html));
    }

    public function testTheReservedUnsubscribeVariableIsNeverReportedWithoutPrefix(): void
    {
        $html = '<a href="{{n8ndispatch_unsubscribe_url}}">Unsubscribe</a> {{n8n_nome}}';

        $this->assertSame([], $this->scanner->extractWithoutPrefix($html));
    }

    public function testNoVariablesWithoutPrefixInPlainHtml(): void
    {
        $this->assertSame([], $this->scanner->extractWithoutPrefix('<p>Hello</p>'));
    }

    public function testTheVariablesOfAnEmailAreThoseOfItsSubjectAndItsHtml(): void
    {
        $this->assertSame(['n8n_a', 'n8n_b'], $this->scanner->extractFromEmail('Oi {{n8n_a}}', '<p>{{n8n_b}}</p>'));
    }

    public function testAVariableOfBothTheSubjectAndTheHtmlIsListedOnce(): void
    {
        $this->assertSame(['n8n_a', 'n8n_b'], $this->scanner->extractFromEmail('{{n8n_a}} {{n8n_b}}', '{{n8n_b}} {{n8n_a}}'));
    }

    public function testTheListHasNoGapsWhenTheSubjectRepeatsAVariableOfTheHtml(): void
    {
        $this->assertSame(['n8n_a', 'n8n_b'], $this->scanner->extractFromEmail('{{n8n_a}}', '{{n8n_a}} {{n8n_b}}'));
    }

    public function testTheReservedVariableInTheMiddleLeavesNoGap(): void
    {
        $this->assertSame(['n8n_a', 'n8n_b'], $this->scanner->extract('{{n8n_a}} {{n8ndispatch_unsubscribe_url}} {{n8n_b}}'));
    }

    public function testAVariableNeverStartsInTheSubjectAndEndsInTheHtml(): void
    {
        $this->assertSame([], $this->scanner->extractFromEmail('Oi {{n8n_a', '}} fim'));
    }

    public function testAnEmailWithoutSubjectOrHtmlHasNoVariables(): void
    {
        $this->assertSame([], $this->scanner->extractFromEmail(null, null));
        $this->assertSame(['n8n_a'], $this->scanner->extractFromEmail(null, '{{n8n_a}}'));
        $this->assertSame(['n8n_a'], $this->scanner->extractFromEmail('{{n8n_a}}', null));
    }

    public function testTheVariablesOfAnEmailWithoutThePrefixComeFromBothTexts(): void
    {
        $this->assertSame(['nome', 'curso'], $this->scanner->extractWithoutPrefixFromEmail('Oi {{nome}} {{n8n_ok}}', '<p>{{curso}} {{n8n_ok}}</p>'));
        $this->assertSame([], $this->scanner->extractWithoutPrefixFromEmail('{{n8n_a}}', '{{n8n_b}} {{n8ndispatch_unsubscribe_url}}'));
    }

    public function testFindNonNumericAcceptsOnlyDigitPlaceholders(): void
    {
        $this->assertSame([], $this->scanner->findNonNumeric('Hello {{1}}, your class is {{ 2 }} at {{03}}.'));
    }

    public function testFindNonNumericListsEverythingElseInOrderWithoutDuplicates(): void
    {
        $text = '{{1}} {{valor}} {{ n8n_nome }} {{1a}} {{valor}} {{ nome completo }} {{}}';

        $this->assertSame(['valor', 'n8n_nome', '1a', 'nome completo', ''], $this->scanner->findNonNumeric($text));
    }

    public function testFindNonNumericListsANameThatOnlyEndsInDigits(): void
    {
        $this->assertSame(['a1', 'x2y3'], $this->scanner->findNonNumeric('{{a1}} {{x2y3}} {{12}}'));
    }

    public function testFindNonNumericIgnoresTextWithoutPlaceholders(): void
    {
        $this->assertSame([], $this->scanner->findNonNumeric('No placeholders, just { braces } and {single}.'));
    }
}
