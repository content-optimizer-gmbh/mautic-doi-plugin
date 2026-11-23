<?php

namespace MauticPlugin\JotaworksDoiBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\JotaworksDoiBundle\Entity\UnsubscribeRule;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Plugin settings of the DOI bundle (Settings > Plugins > Double-Opt-In).
 *
 * The integration name is derived by Mautic from the file name
 * (JotaworksDoiIntegration.php -> "JotaworksDoi") and must match getName().
 */
class JotaworksDoiIntegration extends AbstractIntegration
{
    public const INTEGRATION_NAME = 'JotaworksDoi';

    /** Feature setting: delay the confirmation and check the bait link first. */
    public const FEATURE_LINKSCANNER_PROTECTION = 'linkscanner_protection';

    /** Feature setting: delay in seconds before the confirmation is applied. */
    public const FEATURE_LINKSCANNER_DELAY = 'linkscanner_delay';

    public const DEFAULT_DELAY_SECONDS = 60;

    public const MAX_DELAY_SECONDS = 3600;

    /**
     * A bait link hit counts for a confirmation only if it happened at most this many seconds
     * before the click (up to the end of the delay). Older markers are stale (e.g. a scanner
     * only requested the bait link) and must not swallow a later real click.
     */
    public const BAIT_LOOKBACK_SECONDS = 300;

    /** Feature setting (2.4.0): global one-click unsubscribe mode for emails without own rule. */
    public const FEATURE_ONECLICK_MODE = 'oneclick_mode';

    /** Feature setting (2.4.0): global fallback when the mode does not fit the email. */
    public const FEATURE_ONECLICK_FALLBACK = 'oneclick_fallback';

    /** Feature setting (2.4.1): contact field set on every performed one-click unsubscribe ('' = none). */
    public const FEATURE_ONECLICK_FIELD = 'oneclick_field';

    /** Feature setting (2.4.1): value for that field. */
    public const FEATURE_ONECLICK_FIELD_VALUE = 'oneclick_field_value';

    public const ONECLICK_MODES = [
        UnsubscribeRule::MODE_BLOCK,
        UnsubscribeRule::MODE_AUTO,
        UnsubscribeRule::MODE_SEGMENTS,
        UnsubscribeRule::MODE_CAMPAIGN,
    ];

    public const ONECLICK_FALLBACKS = [
        UnsubscribeRule::MODE_BLOCK,
        UnsubscribeRule::MODE_SEGMENTS,
        UnsubscribeRule::MODE_CAMPAIGN,
    ];

    public function getName()
    {
        return self::INTEGRATION_NAME;
    }

    public function getDisplayName()
    {
        return 'Double-Opt-In (DOI)';
    }

    public function getAuthenticationType()
    {
        return 'none';
    }

    public function getRequiredKeyFields()
    {
        return [];
    }

    public function getIcon()
    {
        return 'plugins/JotaworksDoiBundle/Assets/img/icon.png';
    }

    /**
     * The note on the first tab warns that the option needs an async transport and a worker.
     */
    public function getFormNotes($section)
    {
        if ('authorization' === $section) {
            return [$this->translator->trans('jw.doi.integration.linkscanner.note'), 'warning'];
        }

        return parent::getFormNotes($section);
    }

    /**
     * @param \Mautic\PluginBundle\Integration\Form|\Symfony\Component\Form\FormBuilder $builder
     * @param array                                                                     $data
     * @param string                                                                    $formArea
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' !== $formArea) {
            return;
        }

        $data = is_array($data) ? $data : [];

        $builder->add(
            self::FEATURE_LINKSCANNER_PROTECTION,
            YesNoButtonGroupType::class,
            [
                'label' => 'jw.doi.integration.linkscanner.protection',
                'data'  => !empty($data[self::FEATURE_LINKSCANNER_PROTECTION]),
                'attr'  => [
                    'tooltip' => 'jw.doi.integration.linkscanner.protection.tooltip',
                ],
            ]
        );

        $builder->add(
            self::FEATURE_LINKSCANNER_DELAY,
            IntegerType::class,
            [
                'label'       => 'jw.doi.integration.linkscanner.delay',
                'label_attr'  => ['class' => 'control-label'],
                'required'    => false,
                'data'        => isset($data[self::FEATURE_LINKSCANNER_DELAY]) && '' !== $data[self::FEATURE_LINKSCANNER_DELAY]
                    ? (int) $data[self::FEATURE_LINKSCANNER_DELAY]
                    : self::DEFAULT_DELAY_SECONDS,
                'constraints' => [
                    new Range(min: 1, max: self::MAX_DELAY_SECONDS),
                ],
                'attr'        => [
                    'class'   => 'form-control',
                    'tooltip' => 'jw.doi.integration.linkscanner.delay.tooltip',
                ],
            ]
        );

        $builder->add(
            self::FEATURE_ONECLICK_MODE,
            ChoiceType::class,
            [
                'label'       => 'jw.doi.integration.oneclick.mode',
                'label_attr'  => ['class' => 'control-label'],
                'required'    => true,
                'placeholder' => false,
                'choices'     => $this->modeChoices(self::ONECLICK_MODES),
                'data'        => in_array($data[self::FEATURE_ONECLICK_MODE] ?? null, self::ONECLICK_MODES, true)
                    ? $data[self::FEATURE_ONECLICK_MODE]
                    : UnsubscribeRule::MODE_BLOCK,
                'attr'        => [
                    'class'   => 'form-control',
                    'tooltip' => 'jw.doi.integration.oneclick.mode.tooltip',
                ],
            ]
        );

        $builder->add(
            self::FEATURE_ONECLICK_FALLBACK,
            ChoiceType::class,
            [
                'label'       => 'jw.doi.integration.oneclick.fallback',
                'label_attr'  => ['class' => 'control-label'],
                'required'    => true,
                'placeholder' => false,
                'choices'     => $this->modeChoices(self::ONECLICK_FALLBACKS),
                'data'        => in_array($data[self::FEATURE_ONECLICK_FALLBACK] ?? null, self::ONECLICK_FALLBACKS, true)
                    ? $data[self::FEATURE_ONECLICK_FALLBACK]
                    : UnsubscribeRule::MODE_BLOCK,
                'attr'        => [
                    'class'   => 'form-control',
                    'tooltip' => 'jw.doi.integration.oneclick.fallback.tooltip',
                ],
            ]
        );

        $fieldChoices = ['jw.doi.unsubscribe.field.none' => ''];
        foreach ($this->fieldModel->getFieldList(false) as $alias => $label) {
            $fieldChoices[$label.' ('.$alias.')'] = $alias;
        }
        $builder->add(
            self::FEATURE_ONECLICK_FIELD,
            ChoiceType::class,
            [
                'label'       => 'jw.doi.integration.oneclick.field',
                'label_attr'  => ['class' => 'control-label'],
                'required'    => false,
                'placeholder' => false,
                'choices'     => $fieldChoices,
                'data'        => (string) ($data[self::FEATURE_ONECLICK_FIELD] ?? ''),
                'attr'        => [
                    'class'   => 'form-control',
                    'tooltip' => 'jw.doi.integration.oneclick.field.tooltip',
                ],
            ]
        );

        $builder->add(
            self::FEATURE_ONECLICK_FIELD_VALUE,
            TextType::class,
            [
                'label'      => 'jw.doi.integration.oneclick.field_value',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'data'       => (string) ($data[self::FEATURE_ONECLICK_FIELD_VALUE] ?? ''),
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'jw.doi.integration.oneclick.field_value.tooltip',
                ],
            ]
        );
    }

    /**
     * @param string[] $modes
     *
     * @return array<string, string> label key => mode
     */
    private function modeChoices(array $modes): array
    {
        $choices = [];
        foreach ($modes as $mode) {
            $choices['jw.doi.unsubscribe.mode.'.$mode] = $mode;
        }

        return $choices;
    }
}
