<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\VariableMappingChecker;
use PHPUnit\Framework\TestCase;

/**
 * Same rules as isEntrySet()/findUnmapped() in Assets/js/n8ndispatch-shared.js (tested in Tests/js).
 */
class VariableMappingCheckerTest extends TestCase
{
    private VariableMappingChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new VariableMappingChecker();
    }

    public function testStaticValueIsSetOnlyWhenNotBlank(): void
    {
        $this->assertTrue($this->checker->isEntrySet(['source' => 'static', 'value' => 'Maria']));
        $this->assertFalse($this->checker->isEntrySet(['source' => 'static', 'value' => '']));
        $this->assertFalse($this->checker->isEntrySet(['source' => 'static', 'value' => '   ']));
        $this->assertFalse($this->checker->isEntrySet(['source' => 'static']));
    }

    public function testContactFieldIsSetWhenOneIsChosen(): void
    {
        $this->assertTrue($this->checker->isEntrySet(['source' => 'field', 'field' => 'firstname']));
        $this->assertFalse($this->checker->isEntrySet(['source' => 'field', 'field' => '']));
    }

    public function testCustomObjectNeedsBothTheObjectAndTheField(): void
    {
        $this->assertTrue($this->checker->isEntrySet(['source' => 'custom_object', 'customObject' => 'courses', 'customObjectField' => 'cursid']));
        $this->assertFalse($this->checker->isEntrySet(['source' => 'custom_object', 'customObject' => 'courses', 'customObjectField' => '']));
        $this->assertFalse($this->checker->isEntrySet(['source' => 'custom_object', 'customObject' => '', 'customObjectField' => 'cursid']));
    }

    public function testAMissingOrNonArrayEntryIsNotSet(): void
    {
        $this->assertFalse($this->checker->isEntrySet(null));
        $this->assertFalse($this->checker->isEntrySet('text'));
        $this->assertFalse($this->checker->isEntrySet([]));
    }

    public function testAnEntryWithoutASourceIsTreatedAsStatic(): void
    {
        $this->assertTrue($this->checker->isEntrySet(['value' => 'x']));
    }

    public function testFindUnmappedKeepsTemplateOrder(): void
    {
        $mapping = [
            'n8n_nome'  => ['source' => 'field', 'field' => 'firstname'],
            'n8n_curso' => ['source' => 'static', 'value' => ''],
            'n8n_data'  => ['source' => 'static', 'value' => '2026-10-01'],
        ];

        $this->assertSame(
            ['n8n_curso', 'n8n_novo'],
            $this->checker->findUnmapped(['n8n_curso', 'n8n_nome', 'n8n_data', 'n8n_novo'], $mapping)
        );
    }

    public function testFindUnmappedIsEmptyWhenEverythingIsMappedOrThereAreNoVariables(): void
    {
        $this->assertSame([], $this->checker->findUnmapped([], []));
        $this->assertSame([], $this->checker->findUnmapped(['a'], ['a' => ['source' => 'static', 'value' => 'x']]));
    }

    public function testFindUnmappedWithNoMappingListsEverything(): void
    {
        $this->assertSame(['a', 'b'], $this->checker->findUnmapped(['a', 'b'], []));
    }
}
