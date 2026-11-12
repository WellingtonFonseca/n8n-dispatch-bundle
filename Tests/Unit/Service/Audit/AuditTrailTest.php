<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Audit;

use MauticPlugin\N8nDispatchBundle\Service\Audit\AuditFieldFormatter;
use MauticPlugin\N8nDispatchBundle\Service\Audit\AuditTrail;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AuditTrailTest extends TestCase
{
    /** A formatter that says everything as stored, so these tests are about the rows, not the wording. */
    private function asStored(): AuditFieldFormatter
    {
        $formatter = $this->createMock(AuditFieldFormatter::class);
        $formatter->method('label')->willReturnCallback(static fn (string $object, string $field): string => $field);
        $formatter->method('value')->willReturnCallback(static fn (string $object, string $field, string $stored): string => $stored);

        return $formatter;
    }

    private function rows(array $logs): array
    {
        return AuditTrail::rows($logs, 'hsm_template', $this->asStored());
    }

    public function testAnUpdateListsEachFieldWithItsOldAndNewValue(): void
    {
        $date = new \DateTime('2026-11-11 10:00');

        $rows = $this->rows([['dateAdded' => $date, 'userName' => 'Ana', 'action' => 'update', 'details' => ['subject' => ['Oi', 'Olá'], 'name' => ['A', 'B']]]]);

        $this->assertSame($date, $rows[0]['date']);
        $this->assertSame('Ana', $rows[0]['user']);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertSame([['field' => 'subject', 'old' => 'Oi', 'new' => 'Olá'], ['field' => 'name', 'old' => 'A', 'new' => 'B']], $rows[0]['changes']);
    }

    public function testACreateHasNoOldValue(): void
    {
        $rows = $this->rows([['action' => 'create', 'details' => ['name' => [null, 'X']]]]);

        $this->assertSame([['field' => 'name', 'old' => '', 'new' => 'X']], $rows[0]['changes']);
    }

    public function testADeleteKeepsOnlyTheNameAsTheNewValue(): void
    {
        $rows = $this->rows([['action' => 'delete', 'details' => ['name' => 'Gone']]]);

        $this->assertSame([['field' => 'name', 'old' => '', 'new' => 'Gone']], $rows[0]['changes']);
    }

    public function testAnArrayValueIsShownAsJson(): void
    {
        $rows = $this->rows([['action' => 'update', 'details' => ['lists' => [[1], [1, 2]]]]]);

        $this->assertSame([['field' => 'lists', 'old' => '[1]', 'new' => '[1,2]']], $rows[0]['changes']);
    }

    public function testALongValueIsCutWithAnEllipsis(): void
    {
        $rows = $this->rows([['action' => 'update', 'details' => ['customHtml' => ['', str_repeat('é', 400)]]]]);

        $this->assertSame(301, mb_strlen($rows[0]['changes'][0]['new']));
        $this->assertStringEndsWith('…', $rows[0]['changes'][0]['new']);
    }

    public function testALineWithoutDetailsHasNoChanges(): void
    {
        $this->assertSame([], $this->rows([['action' => 'update', 'details' => null]])[0]['changes']);
        $this->assertSame([], $this->rows([])); 
    }

    public function testAValueOfExactlyTheLimitIsNotCut(): void
    {
        $rows = $this->rows([['action' => 'update', 'details' => ['x' => ['', str_repeat('a', 300)]]]]);

        $this->assertSame(300, mb_strlen($rows[0]['changes'][0]['new']));
    }

    public function testTheFormatterSaysTheFieldAndBothValues(): void
    {
        $formatter = $this->createMock(AuditFieldFormatter::class);
        $formatter->method('label')->willReturnCallback(static fn (string $object, string $field): string => $object.'/'.strtoupper($field));
        $formatter->method('value')->willReturnCallback(static fn (string $object, string $field, string $stored): string => '<'.$stored.'>');

        $rows = AuditTrail::rows([['action' => 'update', 'details' => ['name' => ['A', 'B']]]], 'sms_template', $formatter);

        $this->assertSame([['field' => 'sms_template/NAME', 'old' => '<A>', 'new' => '<B>']], $rows[0]['changes']);
    }

    public function testAValueThatIsNotAnOldAndNewPairIsShownAsTheNewValue(): void
    {
        $rows = $this->rows([['action' => 'update', 'details' => ['onlyNew' => [1 => 'n'], 'onlyOld' => [0 => 'o']]]]);

        $this->assertSame('', $rows[0]['changes'][0]['old']);
        $this->assertSame('{"1":"n"}', $rows[0]['changes'][0]['new']);
        $this->assertSame('', $rows[0]['changes'][1]['old']);
        $this->assertSame('["o"]', $rows[0]['changes'][1]['new']);
    }

    public function testJsonKeepsAccentsAndSlashesAsTyped(): void
    {
        $rows = $this->rows([['action' => 'update', 'details' => ['x' => [['é'], ['a/b']]]]]);

        $this->assertSame('["é"]', $rows[0]['changes'][0]['old']);
        $this->assertSame('["a/b"]', $rows[0]['changes'][0]['new']);
    }

    public function testACutValueKeepsTheStartOfTheText(): void
    {
        $rows = $this->rows([['action' => 'update', 'details' => ['x' => ['', 'Start'.str_repeat('b', 400)]]]]);

        $this->assertStringStartsWith('Start', $rows[0]['changes'][0]['new']);
    }

    public function testAFlagThatWentToFalseIsZeroNotEmpty(): void
    {
        $rows = $this->rows([['action' => 'update', 'details' => ['isPublished' => [true, false]]]]);

        $this->assertSame('1', $rows[0]['changes'][0]['old']);
        $this->assertSame('0', $rows[0]['changes'][0]['new']);
    }

    public function testALongValueIsCountedInCharactersNotBytes(): void
    {
        // 200 accented characters are 400 bytes: they are under the limit and must not be cut
        $rows = $this->rows([['action' => 'update', 'details' => ['x' => ['', str_repeat('é', 200)]]]]);

        $this->assertSame(200, mb_strlen($rows[0]['changes'][0]['new']));
    }
}
