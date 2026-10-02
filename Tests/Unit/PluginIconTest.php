<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Mautic shows a plugin's icon from <plugin>/Assets/img/icon.png and falls back
 * to its own generic one when that file is missing. This plugin has an
 * Integration (N8nDispatch), and the card of an integration is looked up by the
 * integration's own name instead: Assets/img/n8ndispatch.png
 * (AbstractIntegration::getIcon()). Both files carry the same image.
 */
class PluginIconTest extends TestCase
{
    private const ICON             = __DIR__.'/../../Assets/img/icon.png';
    private const INTEGRATION_ICON = __DIR__.'/../../Assets/img/n8ndispatch.png';

    public function testThePluginHasItsOwnIcon(): void
    {
        $this->assertFileExists(self::ICON);
    }

    public function testItIsASquarePng(): void
    {
        $info = getimagesize(self::ICON);

        $this->assertNotFalse($info, 'not an image');
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertSame($info[0], $info[1], 'the icon is shown in a square slot');
        $this->assertGreaterThanOrEqual(128, $info[0]);
    }

    public function testTheBackgroundIsTransparentSoItReadsOnLightAndDarkThemes(): void
    {
        $image = imagecreatefrompng(self::ICON);

        foreach ([[0, 0], [imagesx($image) - 1, 0], [0, imagesy($image) - 1], [imagesx($image) - 1, imagesy($image) - 1]] as [$x, $y]) {
            $this->assertSame(127, (imagecolorat($image, $x, $y) >> 24) & 127, "corner $x,$y is not transparent");
        }
    }

    public function testItIsNotMauticsGenericIcon(): void
    {
        $generic = '/var/www/html/docroot/app/bundles/PluginBundle/Assets/img/generic.png';

        if (!is_file($generic)) {
            $this->markTestSkipped('Mautic core is not next to the plugin.');
        }

        $this->assertNotSame(md5_file($generic), md5_file(self::ICON));
    }

    public function testTheIntegrationCardFindsItsIconUnderTheIntegrationsName(): void
    {
        $this->assertSame('n8ndispatch', strtolower(\MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration::NAME));
        $this->assertFileExists(self::INTEGRATION_ICON);
        $this->assertSame(md5_file(self::ICON), md5_file(self::INTEGRATION_ICON), 'the two icons must be the same image');
    }
}
