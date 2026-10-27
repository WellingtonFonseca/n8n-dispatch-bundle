<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use MauticPlugin\N8nDispatchBundle\EventListener\TemplateRenameSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\HsmTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\SmsTemplateUsageFinder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TemplateRenameSubscriberTest extends TestCase
{
    /** @var EmailTemplateUsageFinder&MockObject */
    private $emails;

    /** @var SmsTemplateUsageFinder&MockObject */
    private $sms;

    /** @var HsmTemplateUsageFinder&MockObject */
    private $hsm;

    /** @var Connection&MockObject */
    private $connection;

    private TemplateRenameSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->emails     = $this->createMock(EmailTemplateUsageFinder::class);
        $this->sms        = $this->createMock(SmsTemplateUsageFinder::class);
        $this->hsm        = $this->createMock(HsmTemplateUsageFinder::class);
        $this->connection = $this->createMock(Connection::class);
        $metadata         = $this->createMock(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('mt_campaign_events');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);
        $em->method('getClassMetadata')->with(Event::class)->willReturn($metadata);

        $this->subscriber = new TemplateRenameSubscriber($this->emails, $this->sms, $this->hsm, $em);
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    private function args(object $entity, array $changes): PreUpdateEventArgs
    {
        $args = $this->createMock(PreUpdateEventArgs::class);
        $args->method('getObject')->willReturn($entity);
        $args->method('hasChangedField')->willReturnCallback(static fn (string $f) => isset($changes[$f]));
        $args->method('getNewValue')->willReturnCallback(static fn (string $f) => $changes[$f][1]);

        return $args;
    }

    private function usage(int ...$eventIds): array
    {
        return array_map(static fn (int $id) => ['eventId' => $id], $eventIds);
    }

    private function email(int $id): Email
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn($id);

        return $email;
    }

    public function testListensToPreUpdate(): void
    {
        $attributes = (new \ReflectionClass(TemplateRenameSubscriber::class))->getAttributes(AsDoctrineListener::class);

        $this->assertCount(1, $attributes);
        $this->assertSame(Events::preUpdate, $attributes[0]->newInstance()->event);
    }

    public function testRenamingAnEmailRenamesTheActionsThatSendIt(): void
    {
        $this->emails->expects($this->once())->method('findUsages')->with(46)->willReturn($this->usage(99, 105));
        $this->sms->expects($this->never())->method('findUsages');
        $this->hsm->expects($this->never())->method('findUsages');
        $this->connection->expects($this->once())->method('executeStatement')
            ->with('UPDATE mt_campaign_events SET name = ? WHERE id IN (99,105)', ['Novo, E 05']);

        $this->subscriber->preUpdate($this->args($this->email(46), ['name' => ['Antigo', 'Novo, E 05']]));
    }

    public function testRenamingAnSmsTemplateUsesTheSmsFinder(): void
    {
        $template = $this->createMock(SmsTemplate::class);
        $template->method('getId')->willReturn(3);
        $this->sms->expects($this->once())->method('findUsages')->with(3)->willReturn($this->usage(7));
        $this->emails->expects($this->never())->method('findUsages');
        $this->connection->expects($this->once())->method('executeStatement')->with($this->stringContains('IN (7)'), ['SMS novo']);

        $this->subscriber->preUpdate($this->args($template, ['name' => ['SMS', 'SMS novo']]));
    }

    public function testRenamingAnHsmTemplateUsesTheHsmFinder(): void
    {
        $template = $this->createMock(HsmTemplate::class);
        $template->method('getId')->willReturn(4);
        $this->hsm->expects($this->once())->method('findUsages')->with(4)->willReturn($this->usage(8));
        $this->connection->expects($this->once())->method('executeStatement')->with($this->stringContains('IN (8)'), ['HSM novo']);

        $this->subscriber->preUpdate($this->args($template, ['name' => ['HSM', 'HSM novo']]));
    }

    public function testAnotherFieldChangingDoesNothing(): void
    {
        $this->emails->expects($this->never())->method('findUsages');
        $this->connection->expects($this->never())->method('executeStatement');

        $this->subscriber->preUpdate($this->args($this->email(46), ['subject' => ['a', 'b']]));
    }

    public function testAnotherEntityDoesNothing(): void
    {
        $this->emails->expects($this->never())->method('findUsages');
        $this->connection->expects($this->never())->method('executeStatement');

        $this->subscriber->preUpdate($this->args(new \stdClass(), ['name' => ['a', 'b']]));
    }

    public function testATemplateNoActionUsesWritesNothing(): void
    {
        $this->emails->method('findUsages')->willReturn([]);
        $this->connection->expects($this->never())->method('executeStatement');

        $this->subscriber->preUpdate($this->args($this->email(46), ['name' => ['a', 'b']]));
    }

    public function testABlankNewNameWritesNothing(): void
    {
        $this->emails->expects($this->never())->method('findUsages');
        $this->connection->expects($this->never())->method('executeStatement');

        $this->subscriber->preUpdate($this->args($this->email(46), ['name' => ['a', '']]));
    }

    public function testALongNameIsCutToTheColumnSize(): void
    {
        $this->emails->method('findUsages')->willReturn($this->usage(1));
        $this->connection->expects($this->once())->method('executeStatement')
            ->with($this->anything(), [str_repeat('á', 255)]);

        $this->subscriber->preUpdate($this->args($this->email(46), ['name' => ['a', str_repeat('á', 300)]]));
    }
}
