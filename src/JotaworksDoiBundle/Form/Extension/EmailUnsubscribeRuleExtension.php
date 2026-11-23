<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Form\Extension;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use Mautic\LeadBundle\Form\Type\LeadListType;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\JotaworksDoiBundle\Entity\UnsubscribeRule;
use MauticPlugin\JotaworksDoiBundle\Service\UnsubscribeRuleStore;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Section "Bei Abmeldung" in the email form (2.4.0): unmapped fields for the one-click rule.
 * Values are kept in UnsubscribeRuleStore on submit and stored by UnsubscribeRuleEmailSubscriber
 * after the email is saved (a new or cloned email gets its id only then). A clone starts with the
 * rule of its source email.
 */
class EmailUnsubscribeRuleExtension extends AbstractTypeExtension
{
    public const FIELD_MODE     = 'jw_unsub_mode';
    public const FIELD_SEGMENTS = 'jw_unsub_segments';
    public const FIELD_FALLBACK = 'jw_unsub_fallback';
    public const FIELD_LEADFIELD = 'jw_unsub_field';
    public const FIELD_LEADVALUE = 'jw_unsub_field_value';

    public function __construct(
        private UnsubscribeRuleStore $rules,
        private FieldModel $fieldModel,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [EmailType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $email = $options['data'] ?? null;
        $rule  = null;
        if ($email instanceof Email) {
            $rule = $this->rules->find($email->getId()) ?? (!$email->getId() ? $this->rules->find($email->getClonedId()) : null);
        }

        $builder->add(self::FIELD_MODE, ChoiceType::class, [
            'mapped'      => false,
            'required'    => false,
            'label'       => 'jw.doi.unsubscribe.rule.mode',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control', 'tooltip' => 'jw.doi.unsubscribe.rule.mode.tooltip'],
            'placeholder' => false,
            'choices'     => [
                'jw.doi.unsubscribe.mode.global'         => UnsubscribeRule::MODE_GLOBAL,
                'jw.doi.unsubscribe.mode.block'          => UnsubscribeRule::MODE_BLOCK,
                'jw.doi.unsubscribe.mode.segments'       => UnsubscribeRule::MODE_SEGMENTS,
                'jw.doi.unsubscribe.mode.campaign'       => UnsubscribeRule::MODE_CAMPAIGN,
                'jw.doi.unsubscribe.mode.fixed_segments' => UnsubscribeRule::MODE_FIXED_SEGMENTS,
            ],
            'data'        => $rule?->getMode() ?? UnsubscribeRule::MODE_GLOBAL,
        ]);

        $builder->add(self::FIELD_SEGMENTS, LeadListType::class, [
            'mapped'     => false,
            'required'   => false,
            'multiple'   => true,
            'label'      => 'jw.doi.unsubscribe.rule.segments',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'        => 'form-control',
                'tooltip'      => 'jw.doi.unsubscribe.rule.segments.tooltip',
                'data-show-on' => '{"emailform_'.self::FIELD_MODE.'":["'.UnsubscribeRule::MODE_FIXED_SEGMENTS.'"]}',
            ],
            'data'       => $rule?->getSegmentIds() ?? [],
        ]);

        $builder->add(self::FIELD_FALLBACK, ChoiceType::class, [
            'mapped'      => false,
            'required'    => false,
            'label'       => 'jw.doi.unsubscribe.rule.fallback',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control', 'tooltip' => 'jw.doi.unsubscribe.rule.fallback.tooltip'],
            'placeholder' => false,
            'choices'     => [
                'jw.doi.unsubscribe.mode.global'   => UnsubscribeRule::MODE_GLOBAL,
                'jw.doi.unsubscribe.mode.block'    => UnsubscribeRule::MODE_BLOCK,
                'jw.doi.unsubscribe.mode.segments' => UnsubscribeRule::MODE_SEGMENTS,
                'jw.doi.unsubscribe.mode.campaign' => UnsubscribeRule::MODE_CAMPAIGN,
            ],
            'data'        => $rule?->getFallback() ?? UnsubscribeRule::MODE_GLOBAL,
        ]);

        $fieldChoices = [
            'jw.doi.unsubscribe.field.global' => '',
            'jw.doi.unsubscribe.field.none'   => UnsubscribeRule::FIELD_NONE,
        ];
        foreach ($this->fieldModel->getFieldList(false) as $alias => $label) {
            $fieldChoices[$label.' ('.$alias.')'] = $alias;
        }
        $builder->add(self::FIELD_LEADFIELD, ChoiceType::class, [
            'mapped'      => false,
            'required'    => false,
            'label'       => 'jw.doi.unsubscribe.rule.field',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control', 'tooltip' => 'jw.doi.unsubscribe.rule.field.tooltip'],
            'placeholder' => false,
            'choices'     => $fieldChoices,
            'data'        => $rule?->getFieldAlias() ?? '',
        ]);

        $builder->add(self::FIELD_LEADVALUE, TextType::class, [
            'mapped'     => false,
            'required'   => false,
            'label'      => 'jw.doi.unsubscribe.rule.field_value',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'        => 'form-control',
                'tooltip'      => 'jw.doi.unsubscribe.rule.field_value.tooltip',
                'data-hide-on' => '{"emailform_'.self::FIELD_LEADFIELD.'":["","'.UnsubscribeRule::FIELD_NONE.'"]}',
            ],
            'data'       => $rule?->getFieldValue() ?? '',
        ]);

        // Only a request that carries the section changes the rule (API calls without it do not).
        $present = false;
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use (&$present): void {
            $data    = $event->getData();
            $present = is_array($data) && array_key_exists(self::FIELD_MODE, $data);
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use (&$present): void {
            $form  = $event->getForm();
            $email = $form->getData();
            if (!$present || !$email instanceof Email || !$form->isValid()) {
                return;
            }
            $this->rules->remember($email, [
                'mode'     => (string) $form->get(self::FIELD_MODE)->getData(),
                'segments' => array_map('intval', (array) $form->get(self::FIELD_SEGMENTS)->getData()),
                'fallback'    => (string) $form->get(self::FIELD_FALLBACK)->getData(),
                'field_alias' => (string) $form->get(self::FIELD_LEADFIELD)->getData(),
                'field_value' => trim((string) $form->get(self::FIELD_LEADVALUE)->getData()),
            ]);
        });
    }
}
