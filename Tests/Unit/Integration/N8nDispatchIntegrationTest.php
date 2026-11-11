<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Integration;

use MauticPlugin\N8nDispatchBundle\Form\Type\VisiblePasswordType;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

class N8nDispatchIntegrationTest extends TestCase
{
    private function integration(?\MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntryRepository $alertLog = null, ?\Psr\Log\LoggerInterface $logger = null): N8nDispatchIntegration
    {
        // the constructor takes a fixed list of core services, not needed for the form
        $integration = (new \ReflectionClass(N8nDispatchIntegration::class))->newInstanceWithoutConstructor();
        $translator  = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        (new \ReflectionProperty(\Mautic\PluginBundle\Integration\AbstractIntegration::class, 'translator'))->setValue($integration, $translator);
        $em = $this->createStub(\Doctrine\ORM\EntityManager::class);
        $em->method('getRepository')->willReturn($alertLog ?? $this->createStub(\MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntryRepository::class));
        (new \ReflectionProperty(\Mautic\PluginBundle\Integration\AbstractIntegration::class, 'em'))->setValue($integration, $em);
        (new \ReflectionProperty(\Mautic\PluginBundle\Integration\AbstractIntegration::class, 'logger'))->setValue($integration, $logger ?? $this->createStub(\Psr\Log\LoggerInterface::class));

        return $integration;
    }

    private function factory(): \Symfony\Component\Form\FormFactoryInterface
    {
        // with the validator extension, as in the app (it is what gives a field its "constraints" option)
        return Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension(Validation::createValidator()))->getFormFactory();
    }

    /**
     * The keys form as core builds it: a text field per key, then appendToForm('keys').
     *
     * @param array<string, mixed> $saved
     */
    private function keysForm(array $saved): \Symfony\Component\Form\FormInterface
    {
        $builder = $this->factory()->createBuilder(FormType::class);
        $builder->add('webhook_url', TextType::class);
        $builder->add('webhook_token', TextType::class);

        $this->integration()->appendToForm($builder, $saved, 'keys');

        return $builder->getForm();
    }

    public function testTheChangeLogIsTheCustomNoteOfTheFirstTab(): void
    {
        $note = $this->integration()->getFormNotes('custom');

        $this->assertSame('@N8nDispatch/Integration/_notes.html.twig', $note['template']);
        $this->assertTrue($note['custom'], 'core only draws the note when custom is set');
        $this->assertNotEmpty($note['parameters']['days']);
    }

    public function testTheNoteCarriesTheLatestAlertsOfTheLog(): void
    {
        $rows = [['created_at' => '2026-11-11 10:00:00', 'alert' => 'campaign.unpublished', 'outcome' => 'sent', 'reason' => null, 'detail' => null, 'subject' => 'A']];
        $log  = $this->createMock(\MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntryRepository::class);
        $log->expects($this->once())->method('latest')->with(50)->willReturn($rows);

        $this->assertSame($rows, $this->integration($log)->getFormNotes('custom')['parameters']['alertLog']);
    }

    public function testAnUnreadableLogIsLoggedAndTheNoteStillOpens(): void
    {
        $log = $this->createStub(\MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntryRepository::class);
        $log->method('latest')->willThrowException(new \RuntimeException('no such table'));
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('N8nDispatch: the alert log could not be read: no such table');

        $this->assertSame([], $this->integration($log, $logger)->getFormNotes('custom')['parameters']['alertLog']);
    }

    public function testTheAuthTokenIsAPasswordInput(): void
    {
        $form = $this->keysForm([]);

        $this->assertInstanceOf(VisiblePasswordType::class, $form->get('webhook_token')->getConfig()->getType()->getInnerType());
        $this->assertSame('password', $form->createView()['webhook_token']->vars['type'], 'drawn as <input type="password">');
    }

    public function testTheSavedTokenStaysInTheFieldSoItCanBeCheckedLater(): void
    {
        $view = $this->keysForm(['webhook_token' => 's3cret-token'])->createView();

        $this->assertSame('s3cret-token', $view['webhook_token']->vars['value']);
        $this->assertSame('password', $view['webhook_token']->vars['type']);
    }

    public function testTheWebhookUrlIsLeftAsPlainText(): void
    {
        $form = $this->keysForm(['webhook_url' => 'https://n8n.example.test/hook']);

        $this->assertInstanceOf(TextType::class, $form->get('webhook_url')->getConfig()->getType()->getInnerType());
    }

    public function testNothingHappensForTheOtherFormAreas(): void
    {
        $builder = $this->factory()->createBuilder(FormType::class);
        $builder->add('webhook_token', TextType::class);

        $this->integration()->appendToForm($builder, [], 'integration');

        $this->assertInstanceOf(TextType::class, $builder->get('webhook_token')->getType()->getInnerType());
    }

    public function testBothKeysAreStillRequired(): void
    {
        $this->assertSame(['webhook_url', 'webhook_token'], array_keys($this->integration()->getRequiredKeyFields()));
    }

    public function testTheTokenIsRequiredOnlyWhileTheIntegrationIsPublished(): void
    {
        $builder = $this->factory()->createBuilder(FormType::class);
        $builder->add('isPublished', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class);
        $builder->add('webhook_token', TextType::class);
        $this->integration()->appendToForm($builder, [], 'keys');

        $published = $builder->getForm();
        $published->submit(['isPublished' => '1', 'webhook_token' => '']);
        $this->assertFalse($published->isValid(), 'empty token while published');

        $draft = $this->factory()->createBuilder(FormType::class);
        $draft->add('isPublished', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class);
        $draft->add('webhook_token', TextType::class);
        $this->integration()->appendToForm($draft, [], 'keys');
        $form = $draft->getForm();
        $form->submit(['webhook_token' => '']);
        $this->assertTrue($form->isValid(), 'empty token while not published');

        $filled = $this->factory()->createBuilder(FormType::class);
        $filled->add('isPublished', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class);
        $filled->add('webhook_token', TextType::class);
        $this->integration()->appendToForm($filled, [], 'keys');
        $ok = $filled->getForm();
        $ok->submit(['isPublished' => '1', 'webhook_token' => 'abc']);
        $this->assertTrue($ok->isValid());
    }

    public function testTheTokenHasAnEyeToShowAndHideIt(): void
    {
        $attr = $this->keysForm(['webhook_token' => 'abc'])->createView()['webhook_token']->vars['attr'];

        $this->assertSame('ri-eye-line', $attr['postaddon']);
        $this->assertStringContainsString("i.type=s?'text':'password'", $attr['postaddon_attr']['onclick']);
        $this->assertStringNotContainsString('"', $attr['postaddon_attr']['onclick'], 'single quotes only, it sits inside an attribute');
    }

    private function sendModeField(array $saved): \Symfony\Component\Form\FormInterface
    {
        return $this->keysForm($saved)->get('email_send_mode');
    }

    public function testTheKeysTabOffersTheEmailSendModeWithN8nAsTheDefault(): void
    {
        $field = $this->sendModeField([]);

        $this->assertSame('n8n', $field->getData());
        $this->assertSame(['n8n', 'mautic'], array_values($field->getConfig()->getOption('choices')));
    }

    public function testTheSavedEmailSendModeIsTheOneShown(): void
    {
        $this->assertSame('mautic', $this->sendModeField(['email_send_mode' => 'mautic'])->getData());
        $this->assertSame('n8n', $this->sendModeField(['email_send_mode' => 'garbage'])->getData());
    }

    public function testTheEmailSendModeIsRightUnderTheToken(): void
    {
        $this->assertSame(['webhook_url', 'webhook_token', 'email_send_mode'], array_keys($this->keysForm([])->all()));
    }

    public function testTheEmailSendModeIsNotOnTheFeaturesTab(): void
    {
        $builder = $this->factory()->createBuilder(FormType::class);
        $this->integration()->appendToForm($builder, [], 'features');

        $this->assertFalse($builder->getForm()->has('email_send_mode'));
    }

    public function testTheLastRunFieldAsksToBeSwappedForAPreBlock(): void
    {
        $builder = $this->factory()->createBuilder(FormType::class);
        $this->integration()->appendToForm($builder, [], 'features');

        $attr = $builder->getForm()->createView()['status_poll_last_run']->vars['attr'];

        $this->assertSame('n8ndispatchBlockFromTextarea', $attr['data-onload-callback']);
        $this->assertStringContainsString('n8ndispatch-json-block', $attr['class']);
    }

    public function testTheFeaturesTabCarriesTheAlertFieldsAndTheFirstOneMovesThemToATabOfTheirOwn(): void
    {
        $builder = $this->factory()->createBuilder(FormType::class);
        $this->integration()->appendToForm($builder, ['alerts_enabled' => 1, 'alert_warning_percent' => 7], 'features');
        $form = $builder->getForm();

        $this->assertTrue($form->get('alerts_enabled')->getData() === 1);
        $this->assertSame(7, $form->get('alert_warning_percent')->getData());
        $attr = $form->createView()['alerts_enabled']->vars['attr'];
        $this->assertSame('n8ndispatchAlertsTab', $attr['data-onload-callback']);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.tab', $attr['data-tab-label']);
    }

    public function testTheAlertFieldsAreNotOnTheKeysTab(): void
    {
        $this->assertFalse($this->keysForm([])->has('alerts_enabled'));
    }
}
