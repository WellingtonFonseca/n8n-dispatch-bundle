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
            ->with($contact, $campaign, 'disciplines', 'discname')
            ->willReturn('disciplina 1<br>disciplina 2');

        $resolved = $this->resolver->resolveAll(
            ['disciplina' => ['source' => 'custom_object', 'customObject' => 'disciplines', 'customObjectField' => 'discname']],
            $contact,
            $campaign
        );

        $this->assertSame(['disciplina' => 'disciplina 1<br>disciplina 2'], $resolved);
    }
}
