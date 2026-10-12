<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\CampaignBundle\Entity\Event;
use MauticPlugin\N8nDispatchBundle\Service\EventNameSync;
use MauticPlugin\N8nDispatchBundle\Twig\EventTemplateExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class EventNameSyncTest extends TestCase
{
    private const LABELS = [
        'mautic.n8ndispatch.campaign.event.email.send' => ['en_US' => 'Send via n8n (Email)', 'pt_BR' => 'Enviar pelo n8n (Email)'],
        'mautic.n8ndispatch.campaign.event.sms.send'   => ['en_US' => 'Send via n8n (SMS)', 'pt_BR' => 'Enviar pelo n8n (SMS)'],
        'mautic.n8ndispatch.campaign.event.hsm.send'   => ['en_US' => 'Send via n8n (HSM)', 'pt_BR' => 'Enviar pelo n8n (HSM)'],
    ];

    /**
     * @param array<int, string|null> $templates template name by the id in the properties' 'email' key
     */
    private function sync(array $templates = [7 => 'Entrega de Trabalho, E 01', 8 => 'Entrega de Trabalho, E 02']): EventNameSync
    {
        $extension = $this->createMock(EventTemplateExtension::class);
        $extension->method('templateName')->willReturnCallback(
            static fn ($type, $properties) => is_array($properties) ? ($templates[(int) ($properties['email'] ?? 0)] ?? null) : null
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $p = [], ?string $d = null, ?string $locale = null) => self::LABELS[$id][$locale] ?? $id);

        return new EventNameSync($extension, $translator);
    }

    private function event(string $name, int $emailId = 7, string $type = 'n8ndispatch.email.send'): Event
    {
        $event = new Event();
        $event->setType($type);
        $event->setName($name);
        $event->setProperties(['email' => $emailId, 'status' => 'test']);

        return $event;
    }

    /**
     * @dataProvider replaceableNames
     */
    public function testReplacesABlankOrDefaultName(string $name): void
    {
        $event = $this->event($name);

        $this->assertTrue($this->sync()->sync($event));
        $this->assertSame('Entrega de Trabalho, E 01', $event->getName());
    }

    public static function replaceableNames(): array
    {
        return [
            'blank'          => [''],
            'spaces'         => ['   '],
            'english type'   => ['Send via n8n (Email)'],
            'portuguese type' => ['Enviar pelo n8n (Email)'],
        ];
    }

    public function testATypedNameIsKept(): void
    {
        $event = $this->event('Meu envio de entrega');

        $this->assertFalse($this->sync()->sync($event));
        $this->assertSame('Meu envio de entrega', $event->getName());
    }

    public function testChangingTheTemplateRenamesAnEventNamedAfterTheOldOne(): void
    {
        $event = $this->event('Entrega de Trabalho, E 01', 8);

        $this->assertTrue($this->sync()->sync($event, ['email' => 7]));
        $this->assertSame('Entrega de Trabalho, E 02', $event->getName());
    }

    public function testChangingTheTemplateKeepsATypedName(): void
    {
        $event = $this->event('Meu envio', 8);

        $this->assertFalse($this->sync()->sync($event, ['email' => 7]));
        $this->assertSame('Meu envio', $event->getName());
    }

    public function testNothingChangesWhenTheNameIsAlreadyTheTemplates(): void
    {
        $this->assertFalse($this->sync()->sync($this->event('Entrega de Trabalho, E 01')));
    }

    public function testNoTemplatePickedOrGoneLeavesTheName(): void
    {
        $event = $this->event('', 99);

        $this->assertFalse($this->sync()->sync($event));
        $this->assertSame('', $event->getName());
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
