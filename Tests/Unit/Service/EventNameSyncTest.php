<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\CampaignBundle\Entity\Event;
use MauticPlugin\N8nDispatchBundle\Service\EventNameSync;
use MauticPlugin\N8nDispatchBundle\Twig\EventTemplateExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EventNameSyncTest extends TestCase
{
    /**
     * @param array<int, string|null> $templates template name by the id in the properties' 'email' key
     */
    private function sync(array $templates = [7 => 'Entrega de Trabalho, E 01', 8 => 'Entrega de Trabalho, E 02']): EventNameSync
    {
        $extension = $this->createMock(EventTemplateExtension::class);
        $extension->method('templateName')->willReturnCallback(
            static fn ($type, $properties) => is_array($properties) ? ($templates[(int) ($properties['email'] ?? 0)] ?? null) : null
        );

        return new EventNameSync($extension);
    }

    private function event(string $name, int $emailId = 7, string $type = 'n8ndispatch.email.send'): Event
    {
        $event = new Event();
        $event->setType($type);
        $event->setName($name);
        $event->setProperties(['email' => $emailId, 'status' => 'test']);

        return $event;
    }

    #[DataProvider('anyName')]
    public function testTheNameIsAlwaysTheTemplates(string $name): void
    {
        $event = $this->event($name);

        $this->assertTrue($this->sync()->sync($event));
        $this->assertSame('Entrega de Trabalho, E 01', $event->getName());
    }

    public static function anyName(): array
    {
        return [
            'blank'                          => [''],
            'spaces'                         => ['   '],
            'english type'                   => ['Send via n8n (Email)'],
            'portuguese type'                => ['Enviar pelo n8n (Email)'],
            'typed'                          => ['Meu envio de entrega'],
            'another template'               => ['Entrega de Trabalho, E 02'],
            'old name of a renamed template' => ['Entrega de Trabalho (antigo), E 01'],
        ];
    }

    public function testACopiedNodeWithAnotherTemplateTakesTheNewTemplatesName(): void
    {
        $event = $this->event('Entrega de Trabalho, E 01', 8);

        $this->assertTrue($this->sync()->sync($event));
        $this->assertSame('Entrega de Trabalho, E 02', $event->getName());
    }

    public function testNothingChangesWhenTheNameIsAlreadyTheTemplates(): void
    {
        $this->assertFalse($this->sync()->sync($this->event('Entrega de Trabalho, E 01')));
    }

    public function testPaddingAroundTheTemplateNameIsCleaned(): void
    {
        $event = $this->event(' Entrega de Trabalho, E 01 ');

        $this->assertTrue($this->sync()->sync($event));
        $this->assertSame('Entrega de Trabalho, E 01', $event->getName());
    }

    public function testAnEventWithoutATypeIsIgnored(): void
    {
        $this->assertFalse($this->sync()->sync(new Event()));
    }

    public function testNoTemplatePickedOrGoneLeavesTheName(): void
    {
        $event = $this->event('Meu envio', 99);

        $this->assertFalse($this->sync()->sync($event));
        $this->assertSame('Meu envio', $event->getName());
    }

    public function testOtherActionTypesAreIgnored(): void
    {
        $event = $this->event('', 7, 'email.send');

        $this->assertFalse($this->sync()->sync($event));
        $this->assertFalse(EventNameSync::handles('email.send'));
        $this->assertTrue(EventNameSync::handles('n8ndispatch.hsm.send'));
    }

    public function testALongTemplateNameIsCutToTheColumnSize(): void
    {
        $long  = str_repeat('á', 300);
        $event = $this->event('', 7);

        $this->sync([7 => $long])->sync($event);

        $this->assertSame(255, mb_strlen((string) $event->getName()));
    }
}
