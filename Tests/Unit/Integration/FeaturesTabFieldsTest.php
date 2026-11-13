<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validation;

/**
 * The status poll and Mirror resync fields of the Features tab, as the real form builds them: type, label, tooltip,
 * limits, saved value and the read-only "Last run" block. The alert fields have their own test.
 */
class FeaturesTabFieldsTest extends TestCase
{
    private function featuresForm(array $saved = []): \Symfony\Component\Form\FormInterface
    {
        $integration = (new \ReflectionClass(N8nDispatchIntegration::class))->newInstanceWithoutConstructor();
        $translator  = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $em = $this->createStub(\Doctrine\ORM\EntityManager::class);
        $em->method('getRepository')->willThrowException(new \RuntimeException('no table'));
        $parent = \Mautic\PluginBundle\Integration\AbstractIntegration::class;
        (new \ReflectionProperty($parent, 'translator'))->setValue($integration, $translator);
        (new \ReflectionProperty($parent, 'em'))->setValue($integration, $em);

        $factory = Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension(Validation::createValidator()))->getFormFactory();
        $builder = $factory->createBuilder(FormType::class);
        $integration->appendToForm($builder, $saved, 'features');

        return $builder->getForm();
    }

    /**
     * @return array<string, array{string, string, string, int, int, int}> key, text prefix, text, min, max, default
     */
    public static function numbers(): array
    {
        return [
            'status_poll_interval'     => ['status_poll_interval', 'status_poll', 'interval', 5, 1440, 60],
            'status_poll_batch'        => ['status_poll_batch', 'status_poll', 'batch', 10, 1000, 100],
            'status_poll_delay'        => ['status_poll_delay', 'status_poll', 'delay', 0, 60000, 1000],
            'status_poll_timeout'      => ['status_poll_timeout', 'status_poll', 'timeout', 10, 900, 180],
            'status_poll_max_duration' => ['status_poll_max_duration', 'status_poll', 'max_duration', 10, 1800, 300],
            'mirror_resync_interval'      => ['mirror_resync_interval', 'mirror_resync', 'interval', 30, 1440, 120],
            'mirror_resync_batch'         => ['mirror_resync_batch', 'mirror_resync', 'batch', 10, 1000, 100],
            'mirror_resync_delay'         => ['mirror_resync_delay', 'mirror_resync', 'delay', 0, 60000, 2000],
            'mirror_resync_refresh_hours' => ['mirror_resync_refresh_hours', 'mirror_resync', 'refresh_hours', 6, 168, 24],
        ];
    }

    #[DataProvider('numbers')]
    public function testANumberFieldCarriesItsTextsLimitsAndDefault(string $key, string $prefix, string $text, int $min, int $max, int $default): void
    {
        $form   = $this->featuresForm();
        $config = $form->get($key)->getConfig();
        $attr   = $form->createView()[$key]->vars['attr'];

        $this->assertInstanceOf(IntegerType::class, $config->getType()->getInnerType());
        $this->assertSame($default, $form->get($key)->getData());
        $this->assertSame('mautic.n8ndispatch.settings.'.$prefix.'.'.$text, $config->getOption('label'));
        $this->assertSame('mautic.n8ndispatch.settings.'.$prefix.'.'.$text.'.tooltip', $attr['tooltip']);
        $this->assertSame('form-control', $attr['class']);
        $this->assertSame([$min, $max], [$attr['min'], $attr['max']]);
        $this->assertSame(['class' => 'control-label'], $config->getOption('label_attr'));
        $range = $config->getOption('constraints')[0];
        $this->assertInstanceOf(Range::class, $range);
        $this->assertSame([$min, $max], [$range->min, $range->max]);
    }

    public function testANumberFieldShowsTheSavedValue(): void
    {
        $form = $this->featuresForm(['status_poll_batch' => 250, 'mirror_resync_delay' => 500]);

        $this->assertSame(250, $form->get('status_poll_batch')->getData());
        $this->assertSame(500, $form->get('mirror_resync_delay')->getData());
    }

    public function testTheSwitchesAreOffUntilTurnedOnAndCarryTheirTooltip(): void
    {
        $form = $this->featuresForm();

        foreach (['status_poll', 'mirror_resync'] as $prefix) {
            $config = $form->get($prefix.'_enabled')->getConfig();
            $this->assertInstanceOf(YesNoButtonGroupType::class, $config->getType()->getInnerType());
            $this->assertSame(0, $form->get($prefix.'_enabled')->getData());
            $this->assertSame('mautic.n8ndispatch.settings.'.$prefix.'.enabled', $config->getOption('label'));
            $this->assertSame('mautic.n8ndispatch.settings.'.$prefix.'.enabled.tooltip', $config->getOption('attr')['tooltip']);
        }
        $on = $this->featuresForm(['status_poll_enabled' => 1, 'mirror_resync_enabled' => 1]);
        $this->assertSame(1, $on->get('status_poll_enabled')->getData());
        $this->assertSame(1, $on->get('mirror_resync_enabled')->getData());
    }

    public function testTheFieldsComeInTheOrderOfTheScreen(): void
    {
        $this->assertSame(
            ['status_poll_enabled', 'status_poll_interval', 'status_poll_batch', 'status_poll_delay', 'status_poll_timeout', 'status_poll_max_duration', 'status_poll_last_run', 'status_poll_check_now',
                'mirror_resync_enabled', 'mirror_resync_interval', 'mirror_resync_batch', 'mirror_resync_delay', 'mirror_resync_refresh_hours', 'mirror_resync_last_run'],
            array_slice(array_keys($this->featuresForm()->all()), 0, 14)
        );
    }

    #[DataProvider('lastRunBlocks')]
    public function testALastRunBlockIsAReadOnlyTextareaOutsideTheModel(string $key, string $label): void
    {
        $form   = $this->featuresForm();
        $config = $form->get($key)->getConfig();
        $attr   = $form->createView()[$key]->vars['attr'];

        $this->assertInstanceOf(TextareaType::class, $config->getType()->getInnerType());
        $this->assertSame($label, $config->getOption('label'));
        $this->assertSame(['class' => 'control-label'], $config->getOption('label_attr'));
        $this->assertFalse($config->getMapped());
        $this->assertTrue($config->getDisabled());
        $this->assertFalse($config->getRequired());
        $this->assertSame('', $form->get($key)->getData());
        $this->assertSame('form-control n8ndispatch-json-block', $attr['class']);
        $this->assertSame('n8ndispatchBlockFromTextarea', $attr['data-onload-callback']);
        $this->assertSame(2, $attr['rows']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function lastRunBlocks(): array
    {
        return [
            'status poll'   => ['status_poll_last_run', 'mautic.n8ndispatch.settings.status_poll.last_run'],
            'mirror resync' => ['mirror_resync_last_run', 'mautic.n8ndispatch.settings.mirror_resync.last_run'],
        ];
    }
}
