<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Twig;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel;
use MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel;
use MauticPlugin\N8nDispatchBundle\Twig\EventTemplateExtension;
use PHPUnit\Framework\TestCase;

class EventTemplateExtensionTest extends TestCase
{
    private function extension(?object $email = null, ?object $sms = null, ?object $hsm = null): EventTemplateExtension
    {
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($email);
        $smsModel = $this->createMock(SmsTemplateModel::class);
        $smsModel->method('getEntity')->willReturn($sms);
        $hsmModel = $this->createMock(HsmTemplateModel::class);
        $hsmModel->method('getEntity')->willReturn($hsm);

        return new EventTemplateExtension($emailModel, $smsModel, $hsmModel);
    }

    public function testEmailName(): void
    {
        $email = (new Email())->setName('Welcome email');

        self::assertSame('Welcome email', $this->extension($email)->templateName('n8ndispatch.email.send', ['email' => 7]));
    }

    public function testSmsName(): void
    {
        $sms = (new SmsTemplate())->setName('Reminder SMS');

        self::assertSame('Reminder SMS', $this->extension(null, $sms)->templateName('n8ndispatch.sms.send', ['smsTemplate' => 3]));
    }

    public function testHsmName(): void
    {
        $hsm = (new HsmTemplate())->setName('Hello HSM');

        self::assertSame('Hello HSM', $this->extension(null, null, $hsm)->templateName('n8ndispatch.hsm.send', ['hsmTemplateId' => 2]));
    }

    public function testNullWhenNothingPickedOrMissing(): void
    {
        $ext = $this->extension();

        self::assertNull($ext->templateName('n8ndispatch.email.send', []));
        self::assertNull($ext->templateName('n8ndispatch.email.send', ['email' => 9]));
        self::assertNull($ext->templateName('other.type', ['email' => 9]));
        self::assertNull($ext->templateName('n8ndispatch.email.send', null));
    }
}
