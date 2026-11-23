<?php

namespace MauticPlugin\JotaworksDoiBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
    }
}
