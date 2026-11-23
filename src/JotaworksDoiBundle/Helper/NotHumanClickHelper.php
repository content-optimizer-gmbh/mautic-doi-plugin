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

        return $this->getMarkerDir().'/doi_'.$hash.'.log';
    }

    /**
     * Directory of the bait markers. Default is the Symfony cache dir; JW_DOI_MARKER_DIR moves them
     * out of it (mautic_docker: one cache per release, so a release switch or cache:clear would drop
     * markers that web-frontend wrote and the worker still has to read).
     */
    protected function getMarkerDir(): string
    {
        $dir = getenv('JW_DOI_MARKER_DIR');
        if ($dir && (is_dir($dir) || @mkdir($dir, 0770, true))) {
            return rtrim($dir, '/');
        }

        return $this->pathsHelper->getSystemPath('cache');
    }

    /** Max. number of bait hit timestamps kept per marker (scanners may hit the link repeatedly). */
    public const MAX_STORED_HITS = 20;

    /**
     * Records a request of the bait link. The marker file holds one Unix timestamp per line
     * (2.2.0); markers written by older versions contain the hash instead and are read via filemtime.
     */
    public function setClick($hash)
    {
        if (!$hash) {
            return;
        }

        $hits   = $this->getHitTimes($hash);
        $hits[] = time();
        $this->writeHits($hash, $hits);
    }

    /**
     * @return int[] timestamps of the recorded bait hits, oldest first
     */
    public function getHitTimes($hash): array
    {
        if (!$hash) {
            return [];
        }

        $absFilename = $this->buildFilename($hash);
        if (!is_file($absFilename)) {
            return [];
        }

        $content = (string) @file_get_contents($absFilename);
        $hits    = [];
        foreach (preg_split('/\R/', trim($content)) as $line) {
            if (ctype_digit($line)) {
                $hits[] = (int) $line;
            }
        }

        // Legacy marker (content = hash): the file time is the hit time.
        if (!$hits && '' !== $content) {
            $mtime = @filemtime($absFilename);
            $hits  = false !== $mtime ? [$mtime] : [];
        }

        sort($hits);

        return $hits;
    }

    /**
     * True if the bait link was requested within [$from, $to] (inclusive).
     */
    public function hasHitBetween($hash, int $from, int $to): bool
    {
        foreach ($this->getHitTimes($hash) as $hitAt) {
            if ($hitAt >= $from && $hitAt <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes all hits up to and including $until; deletes the marker when none are left.
     *
     * @return int number of removed hits
     */
    public function removeHitsUntil($hash, int $until): int
    {
        $hits = $this->getHitTimes($hash);
        $keep = array_values(array_filter($hits, static fn (int $hitAt): bool => $hitAt > $until));

        if (count($keep) === count($hits)) {
            return 0;
        }

        if (!$keep) {
            $this->reset($hash);
        } else {
            $this->writeHits($hash, $keep);
        }

        return count($hits) - count($keep);
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

    /**
     * @param int[] $hits
     */
    private function writeHits($hash, array $hits): void
    {
        sort($hits);
        $hits = array_slice($hits, -self::MAX_STORED_HITS);

        file_put_contents($this->buildFilename($hash), implode("\n", $hits)."\n", LOCK_EX);
    }

}
