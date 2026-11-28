<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\JotaworksDoiBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\JotaworksDoiBundle\Helper\Base64Helper;
use Mautic\MessengerBundle\Queue\QueueService;
use MauticPlugin\JotaworksDoiBundle\QueueEvents;
use MauticPlugin\JotaworksDoiBundle\QueueName;
use MauticPlugin\JotaworksDoiBundle\Helper\DoiActionHelper;
use MauticPlugin\JotaworksDoiBundle\Helper\NotHumanClickHelper;
use MauticPlugin\JotaworksDoiBundle\Service\DelayedConfirmationService;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Controller\AbstractFormController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions as SecurityPermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class DoiController.
 */
class DoiController extends AbstractFormController
{
    protected EncryptionHelper $encryptionHelper;
    protected LeadModel $leadModel;
    protected DoiActionHelper $doiActionHelper;
    protected NotHumanClickHelper $notHumanClickHelper;
    protected DelayedConfirmationService $delayedConfirmation;

    public function __construct(
        protected ManagerRegistry $doctrine,
        protected ModelFactory $modelFactory,
        UserHelper $userHelper,
        protected CoreParametersHelper $coreParametersHelper,
        protected EventDispatcherInterface $dispatcher,
        protected Translator $translator,
        private FlashBag $flashBag,
        private ?RequestStack $requestStack,
        SecurityPermissions $security, // not promoted: assigned by the parent constructor (7.1: ?CorePermissions, 7.2: CorePermissions)
        protected CookieHelper $cookieHelper,
        EncryptionHelper $encryptionHelper,
        LeadModel $leadModel,
        DoiActionHelper $doiActionHelper,
        NotHumanClickHelper $notHumanClickHelper,
        DelayedConfirmationService $delayedConfirmation
    ) {

        $this->encryptionHelper = $encryptionHelper;
        $this->leadModel = $leadModel;
        $this->doiActionHelper = $doiActionHelper;
        $this->notHumanClickHelper = $notHumanClickHelper;
        $this->delayedConfirmation = $delayedConfirmation;

        parent::__construct(
            $doctrine,
            $modelFactory,
            $userHelper,
            $coreParametersHelper,
            $dispatcher,
            $translator,
            $flashBag,
            $requestStack,
            $security
        );
    }    

    protected function decryptDoiActions($enc)
    {
        //Get doi parameters
        if (!$enc) {
            http_response_code(400);
            die();
        }

        //get base64 string
        $base64 = Base64Helper::prepare_base64_url_decode($enc);

        //decrypt string
        $config = $this->encryptionHelper->decrypt($base64, true);
        if (!$config || !is_array($config)) {
            http_response_code(401);
            die();
        }

        $lead = $this->leadModel->getEntity($config['lead_id']);
        if (!$lead) {
            http_response_code(400);
            die();
        }
        $leadEmail = $lead !== null ? $lead->getEmail() : null;
        $config['leadEmail'] = $leadEmail;

        return $config;
    }


    /**
     * Doi confirmation action
     *
     * @param string $enc
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($enc = false)
    {
        //try to decrypt doi action config
        $config = $this->decryptDoiActions($enc);

        // Link scanner protection (opt-in plugin setting): apply the DOI actions only after a
        // delay and only if the bait link {doi_nothuman} of the same mail was not requested.
        // Off (default): actions are applied immediately as before.
        if ($this->delayedConfirmation->isEnabled()) {
            $this->delayedConfirmation->dispatch($config);
        } else {
            $this->doiActionHelper->applyDoiActions($config);
        }

        // Without a success URL in the DOI action a redirect would fail ("Cannot redirect to an empty URL", 500)
        // after the confirmation was already applied; show a plain confirmation page instead.
        $url = trim((string) ($config['url'] ?? ''));
        if ('' === $url) {
            return $this->delegateView([
                'viewParameters'  => [],
                'contentTemplate' => '@JotaworksDoi/Doi/confirmed.html.twig',
            ]);
        }

        //redirect to doi success url
        return $this->redirect($url, 301);
    }

    /**
     * Click bait action for email scanning bots
     *
     * @param string $hash
     */
    public function nothumanAction($hash = false)
    {
        $this->notHumanClickHelper->setClick($hash);

        return $this->delegateView([
            'viewParameters'  => [],
            'contentTemplate' => '@JotaworksDoi/Doi/nothuman.html.twig',
        ]);
    }

}
