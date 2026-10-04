<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * A text field drawn as a password input (dots) that keeps its value. Symfony's
 * own PasswordType always comes up blank, so a saved token could never be
 * looked at again; this one only changes the input's type, which the browser can
 * reveal. Meant for a token that is not a real secret (the n8n webhook token).
 */
class VisiblePasswordType extends AbstractType
{
    public function getParent(): string
    {
        return TextType::class;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'password';
    }
}
