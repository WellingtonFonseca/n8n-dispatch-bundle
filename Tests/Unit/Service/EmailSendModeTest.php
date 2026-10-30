<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\EmailSendMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailSendModeTest extends TestCase
{
    public function testTheModesAreN8nAndMautic(): void
    {
        $this->assertSame('email_send_mode', EmailSendMode::KEY);
        $this->assertSame('n8n', EmailSendMode::N8N);
        $this->assertSame('mautic', EmailSendMode::MAUTIC);
    }

    public function testItGoesThroughN8nByDefault(): void
    {
        $this->assertSame(EmailSendMode::N8N, EmailSendMode::fromArray([]));
    }

    /**
     * @dataProvider notMautic
     */
    #[DataProvider('notMautic')]
    public function testOnlyTheExactValueMauticTurnsTheDirectSendOn(mixed $stored): void
    {
        $this->assertSame(EmailSendMode::N8N, EmailSendMode::fromArray([EmailSendMode::KEY => $stored]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notMautic(): iterable
    {
        yield 'null'        => [null];
        yield 'empty'       => [''];
        yield 'n8n'         => ['n8n'];
        yield 'unknown'     => ['smtp'];
        yield 'upper case'  => ['MAUTIC'];
        yield 'int 1'       => [1];
        yield 'true'        => [true];
        yield 'array'       => [['mautic']];
    }

    public function testTheValueMauticTurnsTheDirectSendOn(): void
    {
        $this->assertSame(EmailSendMode::MAUTIC, EmailSendMode::fromArray([EmailSendMode::KEY => 'mautic']));
    }

    public function testTheModeIsReadFromTheSavedKeys(): void
    {
        $this->assertSame(EmailSendMode::MAUTIC, $this->mode(['email_send_mode' => 'mautic'])->current());
        $this->assertTrue($this->mode(['email_send_mode' => 'mautic'])->isDirect());
        $this->assertSame(EmailSendMode::N8N, $this->mode(['email_send_mode' => 'n8n'])->current());
        $this->assertFalse($this->mode(['email_send_mode' => 'n8n'])->isDirect());
    }

    public function testNothingSavedYetMeansN8n(): void
    {
        $this->assertSame(EmailSendMode::N8N, $this->mode([])->current());
        $this->assertFalse($this->mode([])->isDirect());
    }

    public function testKeysThatAreNotAnArrayMeanN8n(): void
    {
        $this->assertSame(EmailSendMode::N8N, $this->mode(null)->current());
    }

    public function testAMissingIntegrationMeansN8n(): void
    {
        $helper = $this->createStub(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->willReturn(false);

        $this->assertSame(EmailSendMode::N8N, (new EmailSendMode($helper))->current());
    }

    /**
     * @param array<string, mixed>|null $keys
     */
    private function mode(?array $keys): EmailSendMode
    {
        $integration = $this->createStub(N8nDispatchIntegration::class);
        $integration->method('getKeys')->willReturn($keys);

        $helper = $this->createStub(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->willReturn($integration);

        return new EmailSendMode($helper);
    }
}
