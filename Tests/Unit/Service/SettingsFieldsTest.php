<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\SettingsFields;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

class SettingsFieldsTest extends TestCase
{
    private function builder(): FormBuilderInterface
    {
        // with the validator extension, as in the app (it gives a field its "constraints" option)
        return Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension(Validation::createValidator()))->getFormFactory()->createBuilder(FormType::class);
    }

    public function testTheLastRunBlockHasOneRowPerLineOfItsContent(): void
    {
        $builder = $this->builder();

        SettingsFields::lastRun($builder, 'one', 'label', "a\nb\nc");
        SettingsFields::lastRun($builder, 'two', 'label', "a\nb\nc\nd\ne");
        $view = $builder->getForm()->createView();

        $this->assertSame(3, $view['one']->vars['attr']['rows']);
        $this->assertSame(5, $view['two']->vars['attr']['rows']);
    }

    public function testTheExtraAttributesComeAfterTheOnesOfTheField(): void
    {
        $builder = $this->builder();

        SettingsFields::number($builder, 'n', 5, 1, 9, 'text', ['data-x' => '1']);
        SettingsFields::switch($builder, 's', true, 'text', ['data-y' => '2']);
        $view = $builder->getForm()->createView();

        $this->assertSame(['class', 'tooltip', 'min', 'max', 'data-x'], array_keys($view['n']->vars['attr']));
        $this->assertSame('1', $view['n']->vars['attr']['data-x']);
        $this->assertSame('text.tooltip', $view['n']->vars['attr']['tooltip']);
        $this->assertSame(['tooltip', 'data-y'], array_keys($view['s']->vars['attr']));
        $this->assertSame('2', $view['s']->vars['attr']['data-y']);
    }
}
