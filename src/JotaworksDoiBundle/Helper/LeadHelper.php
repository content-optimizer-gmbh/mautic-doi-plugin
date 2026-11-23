<?php

namespace MauticPlugin\JotaworksDoiBundle\Helper;
use Mautic\LeadBundle\Helper\CustomFieldHelper;

class LeadHelper {

    /**
     * Platzhalter, die dieser Helper selbst aufloest. Sie duerfen NICHT ueber den
     * generischen "Token = Alias eines Kontaktfeldes"-Zweig laufen, auch wenn ein
     * gleichnamiges Kontaktfeld existiert (doi_ip und doi_timestamp sind genau
     * solche Felder).
     */
    private const SPECIAL_TOKENS = ['doi_ip', 'doi_timestamp', 'tokenid'];

    /**
     * Formats a point in time (default: now) in the timezone Mautic DISPLAYS dates in.
     *
     * $at is set by the delayed confirmation (link scanner protection): {doi_timestamp} must be
     * the time of the human click, not the time the Messenger worker applies the actions.
     *
     * Jetzt-Zeitpunkt in der Zeitzone, in der Mautic Datumsangaben ANZEIGT.
     *
     * doi_timestamp ist ein Textfeld: Mautic gibt es unveraendert aus, statt es wie ein
     * echtes Datumsfeld aus UTC in die Anzeigezeitzone umzurechnen. PHP laeuft auf diesen
     * Servern auf UTC (date.timezone=UTC), Mautic zeigt aber in default_timezone an
     * (Europe/Berlin). Mit einem blossen date() stand im Kontakt deshalb ein Zeitstempel,
     * der zwei Stunden vor allen anderen Datumsangaben desselben Kontakts lag.
     *
     * Die Zeitzone kommt aus Mautics eigener Konfiguration - auf demselben Weg wie in
     * CoreBundle/Helper/DateTimeHelper::setDefaultTimezone(). Damit bleibt der Helper
     * instanzunabhaengig und funktioniert auch dort, wo eine andere Zone eingestellt ist.
     *
     * ACHTUNG - Gegenstelle: Der geschriebene Wert wird vom Syncer gelesen
     * (maubot, MauticNewContactsForwardTask) und ins CRM uebertragen. Dieser parst ihn
     * ausdruecklich in dieser Zone und rechnet vor dem Versand nach UTC zurueck, damit im
     * CRM derselbe Zeitpunkt ankommt wie zuvor. Wird das Format oder die Zone hier
     * geaendert, muss die Gegenseite mitgeaendert werden.
     */
    private static function timeInDisplayTimezone(?\DateTimeInterface $at = null): string
    {
        static $timezone = null;

        if (null === $timezone) {
            $timezone = date_default_timezone_get();

            try {
                $loader     = new \Mautic\CoreBundle\Loader\ParameterLoader();
                $configured = $loader->getParameterBag()->get('default_timezone');
                if (!empty($configured)) {
                    $timezone = $configured;
                }
            } catch (\Throwable $e) {
                // Konfiguration nicht lesbar - bei der PHP-Vorgabe bleiben.
            }
        }

        $zone = new \DateTimeZone($timezone);
        $time = null === $at
            ? new \DateTimeImmutable('now', $zone)
            : \DateTimeImmutable::createFromInterface($at)->setTimezone($zone);

        return $time->format('d.m.Y H:i:s');
    }

    /**
     * @param \DateTimeInterface|null $confirmedAt time written for {doi_timestamp}; null = now
     */
    public static function leadFieldUpdate($leadFieldUpdate, $leadModel, $lead, $ip = null, ?\DateTimeInterface $confirmedAt = null ) {

        if(empty($leadFieldUpdate))
        {
            return;
        }

        //get lead field configs 
        $leadValueConfigs = explode(',',$leadFieldUpdate );   
        
        //get current lead fields and values
        $leadFields = $lead->getFields(true);

        $leadValues = [];
        foreach($leadValueConfigs as $leadValueConfig)
        {
            list($leadFieldAlias, $leadFieldValue) = array_merge( explode( '=', $leadValueConfig ), array( true ) );

            // we replace tokens if any
            if($leadFieldAlias && $leadFieldValue)
            {
                $timestring = self::timeInDisplayTimezone($confirmedAt);

                //generate token
                $token = bin2hex(openssl_random_pseudo_bytes(16));

                // Ist der Wert einer unserer eigenen Platzhalter? Dann NICHT als
                // Kontaktfeld-Alias interpretieren.
                $isSpecialToken = (bool) preg_match(
                    '/\{(' . implode('|', self::SPECIAL_TOKENS) . ')\}/',
                    $leadFieldValue
                );

                //if $leadFieldValue is token then replace with current lead field value
                if( !$isSpecialToken && preg_match('/\{.*\}/', $leadFieldValue) )
                {
                    $tokenAlias = str_replace(['{','}'],'',$leadFieldValue);
                    if(isset($leadFields[ $tokenAlias ])) {
                        // Mautic 4 lieferte hier den Wert direkt, ab Mautic 5 ein
                        // Array mit der Feldkonfiguration.
                        $leadFieldValue = is_array($leadFields[$tokenAlias])
                            ? ($leadFields[$tokenAlias]['normalizedValue'] ?? '')
                            : $leadFields[$tokenAlias];
                    }
                }

                $leadFieldValue = str_replace('{doi_ip}', (string) $ip, $leadFieldValue);
                $leadFieldValue = str_replace('{doi_timestamp}', $timestring, $leadFieldValue);
                $leadFieldValue = str_replace('{tokenid}', $token, $leadFieldValue);

                $leadValues[$leadFieldAlias] = $leadFieldValue;
            }

        }

        if(!empty($leadValues) && !empty($leadFields)){
            $leadModel->setFieldValues($lead, CustomFieldHelper::fieldsValuesTransformer($leadFields, $leadValues), false);
            $leadModel->saveEntity($lead); 
        }

    }

}
