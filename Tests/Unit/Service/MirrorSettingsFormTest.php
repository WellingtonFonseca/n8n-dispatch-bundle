<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSettings;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSettingsForm;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

class MirrorSettingsFormTest extends TestCase
{
    public function testTheSwitchCarriesTheTabLabelAndTheScriptCallback(): void
    {
        $added = $this->build('Mirror');

        $attr = $added['mirror_send_enabled']['attr'];
        $this->assertSame('Mirror', $attr['data-tab-label']);
        $this->assertSame('n8ndispatchMirrorTab', $attr['data-onload-callback']);
    }

    public function testEveryFieldKeyStartsWithMirrorBecauseTheScriptFindsTheRowsByTheirLabel(): void
    {
        $added = $this->build('Mirror');

        $this->assertSame(
            ['mirror_send_enabled', 'mirror_resync_interval', 'mirror_resync_batch', 'mirror_resync_delay', 'mirror_resync_refresh_hours'],
            array_keys($added)
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function build(string $tabLabel): array
    {
        $added   = [];
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, string $type, array $options) use (&$added, $builder) {
            $added[$name] = $options;

            return $builder;
        });

        MirrorSettingsForm::addTo($builder, true, MirrorResyncSettings::fromArray([]), $tabLabel);

        return $added;
    }
}
