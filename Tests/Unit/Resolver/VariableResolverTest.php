<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\N8nDispatchBundle\Resolver\CustomObjectVariableResolver;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use PHPUnit\Framework\TestCase;

class VariableResolverTest extends TestCase
{
    private CustomObjectVariableResolver $customObjectVariableResolver;

    private LeadModel $leadModel;

    private VariableResolver $resolver;

    protected function setUp(): void
    {
        $this->customObjectVariableResolver = $this->createMock(CustomObjectVariableResolver::class);
        $this->leadModel                    = $this->createMock(LeadModel::class);
        $this->resolver                     = new VariableResolver($this->customObjectVariableResolver, $this->leadModel);
    }

    public function testStaticSourceReturnsItsValue(): void
    {
        $contact  = new Lead();
        $campaign = new Campaign();

        $resolved = $this->resolver->resolveAll(
            ['turma' => ['source' => 'static', 'value' => 'a']],
            $contact,
            $campaign
        );

        $this->assertSame(['turma' => 'a'], $resolved);
    }

    public function testMissingSourceDefaultsToStatic(): void
    {
        $contact  = new Lead();
        $campaign = new Campaign();

        $resolved = $this->resolver->resolveAll(
            ['turma' => ['value' => 'b']],
            $contact,
            $campaign
        );

        $this->assertSame(['turma' => 'b'], $resolved);
    }

    public function testNonArrayEntryResolvesToEmptyStringInsteadOfCrashing(): void
    {
        // Regression for the stale pre-{source,value,field} campaign event
        // found and removed during the Custom Object variable source work
        // (see wiki/n8n-dispatch-plugin.md) — a plain string entry must not
        // throw, it must resolve to "".
        $contact  = new Lead();
        $campaign = new Campaign();

        $resolved = $this->resolver->resolveAll(
            ['legacy' => 'just a plain string'],
            $contact,
            $campaign
        );

        $this->assertSame(['legacy' => ''], $resolved);
    }

    public function testFieldSourceReadsAlreadyHydratedContactField(): void
    {
        $contact = new Lead();
        $contact->setFields([
            'core' => [
                'ra' => ['type' => 'text', 'value' => '3110987654'],
            ],
        ]);
        $campaign = new Campaign();

        // Fields are already hydrated, so the lazy-load repository call must
        // never happen.
        $this->leadModel->expects($this->never())->method('getRepository');

        $resolved = $this->resolver->resolveAll(
            ['destinatario' => ['source' => 'field', 'field' => 'ra']],
            $contact,
            $campaign
        );

        $this->assertSame(['destinatario' => '3110987654'], $resolved);
    }

    public function testFieldSourceFormatsADateFieldToBrazilianFormat(): void
    {
        $contact = new Lead();
        $contact->setFields([
            'core' => [
                'discstart' => ['type' => 'date', 'value' => '2026-09-21'],
            ],
        ]);
        $campaign = new Campaign();

        $resolved = $this->resolver->resolveAll(
            ['inicio' => ['source' => 'field', 'field' => 'discstart']],
            $contact,
            $campaign
        );

        $this->assertSame(['inicio' => '21/09/2026'], $resolved);
    }

    public function testFieldSourceFormatsADatetimeFieldToBrazilianFormat(): void
    {
        $contact = new Lead();
        $contact->setFields([
            'core' => [
                'last_active' => ['type' => 'datetime', 'value' => '2026-09-21 14:30:00'],
            ],
        ]);
        $campaign = new Campaign();

        $resolved = $this->resolver->resolveAll(
            ['ultimo_acesso' => ['source' => 'field', 'field' => 'last_active']],
            $contact,
            $campaign
        );

        $this->assertSame(['ultimo_acesso' => '21/09/2026 14:30:00'], $resolved);
    }

    public function testFieldSourceLazilyHydratesUnhydratedContact(): void
    {
        $contact = new Lead();
        $contact->setId(42);
        $campaign = new Campaign();

        $repository = $this->createMock(LeadRepository::class);
        $repository->expects($this->once())
            ->method('getFieldValues')
            ->with(42)
            ->willReturn([
                'core' => [
                    'email' => ['type' => 'email', 'value' => 'wellington@email.com'],
                ],
            ]);

        $this->leadModel->method('getRepository')->willReturn($repository);

        $resolved = $this->resolver->resolveAll(
            ['matricula' => ['source' => 'field', 'field' => 'email']],
            $contact,
            $campaign
        );

        $this->assertSame(['matricula' => 'wellington@email.com'], $resolved);
    }

    public function testFieldSourceWithEmptyFieldAliasResolvesToEmptyString(): void
    {
        $contact  = new Lead();
        $campaign = new Campaign();

        $this->leadModel->expects($this->never())->method('getRepository');

        $resolved = $this->resolver->resolveAll(
            ['turma' => ['source' => 'field', 'field' => '']],
            $contact,
            $campaign
        );

        $this->assertSame(['turma' => ''], $resolved);
    }

    public function testCustomObjectSourceDelegatesToCustomObjectVariableResolver(): void
    {
        $contact  = new Lead();
        $campaign = new Campaign();

        $this->customObjectVariableResolver->expects($this->once())
            ->method('resolve')
            ->with($contact, $campaign, 'disciplines', 'discname', '<br>')
            ->willReturn('disciplina 1<br>disciplina 2');

        $resolved = $this->resolver->resolveAll(
            ['disciplina' => ['source' => 'custom_object', 'customObject' => 'disciplines', 'customObjectField' => 'discname']],
            $contact,
            $campaign
        );

        $this->assertSame(['disciplina' => 'disciplina 1<br>disciplina 2'], $resolved);
    }

    public function testCustomObjectSourcePassesTheMultiValueSeparatorThrough(): void
    {
        $contact  = new Lead();
        $campaign = new Campaign();

        $this->customObjectVariableResolver->expects($this->once())
            ->method('resolve')
            ->with($contact, $campaign, 'disciplines', 'discname', "\n")
            ->willReturn("disciplina 1\ndisciplina 2");

        $resolved = $this->resolver->resolveAll(
            ['disciplina' => ['source' => 'custom_object', 'customObject' => 'disciplines', 'customObjectField' => 'discname']],
            $contact,
            $campaign,
            "\n"
        );

        $this->assertSame(['disciplina' => "disciplina 1\ndisciplina 2"], $resolved);
    }

    private function contactWithStart(string $value = '2026-10-21', string $type = 'date'): Lead
    {
        $contact = new Lead();
        $contact->setFields(['core' => ['discstart' => ['type' => $type, 'value' => $value]]]);

        return $contact;
    }

    /** @return array<string, array{mixed, string}> */
    public static function offsets(): array
    {
        return [
            'ten days'              => [10, '31/10/2026'],
            'a number as text'      => ['10', '31/10/2026'],
            'with a plus sign'      => ['+10', '31/10/2026'],
            'spaces around it'      => [' 10 ', '31/10/2026'],
            'minus'                 => [-10, '11/10/2026'],
            'minus as text'         => ['-10', '11/10/2026'],
            'zero'                  => [0, '21/10/2026'],
            'empty text'            => ['', '21/10/2026'],
            'not a number'          => ['dez', '21/10/2026'],
            'a decimal'             => ['1.5', '21/10/2026'],
            'null'                  => [null, '21/10/2026'],
            'a list'                => [[10], '21/10/2026'],
            'more than ten years'   => [99999, '18/10/2036'],
            'less than ten years'   => [-99999, '23/10/2016'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('offsets')]
    public function testAContactDateFieldAddsTheDaysOfTheEntry(mixed $days, string $expected): void
    {
        $resolved = $this->resolver->resolveAll(
            ['avaliacao' => ['source' => 'field', 'field' => 'discstart', 'dateOffsetDays' => $days]],
            $this->contactWithStart(),
            new Campaign()
        );

        $this->assertSame(['avaliacao' => $expected], $resolved);
    }

    public function testAnEntryWithoutDaysIsAsBefore(): void
    {
        $resolved = $this->resolver->resolveAll(['inicio' => ['source' => 'field', 'field' => 'discstart']], $this->contactWithStart(), new Campaign());

        $this->assertSame(['inicio' => '21/10/2026'], $resolved);
    }

    public function testTheDaysFollowTheLanguageOfTheTemplate(): void
    {
        $resolved = $this->resolver->resolveAll(
            ['avaliacao' => ['source' => 'field', 'field' => 'discstart', 'dateOffsetDays' => 10]],
            $this->contactWithStart(),
            new Campaign(),
            '<br>',
            'en_US'
        );

        $this->assertSame(['avaliacao' => '10/31/2026'], $resolved);
    }

    public function testAContactDatetimeFieldAddsDaysAndKeepsTheTime(): void
    {
        $resolved = $this->resolver->resolveAll(
            ['avaliacao' => ['source' => 'field', 'field' => 'discstart', 'dateOffsetDays' => 10]],
            $this->contactWithStart('2026-10-21 14:30:00', 'datetime'),
            new Campaign()
        );

        $this->assertSame(['avaliacao' => '31/10/2026 14:30:00'], $resolved);
    }

    public function testDaysAreNotAddedToAFieldThatIsNotADate(): void
    {
        $resolved = $this->resolver->resolveAll(
            ['nome' => ['source' => 'field', 'field' => 'discstart', 'dateOffsetDays' => 10]],
            $this->contactWithStart('Wellington', 'text'),
            new Campaign()
        );

        $this->assertSame(['nome' => 'Wellington'], $resolved);
    }

    public function testStaticValuesNeverGetDays(): void
    {
        $resolved = $this->resolver->resolveAll(['x' => ['source' => 'static', 'value' => '2026-10-21', 'dateOffsetDays' => 10]], new Lead(), new Campaign());

        $this->assertSame(['x' => '2026-10-21'], $resolved);
    }

    public function testACustomObjectEntryPassesTheDaysToTheCustomObjectResolver(): void
    {
        $contact  = new Lead();
        $campaign = new Campaign();

        $this->customObjectVariableResolver->expects($this->once())
            ->method('resolve')
            ->with($contact, $campaign, 'disciplines', 'discstart', '<br>', 'pt_BR', 10)
            ->willReturn('31/10/2026');

        $resolved = $this->resolver->resolveAll(
            ['avaliacao' => ['source' => 'custom_object', 'customObject' => 'disciplines', 'customObjectField' => 'discstart', 'dateOffsetDays' => '10']],
            $contact,
            $campaign
        );

        $this->assertSame(['avaliacao' => '31/10/2026'], $resolved);
    }

    public function testACustomObjectEntryWithoutDaysPassesZero(): void
    {
        $contact  = new Lead();
        $campaign = new Campaign();

        $this->customObjectVariableResolver->expects($this->once())
            ->method('resolve')
            ->with($contact, $campaign, 'disciplines', 'discstart', '<br>', 'pt_BR', 0)
            ->willReturn('21/10/2026');

        $this->resolver->resolveAll(['inicio' => ['source' => 'custom_object', 'customObject' => 'disciplines', 'customObjectField' => 'discstart']], $contact, $campaign);
    }

    public function testResolveContactFieldAddsNothingByDefaultAndTheGivenDaysWhenAsked(): void
    {
        $contact = $this->contactWithStart();

        // the callers that read a fixed field straight into the payload (inst_id_*) never pass days
        $this->assertSame('21/10/2026', $this->resolver->resolveContactField($contact, 'discstart'));
        $this->assertSame('21/10/2026', $this->resolver->resolveContactField($contact, 'discstart', 'pt_BR'));
        $this->assertSame('26/10/2026', $this->resolver->resolveContactField($contact, 'discstart', 'pt_BR', 5));
        $this->assertSame('16/10/2026', $this->resolver->resolveContactField($contact, 'discstart', 'pt_BR', -5));
    }

    public function testAddDaysReadsOnlyWholeNumbersAndCutsAtTenYears(): void
    {
        $this->assertSame(0, VariableResolver::addDays([]));
        $this->assertSame(3650, VariableResolver::addDays(['dateOffsetDays' => 3650]));
        $this->assertSame(3650, VariableResolver::addDays(['dateOffsetDays' => 3651]));
        $this->assertSame(3649, VariableResolver::addDays(['dateOffsetDays' => 3649]));
        $this->assertSame(-3650, VariableResolver::addDays(['dateOffsetDays' => -3651]));
        $this->assertSame(-3649, VariableResolver::addDays(['dateOffsetDays' => '-3649']));
        $this->assertSame(0, VariableResolver::addDays(['dateOffsetDays' => '1e3']));
        $this->assertSame(0, VariableResolver::addDays(['dateOffsetDays' => 1.5]));
    }
}
