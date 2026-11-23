<?php

namespace MauticPlugin\JotaworksDoiBundle\DependencyInjection;

use MauticPlugin\JotaworksDoiBundle\DTO\DelayedDoiConfirmationMessage;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * Registers the messenger transport "jotaworks_doi" for the delayed DOI confirmation.
 *
 * DSN from the Mautic parameter messenger_dsn_jotaworks_doi (default sync://, see
 * Config/config.php). sync:// ignores the DelayStamp; for the link scanner protection
 * the operator sets an async DSN (e.g. doctrine://default?queue_name=jotaworks_doi)
 * and runs a worker: bin/console messenger:consume jotaworks_doi.
 *
 * Services stay in Config/config.php; this extension only prepends framework config.
 */
class JotaworksDoiExtension extends Extension implements PrependExtensionInterface
{
    public const TRANSPORT = 'jotaworks_doi';

    /**
     * @param array<mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'transports' => [
                    self::TRANSPORT => [
                        'dsn'            => '%env(urlencoded-dsn:MAUTIC_MESSENGER_DSN_JOTAWORKS_DOI)%',
                        'retry_strategy' => [
                            'service' => \Mautic\MessengerBundle\Retry\RetryStrategy::class,
                        ],
                    ],
                ],
                'routing' => [
                    DelayedDoiConfirmationMessage::class => self::TRANSPORT,
                ],
            ],
        ]);
    }
}
