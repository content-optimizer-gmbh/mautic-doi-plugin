<?php

namespace MauticPlugin\JotaworksDoiBundle\Helper;

class Base64Helper {

    /**
    * AFTER base64 encoding the string, call this function 
    * to build a url safe string
    */    
    public static function prepare_base64_url_encode($input) {
        return strtr($input, '+/=', '._-');
    } 

    /**
    * BEFORE decoding base64 strin, call this function 
    */
    public static function prepare_base64_url_decode($input) {
        return strtr($input, '._-', '+/=');
    }    
}