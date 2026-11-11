<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel;
use MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use MauticPlugin\N8nDispatchBundle\Service\ResendVariables;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use MauticPlugin\N8nDispatchBundle\Service\VariableMappingChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the resend form shows and sends: the template's variables as they resolve today, next to the first dispatch's.
 */
class ResendVariablesTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EmailVariablesRepository&MockObject $emailVariables;
    private SmsTemplateModel&MockObject $sms;
    private HsmTemplateModel&MockObject $hsm;
    private VariableResolver&MockObject $resolver;
    private ResendVariables $service;

    protected function setUp(): void
    {
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->emailVariables = $this->createMock(EmailVariablesRepository::class);
        $this->sms            = $this->createMock(SmsTemplateModel::class);
        $this->hsm            = $this->createMock(HsmTemplateModel::class);
        $this->resolver       = $this->createMock(VariableResolver::class);
        $this->service        = new ResendVariables($this->em, $this->emailVariables, $this->sms, $this->hsm, $this->resolver, new TemplateVariableScanner(), new VariableMappingChecker());
    }

    public function testTheStateIsOkWhenMappedWithAValue(): void
    {
        $this->assertSame('ok', ResendVariables::stateOf('Zeca', true));
    }

    public function testTheStateIsEmptyWhenMappedButTheContactHasNothing(): void
    {
        $this->assertSame('empty', ResendVariables::stateOf('', true));
    }

    public function testTheStateIsUnmappedWhenTheMappingHasNoSource(): void
    {
        $this->assertSame('unmapped', ResendVariables::stateOf('', false));
        $this->assertSame('unmapped', ResendVariables::stateOf('x', false), 'a value with no source filled in is still not mapped');
    }

    public function testAnSmsListsEachVariableOfTheTextWithTheFirstValueTodaysValueAndItsState(): void
    {
        $this->smsTemplate('Oi {{1}}, turma {{2}} e {{3}}', '{"1":{"source":"field","field":"firstname"},"2":{"source":"field","field":"turma"},"3":{"source":"field","field":""}}');
        $this->resolver->method('resolveAll')->willReturn(['1' => 'Zeca', '2' => '', '3' => '']);

        $rows = $this->rows($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], ['1' => 'Ana']));

        $this->assertSame([
            ['name' => '1', 'first' => 'Ana', 'current' => 'Zeca', 'state' => 'ok'],
            ['name' => '2', 'first' => '', 'current' => '', 'state' => 'empty'],
            ['name' => '3', 'first' => '', 'current' => '', 'state' => 'unmapped'],
        ], $rows);
    }

    public function testAnHsmHandedToAnotherPluginShowsTheValuesOfItsRequestAsTheFirstSend(): void
    {
        $this->smsTemplate('Oi {{1}}', '{"1":{"source":"field","field":"firstname"}}');
        $this->resolver->method('resolveAll')->willReturn(['1' => 'Zeca']);
        $log = $this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []);
        $log->setMetadata(['n8ndispatch' => ['mode' => 'dialoghsm', 'request' => ['variables' => ['1' => 'Ana']]]]);

        $this->assertSame([['name' => '1', 'first' => 'Ana', 'current' => 'Zeca', 'state' => 'ok']], $this->rows($log));
    }

    public function testTheMappingIsResolvedWithTheLanguageAndTheSeparatorOfTheChannel(): void
    {
        $this->smsTemplate('Oi {{1}}', '{"1":{"source":"static","value":"x"}}', 'en_US');
        $this->resolver->expects($this->once())->method('resolveAll')->with($this->anything(), $this->anything(), $this->anything(), ', ', 'en_US')->willReturn(['1' => 'x']);

        $this->rows($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []));
    }

    public function testAnEmailReadsItsVariablesFromTheSubjectAndTheHtmlAndItsMappingFromTheVariablesTab(): void
    {
        $email = (new Email())->setSubject('Oi {{n8n_nome}}')->setCustomHtml('<p>{{n8n_curso}}</p>');
        $email->setLanguage('en_US');
        $this->em->method('find')->willReturn($email);
        $this->emailVariables->method('getVariablesJsonForEmail')->with(7)->willReturn('{"n8n_nome":{"source":"static","value":"a"},"n8n_curso":{"source":"static","value":"b"}}');
        $this->resolver->expects($this->once())->method('resolveAll')->with($this->anything(), $this->anything(), $this->anything(), '<br>', 'en_US')->willReturn(['n8n_nome' => 'a', 'n8n_curso' => 'b']);

        $rows = $this->rows($this->log('n8ndispatch.email.send', ['email' => 7], ['n8n_nome' => 'a']));

        $this->assertSame(['n8n_nome', 'n8n_curso'], array_column($rows, 'name'));
        $this->assertSame(['ok', 'ok'], array_column($rows, 'state'));
    }

    public function testAnEmailStepWithoutATemplateMappingUsesItsOwn(): void
    {
        $email = (new Email())->setCustomHtml('{{n8n_nome}}');
        $this->em->method('find')->willReturn($email);
        $this->emailVariables->method('getVariablesJsonForEmail')->willReturn(null);
        $this->resolver->expects($this->once())->method('resolveAll')->with(['n8n_nome' => ['source' => 'static', 'value' => 'z']], $this->anything(), $this->anything(), '<br>', $this->anything())->willReturn(['n8n_nome' => 'z']);

        $rows = $this->rows($this->log('n8ndispatch.email.send', ['email' => 7, 'variablesJson' => '{"n8n_nome":{"source":"static","value":"z"}}'], []));

        $this->assertSame('ok', $rows[0]['state']);
    }

    public function testAnHsmListsTheVariablesOfItsText(): void
    {
        $template = (new HsmTemplate())->setText('Olá {{1}}')->setVariablesJson('{"1":{"source":"field","field":"firstname"}}');
        $this->hsm->method('getEntity')->with(9)->willReturn($template);
        $this->resolver->method('resolveAll')->willReturn(['1' => 'Zeca']);

        $rows = $this->rows($this->log('n8ndispatch.hsm.send', ['hsmTemplateId' => 9], ['1' => 'Zeca']));

        $this->assertSame([['name' => '1', 'first' => 'Zeca', 'current' => 'Zeca', 'state' => 'ok']], $rows);
    }

    public function testATemplateWithNoReadableNameListsWhatItsMappingHolds(): void
    {
        $template = (new HsmTemplate())->setText('texto sem variável')->setVariablesJson('{"1":{"source":"static","value":"a"}}');
        $this->hsm->method('getEntity')->willReturn($template);
        $this->resolver->method('resolveAll')->willReturn(['1' => 'a']);

        $rows = $this->rows($this->log('n8ndispatch.hsm.send', ['hsmTemplateId' => 9], []));

        $this->assertSame(['1'], array_column($rows, 'name'));
    }

    public function testTheMessageKeepsAVariableNobodyFilled(): void
    {
        $this->smsTemplate('Oi {{1}} {{2}}', '{}');
        $this->resolver->method('resolveAll')->willReturn([]);

        $this->assertSame('Oi Zeca {{2}}', $this->service->smsMessage($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []), ['1' => 'Zeca']), 'the message is filled from the text of the template today');
    }

    public function testOnlyAnSmsHasAMessage(): void
    {
        $this->hsm->method('getEntity')->willReturn((new HsmTemplate())->setText('Olá {{1}}')->setVariablesJson('{}'));
        $this->resolver->method('resolveAll')->willReturn([]);

        $this->assertNull($this->service->smsMessage($this->log('n8ndispatch.hsm.send', ['hsmTemplateId' => 9], []), ['1' => 'Zeca']));
    }

    public function testAnOldSmsStepWithItsOwnTextStillWorks(): void
    {
        $this->resolver->method('resolveAll')->willReturn(['1' => 'Zeca']);

        $this->assertSame('Oi Zeca', $this->service->smsMessage($this->log('n8ndispatch.sms.send', ['text' => 'Oi {{1}}', 'variablesJson' => '{}'], []), ['1' => 'Zeca']));
    }

    public function testAnEmailListsOnlyTheVariablesOfTheTemplateNotEveryKeyOfItsMapping(): void
    {
        $email = (new Email())->setCustomHtml('{{n8n_nome}}');
        $this->em->method('find')->willReturn($email);
        $this->emailVariables->method('getVariablesJsonForEmail')->willReturn('{"n8n_nome":{"source":"static","value":"a"},"n8n_antiga":{"source":"static","value":"b"}}');
        $this->resolver->method('resolveAll')->willReturn(['n8n_nome' => 'a', 'n8n_antiga' => 'b']);

        $rows = $this->rows($this->log('n8ndispatch.email.send', ['email' => 7], []));

        $this->assertSame(['n8n_nome'], array_column($rows, 'name'));
    }

    public function testAnEmailStepWithoutAnEmailAsksForNothing(): void
    {
        $this->em->expects($this->never())->method('find');
        $this->emailVariables->expects($this->never())->method('getVariablesJsonForEmail');
        $this->resolver->expects($this->once())->method('resolveAll')->with([], $this->anything(), $this->anything(), '<br>', 'pt_BR')->willReturn([]);

        $this->assertSame([], $this->rows($this->log('n8ndispatch.email.send', [], [])));
    }

    public function testAnEmailThatWasDeletedKeepsTheLanguageDefaultAndNoVariables(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->emailVariables->method('getVariablesJsonForEmail')->willReturn('{"n8n_nome":{"source":"static","value":"a"}}');
        $this->resolver->expects($this->once())->method('resolveAll')->with($this->anything(), $this->anything(), $this->anything(), '<br>', 'pt_BR')->willReturn(['n8n_nome' => 'a']);

        $this->assertSame(['n8n_nome'], array_column($this->rows($this->log('n8ndispatch.email.send', ['email' => 7], [])), 'name'));
    }

    public function testAnSmsTemplateWithoutTextOrMappingGivesNoRows(): void
    {
        $this->sms->method('getEntity')->willReturn((new SmsTemplate())->setText(null)->setVariablesJson(null));
        $this->resolver->expects($this->once())->method('resolveAll')->with([], $this->anything(), $this->anything(), ', ', $this->anything())->willReturn([]);

        $this->assertSame([], $this->rows($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], [])));
    }

    public function testAnOldSmsStepUsesTheDefaultLanguageAndItsOwnMapping(): void
    {
        $this->sms->expects($this->never())->method('getEntity');
        $this->resolver->expects($this->once())->method('resolveAll')->with(['1' => ['source' => 'static', 'value' => 'a']], $this->anything(), $this->anything(), ', ', 'pt_BR')->willReturn(['1' => 'a']);

        $rows = $this->rows($this->log('n8ndispatch.sms.send', ['text' => 'Oi {{1}}', 'variablesJson' => '{"1":{"source":"static","value":"a"}}'], []));

        $this->assertSame('ok', $rows[0]['state']);
    }

    public function testAnSmsStepWithNoTextAtAllGivesAnEmptyMessage(): void
    {
        $this->resolver->method('resolveAll')->willReturn([]);

        $this->assertSame('', $this->service->smsMessage($this->log('n8ndispatch.sms.send', [], []), ['1' => 'x']));
    }

    public function testAnHsmStepWithoutATemplateUsesItsOwnMappingAndTheDefaultLanguage(): void
    {
        $this->hsm->expects($this->never())->method('getEntity');
        $this->resolver->expects($this->once())->method('resolveAll')->with(['1' => ['source' => 'static', 'value' => 'a']], $this->anything(), $this->anything(), ', ', 'pt_BR')->willReturn(['1' => 'a']);

        $rows = $this->rows($this->log('n8ndispatch.hsm.send', ['variablesJson' => '{"1":{"source":"static","value":"a"}}'], []));

        $this->assertSame(['1'], array_column($rows, 'name'));
    }

    public function testAnHsmTemplateWithoutTextOrMappingGivesNoRowsAndItsLanguageIsUsed(): void
    {
        $this->hsm->method('getEntity')->willReturn((new HsmTemplate())->setText(null)->setVariablesJson(null)->setLanguage('en_US'));
        $this->resolver->expects($this->once())->method('resolveAll')->with([], $this->anything(), $this->anything(), ', ', 'en_US')->willReturn([]);

        $this->assertSame([], $this->rows($this->log('n8ndispatch.hsm.send', ['hsmTemplateId' => 9], [])));
    }

    public function testTheMessageOfAnSmsIsItsTemplateTextFilledWithTodaysValuesAndAnEmptyOneStaysAsWritten(): void
    {
        $this->smsTemplate('Oi {{1}}, turma {{2}} em {{3}}', '{}');
        $this->resolver->method('resolveAll')->willReturn(['1' => 'Zeca', '2' => '']);

        $message = $this->message($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []));

        $this->assertSame(['template' => 'Oi {{1}}, turma {{2}} em {{3}}', 'filled' => 'Oi Zeca, turma {{2}} em {{3}}'], $message);
    }

    public function testTheMessageOfAnHsmIsTheTextOfItsTemplate(): void
    {
        $this->hsm->method('getEntity')->willReturn((new HsmTemplate())->setText('Olá {{1}}')->setVariablesJson('{}'));
        $this->resolver->method('resolveAll')->willReturn(['1' => 'Zeca']);

        $this->assertSame(['template' => 'Olá {{1}}', 'filled' => 'Olá Zeca'], $this->message($this->log('n8ndispatch.hsm.send', ['hsmTemplateId' => 9], [])));
    }

    public function testAnEmailHasNoMessageToShow(): void
    {
        $this->em->method('find')->willReturn((new Email())->setCustomHtml('{{n8n_nome}}'));
        $this->emailVariables->method('getVariablesJsonForEmail')->willReturn('{}');
        $this->resolver->method('resolveAll')->willReturn([]);

        $this->assertNull($this->message($this->log('n8ndispatch.email.send', ['email' => 7], [])));
    }

    public function testATemplateWithoutTextHasNoMessageToShow(): void
    {
        $this->sms->method('getEntity')->willReturn((new SmsTemplate())->setText('  ')->setVariablesJson('{}'));
        $this->resolver->method('resolveAll')->willReturn([]);

        $this->assertNull($this->message($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], [])));
    }

    public function testAnHsmStepWithoutATemplateHasNoMessageToShow(): void
    {
        $this->resolver->method('resolveAll')->willReturn([]);

        $this->assertNull($this->message($this->log('n8ndispatch.hsm.send', ['variablesJson' => '{}'], [])));
    }

    public function testTheValuesAreResolvedOnceForTheWholeForm(): void
    {
        $this->smsTemplate('Oi {{1}}', '{"1":{"source":"static","value":"a"}}');
        $this->resolver->expects($this->once())->method('resolveAll')->willReturn(['1' => 'a']);

        $form = $this->service->form($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []));

        $this->assertCount(1, $form['rows']);
        $this->assertSame('Oi a', $form['message']['filled']);
    }

    public function testTheSmsMessageAsksNothingOfTheResolver(): void
    {
        $this->smsTemplate('Oi {{1}}', '{"1":{"source":"field","field":"firstname"}}');
        $this->resolver->expects($this->never())->method('resolveAll');

        $this->service->smsMessage($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []), ['1' => 'Zeca']);
    }

    public function testOnlyTextValuesFillTheSmsMessage(): void
    {
        $this->smsTemplate('Oi {{1}} {{2}} {{3}}', '{}');

        $this->assertSame('Oi 7 {{2}} c', $this->service->smsMessage($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []), ['1' => 7, '2' => ['x'], '3' => 'c']));
    }

    public function testAnSmsTemplateWithNoTextHasNoMessageToFill(): void
    {
        $this->sms->method('getEntity')->willReturn((new SmsTemplate())->setText(null)->setVariablesJson('{}'));

        $this->assertSame('', $this->service->smsMessage($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []), []));
    }

    public function testTheFirstValuesAreShownAsTextAndOnlyScalarOnesAreKept(): void
    {
        $this->smsTemplate('Oi {{1}} {{2}} {{3}}', '{}');
        $this->resolver->method('resolveAll')->willReturn([]);

        $rows = $this->rows($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], ['1' => 7, '2' => ['x'], '3' => 'c']));

        $this->assertSame(['7', '', 'c'], array_column($rows, 'first'));
    }

    public function testTheEmptyVariablesAreTheNamesOfTheTemplateWithNoValueInWhatWouldGoOut(): void
    {
        $this->smsTemplate('Oi {{1}} {{2}} {{3}} {{4}}', '{}');

        $empty = $this->service->emptyNames($this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []), ['1' => 'Zeca', '2' => '', '3' => '   ', '4' => ['x']]);

        $this->assertSame(['2', '3', '4'], $empty);
    }

    public function testUnicodeWhitespaceIsNotAValueButZeroIs(): void
    {
        $this->smsTemplate('{{1}} {{2}} {{3}} {{4}} {{5}}', '{}');

        $empty = $this->service->emptyNames(
            $this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []),
            ['1' => "\u{00A0}", '2' => "\u{3000} \t\n", '3' => "\u{FEFF}", '4' => '0', '5' => 'a b']
        );

        $this->assertSame(['1', '2', '3'], $empty);
    }

    public function testAVariableNobodySentIsEmptyAndACompleteSetHasNone(): void
    {
        $this->smsTemplate('Oi {{1}} {{2}}', '{}');
        $log = $this->log('n8ndispatch.sms.send', ['smsTemplate' => 5], []);

        $this->assertSame(['2'], $this->service->emptyNames($log, ['1' => 'Zeca']));
        $this->assertSame([], $this->service->emptyNames($log, ['1' => 'Zeca', '2' => '0']), 'a zero is a value');
    }

    public function testATemplateWithNoReadableNameAsksForWhatItsMappingHolds(): void
    {
        $this->hsm->method('getEntity')->willReturn((new HsmTemplate())->setText('sem variável')->setVariablesJson('{"1":{"source":"static","value":"a"}}'));

        $this->assertSame(['1'], $this->service->emptyNames($this->log('n8ndispatch.hsm.send', ['hsmTemplateId' => 9], []), []));
    }

    /**
     * @return list<array{name: string, first: string, current: string, state: string}>
     */
    private function rows(LeadEventLog $log): array
    {
        return $this->service->form($log)['rows'];
    }

    /**
     * @return array{template: string, filled: string}|null
     */
    private function message(LeadEventLog $log): ?array
    {
        return $this->service->form($log)['message'];
    }

    private function smsTemplate(string $text, string $json, string $language = 'pt_BR'): void
    {
        $template = (new SmsTemplate())->setText($text)->setVariablesJson($json)->setLanguage($language);
        $this->sms->method('getEntity')->with(5)->willReturn($template);
    }

    /**
     * @param array<string, mixed>  $properties
     * @param array<string, string> $first
     */
    private function log(string $type, array $properties, array $first): LeadEventLog
    {
        $event = new Event();
        $event->setType($type);
        $event->setCampaign(new Campaign());
        $event->setProperties($properties);

        $log = new LeadEventLog();
        $log->setEvent($event);
        $log->setLead(new Lead());
        $log->setMetadata(['n8ndispatch' => ['variables' => $first]]);

        return $log;
    }
}
