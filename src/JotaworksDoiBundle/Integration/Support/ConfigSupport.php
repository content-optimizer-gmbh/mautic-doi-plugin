<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormFeaturesInterface;
use MauticPlugin\JotaworksDoiBundle\Integration\JotaworksDoiIntegration;

class ConfigSupport extends JotaworksDoiIntegration implements ConfigFormInterface, ConfigFormFeaturesInterface
{
    use DefaultConfigFormTrait;

    /**
     * @return array<string,string>
     */
    public function getSupportedFeatures(): array
    {
        return [
            "no_email_to_confirmed" => 'jw.doi.feature.no_email_to_confirmed',
        ];
    }
}