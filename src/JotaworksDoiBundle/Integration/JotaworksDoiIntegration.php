<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

class JotaworksDoiIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME = 'JotaworksDoi';
    public const DISPLAY_NAME = 'JotaWorks Double-Opt-In';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    public function getIcon(): string
    {
        return 'plugins/JotaworksDoiBundle/Assets/img/icon.png';
    }

}