<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Integration;

use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Exception\InvalidValueException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;

class Config
{
    private IntegrationsHelper $integrationsHelper;

    /**
     * @var array<string,mixed[]>
     */
    private array $fieldDirections = [];

    /**
     * @var array<string,mixed[]>
     */
    private $mappedFields = [];

    public function __construct(IntegrationsHelper $integrationsHelper)
    {
        $this->integrationsHelper = $integrationsHelper;
    }

    public function isPublished(): bool
    {
        try {
            $integration = $this->getIntegrationEntity();

            return (bool) $integration->getIsPublished() ?: false;
        } catch (IntegrationNotFoundException $e) {
            return false;
        }
    }

    /**
     * @return mixed[]
     */
    public function getSupportedFeatures(): array
    {
        try {
            $integration = $this->getIntegrationEntity(); 

            return $integration->getSupportedFeatures() ?: [];
        } catch (IntegrationNotFoundException $e) {
            return [];
        }
    }

    /**
     * @return mixed[]
     */
    public function getFeatureSettings(): array
    {
        try {
            $integration = $this->getIntegrationEntity();

            return $integration->getFeatureSettings() ?: [];
        } catch (IntegrationNotFoundException $e) {
            return [];
        }
    }

    /**
     * @throws IntegrationNotFoundException
     */
    public function getIntegrationEntity(): Integration
    {
        $integrationObject = $this->integrationsHelper->getIntegration(JotaworksDoiIntegration::NAME);

        return $integrationObject->getIntegrationConfiguration();
    }
}
