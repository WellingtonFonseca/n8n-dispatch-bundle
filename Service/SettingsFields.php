<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Range;

/**
 * The field kinds of the Features tab (switch, number with limits, read-only "last run" block), built in one place.
 * $text is the translation key of the label; its tooltip is "<text>.tooltip".
 */
class SettingsFields
{
    /**
     * @param array<string, string> $extraAttr
     */
    public static function switch(FormBuilderInterface $builder, string $key, bool $on, string $text, array $extraAttr = []): void
    {
        $builder->add($key, YesNoButtonGroupType::class, [
            'label' => $text,
            'data'  => $on ? 1 : 0,
            'attr'  => ['tooltip' => $text.'.tooltip'] + $extraAttr,
        ]);
    }

    /**
     * @param array<string, string> $extraAttr
     */
    public static function number(FormBuilderInterface $builder, string $key, int $value, int $min, int $max, string $text, array $extraAttr = []): void
    {
        $builder->add($key, IntegerType::class, [
            'label'       => $text,
            'data'        => $value,
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => $text.'.tooltip',
                'min'     => $min,
                'max'     => $max,
            ] + $extraAttr,
            'constraints' => [new Range(min: $min, max: $max)],
        ]);
    }

    /**
     * A block, not a one-line input: the last run is JSON. Assets/js/status-poll-check-now.js swaps the textarea for a <pre>.
     */
    public static function lastRun(FormBuilderInterface $builder, string $key, string $text, string $content): void
    {
        $builder->add($key, TextareaType::class, [
            'label'      => $text,
            'label_attr' => ['class' => 'control-label'],
            'data'       => $content,
            'mapped'     => false,
            'disabled'   => true,
            'required'   => false,
            'attr'       => [
                'class'                => 'form-control n8ndispatch-json-block',
                'rows'                 => max(2, substr_count($content, "\n") + 1),
                'data-onload-callback' => 'n8ndispatchBlockFromTextarea',
            ],
        ]);
    }
}
