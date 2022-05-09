<?php

namespace MauticPlugin\JotaworksDoiBundle\Helper;

use Mautic\CoreBundle\Helper\PathsHelper;

class NotHumanClickHelper {

    protected $pathsHelper;

    public function __construct(PathsHelper $pathsHelper) 
    {
        $this->pathsHelper = $pathsHelper;
    }

    protected function filter_filename($filename) {
        // sanitize filename
        $filename = preg_replace(
            '~
            [<>:"/\\\|?*]|            # file system reserved https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
            [\x00-\x1F]|             # control characters http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
            [\x7F\xA0\xAD]|          # non-printing characters DEL, NO-BREAK SPACE, SOFT HYPHEN
            [#\[\]@!$&\'()+,;=]|     # URI reserved https://www.rfc-editor.org/rfc/rfc3986#section-2.2
            [{}^\~`]                 # URL unsafe characters https://www.ietf.org/rfc/rfc1738.txt
            ~x',
            '-', $filename);
        // avoids ".", ".." or ".hiddenFiles"
        $filename = ltrim($filename, '.-');

        // maximize filename length to 255 bytes http://serverfault.com/a/9548/44086
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $filename = mb_strcut(pathinfo($filename, PATHINFO_FILENAME), 0, 255 - ($ext ? strlen($ext) + 1 : 0), mb_detect_encoding($filename)) . ($ext ? '.' . $ext : '');
        return $filename;
    }

    protected function buildFilename($hash) 
    {
        $hash = $this->filter_filename($hash);
        return $this->pathsHelper->getSystemPath('cache').'/doi_'.$hash.'.log';
    }

    public function setClick($hash) 
    {
        if($hash)
        {
            file_put_contents( $this->buildFilename($hash), $hash );
        }       
    }

    public function isRunning($hash)
    {
        if(!$hash)
        {
            return false;
        }

        return file_exists( $this->buildFilename($hash) );
    }

    public function reset($hash)
    {
        if(!$hash)
        {
            return false;
        }
        $absFilename = $this->buildFilename($hash);

        if( file_exists($absFilename) )
        {
            unlink( $absFilename );
        } 
        
    }

}