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
use MauticPlugin\JotaworksDoiBundle\Helper\Base64Helper;
use Mautic\QueueBundle\Queue\QueueService;
use MauticPlugin\JotaworksDoiBundle\QueueEvents;
use MauticPlugin\JotaworksDoiBundle\QueueName;

/**
 * Class DoiController.
 */
class DoiController extends FormController
{

    protected function decryptDoiActions($enc)
    {
        $leadModel = $this->getModel('lead');
        $encryptionHelper = $this->get('mautic.helper.encryption');

        //Get doi parameters
        if (!$enc) {
            http_response_code(400);
            die();
        }

        //get base64 string
        $base64 = Base64Helper::prepare_base64_url_decode($enc);

        //decrypt string
        $config = $encryptionHelper->decrypt($base64, true);
        if (!$config || !is_array($config)) {
            http_response_code(401);
            die();
        }

        $lead = $leadModel->getEntity($config['lead_id']);
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

        $queueService = $this->get('mautic.queue.service');
        if ($queueService->isQueueEnabled()) {
            $payload = [
                'config'            => $config,
                'doiActivationTime' => time(),
                'request'           => $this->request,
            ];

            $queueService->publishToQueue(QueueName::DOI_SUCCESSFUL, $payload);

        } else {
            $doiActionHelper = $this->get('jw.doi.actionhelper');
            $doiActionHelper->applyDoiActions($config);
        }

        //redirect to doi success url
        return $this->redirect($config['url'], 301);
    }

    /**
     * Click bait action for email scanning bots
     *
     * @param string $hash
     */
    public function nothumanAction($hash = false)
    {
        $notHumanClickHelper = $this->get('jw.doi.nothumanclickhelper');
        $notHumanClickHelper->setClick($hash);

        return $this->delegateView([
            'viewParameters'  => [],
            'contentTemplate' => 'JotaworksDoiBundle:Doi:nothuman.html.php',
        ]);
    }

}
