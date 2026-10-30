<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\CategoryBundle\Entity\Category;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Entity\StatRepository;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\N8nDispatchBundle\Service\DirectEmailSender;
use MauticPlugin\N8nDispatchBundle\Service\EmailSendMode;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener\EnUsTranslator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DirectEmailSenderTest extends TestCase
{
    private EmailSendMode&MockObject $mode;
    private EmailModel&MockObject $emailModel;
    private LeadModel&MockObject $leadModel;
    private StatRepository&MockObject $stats;
    private LoggerInterface&MockObject $logger;
    private DirectEmailSender $sender;

    protected function setUp(): void
    {
        $this->mode       = $this->createMock(EmailSendMode::class);
        $this->emailModel = $this->createMock(EmailModel::class);
        $this->leadModel  = $this->createMock(LeadModel::class);
        $this->stats      = $this->createMock(StatRepository::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->emailModel->method('getStatRepository')->willReturn($this->stats);
        $this->leadModel->method('getUnsubscribedLeadCategoriesIds')->willReturn([]);

        $this->sender = new DirectEmailSender(
            $this->mode,
            $this->emailModel,
            $this->leadModel,
            new DispatchFailureReasons(new EnUsTranslator()),
            new EnUsTranslator(),
            $this->logger,
        );
    }

    private function email(?int $categoryId = null): Email
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn(55);
        if (null !== $categoryId) {
            $category = $this->createMock(Category::class);
            $category->method('getId')->willReturn($categoryId);
            $email->method('getCategory')->willReturn($category);
        }

        return $email;
    }

    private function contact(?string $address = 'ana@example.test', int $id = 9): Lead
    {
        $contact = new Lead();
        (new \ReflectionProperty($contact, 'id'))->setValue($contact, $id);
        if (null !== $address) {
            $contact->setFields(['core' => ['email' => ['alias' => 'email', 'value' => $address]]]);
            $contact->setEmail($address);
        }

        return $contact;
    }

    public function testItIsOnWhenTheSendModeIsDirect(): void
    {
        $this->mode->method('isDirect')->willReturnOnConsecutiveCalls(true, false);

        $this->assertTrue($this->sender->isOn());
        $this->assertFalse($this->sender->isOn());
    }

    public function testTheEmailGoesThroughMauticsMailerAsMarketingFromTheCampaignStep(): void
    {
        $email   = $this->email();
        $contact = $this->contact();

        $this->emailModel->expects($this->once())->method('sendEmail')
            ->with(
                $email,
                $this->callback(fn (array $leads): bool => [9] === array_keys($leads) && 9 === $leads[9]['id'] && 'ana@example.test' === $leads[9]['email']),
                $this->callback(function (array $options): bool {
                    $this->assertSame(['campaign.event', 77], $options['source']);
                    $this->assertSame('marketing', $options['email_type']);
                    $this->assertTrue($options['return_errors']);
                    $this->assertTrue($options['dnc_as_error']);
                    $this->assertFalse($options['ignoreDNC']);
                    $this->assertSame(['X-EMAIL-ID' => 55], $options['customHeaders']);
                    $this->assertSame(3, $options['email_attempts']);
                    $this->assertSame(2, $options['email_priority']);

                    return true;
                })
            )
            ->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->sender->send($contact, $email, 77, []);
    }

    public function testTheVariablesAreTheTokensOfTheMailerAndTheUnsubscribeIsMautics(): void
    {
        $this->emailModel->expects($this->once())->method('sendEmail')
            ->with($this->anything(), $this->anything(), $this->callback(function (array $options): bool {
                $this->assertSame(
                    ['{{n8n_nome}}' => 'Ana', '{{n8n_total}}' => '10', '{{n8n_vazio}}' => '', '{{n8ndispatch_unsubscribe_url}}' => '{unsubscribe_url}'],
                    $options['tokens']
                );
                $this->assertSame(['{{n8n_nome}}', '{{n8n_total}}', '{{n8n_vazio}}', '{{n8ndispatch_unsubscribe_url}}'], array_keys($options['tokens']), 'in this order: the placeholder resolves before core fills {unsubscribe_url}');

                return true;
            }))
            ->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->sender->send($this->contact(), $this->email(), 77, ['n8n_nome' => 'Ana', 'n8n_total' => 10, 'n8n_vazio' => null]);
    }

    public function testTheContactFieldsAreHydratedWhenTheContactCameWithoutThem(): void
    {
        $contact = $this->contact();
        $contact->setFields([]);
        $repository = $this->createMock(LeadRepository::class);
        $repository->expects($this->once())->method('getFieldValues')->with(9)
            ->willReturn(['core' => ['firstname' => ['alias' => 'firstname', 'value' => 'Ana'], 'lastname' => ['alias' => 'lastname', 'value' => 'Silva'], 'email' => ['alias' => 'email', 'value' => 'ana@example.test']]]);
        $this->leadModel->method('getRepository')->willReturn($repository);

        $this->emailModel->expects($this->once())->method('sendEmail')
            ->with($this->anything(), $this->callback(function (array $leads): bool {
                $this->assertSame('Ana', $leads[9]['firstname']);
                $this->assertSame('Silva', $leads[9]['lastname']);

                return true;
            }), $this->anything())
            ->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->sender->send($contact, $this->email(), 77, []);
    }

    public function testTheAddressOfTheFieldsWinsOverTheEntitys(): void
    {
        $contact = $this->contact('fields@example.test');
        $contact->setEmail('entity@example.test');
        $this->emailModel->expects($this->once())->method('sendEmail')
            ->with($this->anything(), $this->callback(fn (array $leads): bool => 'fields@example.test' === $leads[9]['email']), $this->anything())
            ->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->sender->send($contact, $this->email(), 77, []);
    }

    public function testTheContactsOwnAddressIsTheFallbackWhenTheFieldsHaveNone(): void
    {
        $repository = $this->createMock(LeadRepository::class);
        $repository->method('getFieldValues')->willReturn([]);
        $this->leadModel->method('getRepository')->willReturn($repository);
        $contact = new Lead();
        (new \ReflectionProperty($contact, 'id'))->setValue($contact, 9);
        $contact->setEmail('entity@example.test');
        $this->emailModel->expects($this->once())->method('sendEmail')
            ->with($this->anything(), $this->callback(fn (array $leads): bool => 'entity@example.test' === $leads[9]['email']), $this->anything())
            ->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->sender->send($contact, $this->email(), 77, []);
    }

    public function testTheMailerAlwaysGetsTheNameKeysItReads(): void
    {
        $this->emailModel->expects($this->once())->method('sendEmail')
            ->with($this->anything(), $this->callback(function (array $leads): bool {
                $this->assertSame('', $leads[9]['firstname']);
                $this->assertSame('', $leads[9]['lastname']);

                return true;
            }), $this->anything())
            ->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->sender->send($this->contact(), $this->email(), 77, []);
    }

    public function testAContactWithoutAnEmailAddressIsSkippedAndNothingIsSent(): void
    {
        $repository = $this->createMock(LeadRepository::class);
        $repository->method('getFieldValues')->willReturn([]);
        $this->leadModel->method('getRepository')->willReturn($repository);
        $this->emailModel->expects($this->never())->method('sendEmail');

        $result = $this->sender->send($this->contact(null), $this->email(), 77, []);

        $this->assertSame(DirectEmailSender::SKIPPED, $result['outcome']);
        $this->assertSame('N8nDispatch: the contact has no email address.', $result['reason']);
    }

    public function testAContactWhoUnsubscribedFromTheCategoryOfTheEmailIsSkipped(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getUnsubscribedLeadCategoriesIds')->willReturn([3, 8]);
        $sender = new DirectEmailSender($this->mode, $this->emailModel, $leadModel, new DispatchFailureReasons(new EnUsTranslator()), new EnUsTranslator(), $this->logger);
        $this->emailModel->expects($this->never())->method('sendEmail');

        $result = $sender->send($this->contact(), $this->email(8), 77, []);

        $this->assertSame(DirectEmailSender::SKIPPED, $result['outcome']);
        $this->assertSame('N8nDispatch: the contact unsubscribed from the category of this Email.', $result['reason']);
    }

    public function testACategoryTheContactDidNotLeaveDoesNotStopTheSend(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getUnsubscribedLeadCategoriesIds')->willReturn([3, 8]);
        $sender = new DirectEmailSender($this->mode, $this->emailModel, $leadModel, new DispatchFailureReasons(new EnUsTranslator()), new EnUsTranslator(), $this->logger);
        $this->emailModel->expects($this->once())->method('sendEmail')->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->assertSame(DirectEmailSender::QUEUED, $sender->send($this->contact(), $this->email(4), 77, [])['outcome']);
    }

    public function testTheMailersRefusalIsAFailureWithItsReason(): void
    {
        $this->emailModel->method('sendEmail')->willReturn([9 => '550 mailbox unavailable']);

        $result = $this->sender->send($this->contact(), $this->email(), 77, []);

        $this->assertSame(DirectEmailSender::FAILED, $result['outcome']);
        $this->assertSame('N8nDispatch: 550 mailbox unavailable', $result['reason']);
        $this->assertNull($result['statId']);
    }

    public function testTheRefusalOfAnotherContactIsNotThisContactsFailure(): void
    {
        $this->emailModel->method('sendEmail')->willReturn([123 => 'boom']);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->assertSame(DirectEmailSender::QUEUED, $this->sender->send($this->contact(), $this->email(), 77, [])['outcome']);
    }

    public function testADoNotContactAnswerOfTheMailerIsSkippedNotRetried(): void
    {
        $this->emailModel->method('sendEmail')->willReturn([9 => 'mautic.email.dnc']);

        $result = $this->sender->send($this->contact(), $this->email(), 77, []);

        $this->assertSame(DirectEmailSender::SKIPPED, $result['outcome']);
        $this->assertSame('N8nDispatch: mautic.email.dnc', $result['reason']);
    }

    public function testAnExceptionOfTheMailerIsAFailureAndIsLogged(): void
    {
        $this->emailModel->method('sendEmail')->willThrowException(new \RuntimeException('connection refused'));
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: direct send failed for contact 9: connection refused');

        $result = $this->sender->send($this->contact(), $this->email(), 77, []);

        $this->assertSame(DirectEmailSender::FAILED, $result['outcome']);
        $this->assertSame('N8nDispatch: connection refused', $result['reason']);
    }

    public function testTheStatTheMailerMadeIsFoundByContactAndStep(): void
    {
        $stat = $this->createMock(Stat::class);
        $stat->method('getId')->willReturn(4321);
        $this->emailModel->method('sendEmail')->willReturn([]);
        $this->stats->expects($this->exactly(2))->method('findOneBy')
            ->with(['lead' => 9, 'source' => 'campaign.event', 'sourceId' => 77], ['id' => 'DESC'])
            ->willReturnOnConsecutiveCalls(null, $stat);

        $result = $this->sender->send($this->contact(), $this->email(), 77, []);

        $this->assertSame(DirectEmailSender::SENT, $result['outcome']);
        $this->assertSame(4321, $result['statId']);
        $this->assertNull($result['reason']);
    }

    public function testAContactWhoCameBackGetsTheNewStatNotTheOldOne(): void
    {
        $old = $this->createMock(Stat::class);
        $old->method('getId')->willReturn(100);
        $new = $this->createMock(Stat::class);
        $new->method('getId')->willReturn(101);
        $this->emailModel->method('sendEmail')->willReturn([]);
        $this->stats->method('findOneBy')->willReturnOnConsecutiveCalls($old, $new);

        $result = $this->sender->send($this->contact(), $this->email(), 77, []);

        $this->assertSame(DirectEmailSender::SENT, $result['outcome']);
        $this->assertSame(101, $result['statId']);
    }

    public function testAStatThatWasThereBeforeThisSendIsNotThisSends(): void
    {
        $stat = $this->createMock(Stat::class);
        $stat->method('getId')->willReturn(4321);
        $this->emailModel->method('sendEmail')->willReturn([]);
        $this->stats->method('findOneBy')->willReturn($stat);

        $result = $this->sender->send($this->contact(), $this->email(), 77, []);

        $this->assertSame(DirectEmailSender::QUEUED, $result['outcome']);
        $this->assertNull($result['statId']);
    }

    public function testNoStatMeansTheMailerHeldTheEmailBackInItsQueue(): void
    {
        $this->emailModel->method('sendEmail')->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $result = $this->sender->send($this->contact(), $this->email(), 77, []);

        $this->assertSame(DirectEmailSender::QUEUED, $result['outcome']);
        $this->assertNull($result['statId']);
        $this->assertNull($result['reason']);
    }

    public function testTheOwnerOfTheContactIsPassedForTheOwnerIsMailerFeature(): void
    {
        $contact = $this->contact();
        $owner   = $this->createMock(\Mautic\UserBundle\Entity\User::class);
        $owner->method('getId')->willReturn(12);
        $contact->setOwner($owner);

        $this->emailModel->expects($this->once())->method('sendEmail')
            ->with($this->anything(), $this->callback(function (array $leads): bool {
                $this->assertSame(12, $leads[9]['owner_id']);
                $this->assertSame('ana@example.test', $leads[9]['primaryIdentifier']);

                return true;
            }), $this->anything())
            ->willReturn([]);
        $this->stats->method('findOneBy')->willReturn(null);

        $this->sender->send($contact, $this->email(), 77, []);
    }
}
