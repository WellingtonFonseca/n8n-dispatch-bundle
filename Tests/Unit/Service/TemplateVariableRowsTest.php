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
}
