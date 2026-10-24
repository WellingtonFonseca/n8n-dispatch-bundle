<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableRows;
use PHPUnit\Framework\TestCase;

class TemplateVariableRowsTest extends TestCase
{
    public function testEachSourceKindBecomesARow(): void
    {
        $json = json_encode([
            '1'    => ['source' => 'field', 'value' => 'firstname'],
            '2'    => ['source' => 'static', 'value' => 'Disciplina'],
            'nome' => ['source' => 'custom_object', 'customObject' => 'disciplina', 'customObjectField' => 'nome'],
            'old'  => ['source' => 'field', 'field' => 'email'],
        ]);

        $this->assertSame([
            ['name' => '1', 'source' => 'field', 'value' => 'firstname'],
            ['name' => '2', 'source' => 'static', 'value' => 'Disciplina'],
            ['name' => 'nome', 'source' => 'custom_object', 'value' => 'disciplina / nome'],
            ['name' => 'old', 'source' => 'field', 'value' => 'email'],
        ], TemplateVariableRows::fromJson($json));
    }

    public function testNoOrBadJsonGivesNoRows(): void
    {
        $this->assertSame([], TemplateVariableRows::fromJson(null));
        $this->assertSame([], TemplateVariableRows::fromJson(''));
        $this->assertSame([], TemplateVariableRows::fromJson('not json'));
        $this->assertSame([], TemplateVariableRows::fromJson('{"1": "text"}'));
    }

    public function testAnUnknownSourceCountsAsStatic(): void
    {
        $this->assertSame([['name' => '1', 'source' => 'static', 'value' => 'x']], TemplateVariableRows::fromJson('{"1": {"source": "weird", "value": "x"}}'));
    }

    public function testAnEntryThatIsNotAnObjectIsSkippedAndTheNextOnesStillRead(): void
    {
        $rows = TemplateVariableRows::fromJson(json_encode(['bad' => 'text', 'also' => 5, 'good' => ['source' => 'static', 'value' => 'x']]));

        $this->assertSame([['name' => 'good', 'source' => 'static', 'value' => 'x']], $rows);
    }

    public function testAFieldEntryPrefersItsFieldOverTheOldValueKeyAndStaticValuesAreText(): void
    {
        $rows = TemplateVariableRows::fromJson(json_encode([
            'a' => ['source' => 'field', 'field' => 'email', 'value' => 'old'],
            'b' => ['source' => 'static', 'value' => 42],
            'c' => ['source' => 'field', 'field' => 7],
            'd' => ['source' => 'nope', 'value' => 'text'],
        ]));

        $this->assertSame([['name' => 'a', 'source' => 'field', 'value' => 'email'], ['name' => 'b', 'source' => 'static', 'value' => '42'], ['name' => 'c', 'source' => 'field', 'value' => '7'], ['name' => 'd', 'source' => 'static', 'value' => 'text']], $rows);
    }

    public function testACustomObjectEntryShowsObjectSlashFieldWithoutLooseSeparators(): void
    {
        $rows = TemplateVariableRows::fromJson(json_encode([
            'a' => ['source' => 'custom_object', 'customObject' => 'disc', 'customObjectField' => 'nome'],
            'b' => ['source' => 'custom_object', 'customObjectField' => 'nome'],
            'c' => ['source' => 'custom_object', 'customObject' => 'disc'],
            'd' => ['source' => 'custom_object'],
        ]));

        $this->assertSame(['disc / nome', 'nome', 'disc', ''], array_column($rows, 'value'));
    }
}
