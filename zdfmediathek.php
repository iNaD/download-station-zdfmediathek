<?php

/**
 * @author Daniel Gehn <me@theinad.com>
 * @version 0.4a
 * @copyright 2015 Daniel Gehn
 * @license http://opensource.org/licenses/MIT Licensed under MIT License
 */

require_once 'provider.php';

class SynoFileHostingZdfMediathek extends TheiNaDProvider {

    static $QualityPriority = array(
        'veryhigh'  => 2,
        'high'      => 1,
        'low'       => 0,
    );

    static $UnsupportedFacets = array(
        'hbbtv',
    );

    protected $LogPath = '/tmp/zdf-mediathek.log';

    //This function gets the download url
    public function GetDownloadInfo() {

        $this->DebugLog("Getting download url for $this->Url");

        if(strpos($this->Url, 'zdf.de') !== false)
        {
            return $this->zdf();
        }

        if(strpos($this->Url, '3sat.de') !== false)
        {
            return $this->dreisat();
        }

        return FALSE;
    }

    private function dreisat()
    {
        if(preg_match('#mediathek\/(?:.*)obj=(\d+)#i', $this->Url, $match) === 1)
        {
            $id = $match[1];

            $this->DebugLog("ID is $id");

            $this->DebugLog("Getting XML data from http://www.3sat.de/mediathek/xmlservice/web/beitragsDetails?ak=web&id=$id&ak=web");

            $RawXML = $this->curlRequest('http://www.3sat.de/mediathek/xmlservice/web/beitragsDetails?ak=web&id=' . $id . '&ak=web');

            if($RawXML === null)
            {
                return false;
            }

            $this->DebugLog("Processing XML data");

            return $this->processXML($RawXML);
        }

        $this->DebugLog("Couldn't identify id");

        return FALSE;
    }

    private function zdf()
    {
        if(preg_match('#beitrag/video/([0-9]+)#i', $this->Url, $match) === 1)
        {
            $id = $match[1];

            $this->DebugLog("ID is $id");

            $this->DebugLog("Getting XML data from http://zdf.de/ZDFmediathek/xmlservice/web/beitragsDetails?id=$id&ak=web");

            $RawXML = $this->curlRequest('http://zdf.de/ZDFmediathek/xmlservice/web/beitragsDetails?id=' . $id . '&ak=web');

            if($RawXML === null)
            {
                return false;
            }

            $this->DebugLog("Processing XML data");

            return $this->processXML($RawXML);
        }

        $this->DebugLog("Couldn't identify id");

        return FALSE;
    }

    private function processXML($RawXML)
    {
        $match = array();

        preg_match('#<statuscode>(.*?)</statuscode>#i', $RawXML, $match);

        if($match[1] !== 'ok') {
            $this->DebugLog("Status not ok, Statuscode " . $match[1]);
            return ERR_FILE_NO_EXIST;
        }

        $bestFormat = array(
            'quality'   => -1,
            'bitrate'   => -1,
            'url'       => '',
        );

        $matches = array();

        preg_match_all('#<formitaet basetype="(.*?)".*?>(.*?)</formitaet>#is', $RawXML, $matches);

        foreach($matches[1] as $index => $basetype)
        {
            if(strpos($basetype, 'mp4_http') !== false)
            {
                $match = array();
                preg_match('#<facet>(.*?)</facet>#is', $matches[2][$index], $match);

                if(in_array($match[1], self::$UnsupportedFacets))
                {
                    continue;
                }

                $match = array();
                preg_match('#<quality>(.*?)</quality>#is', $matches[2][$index], $match);

                $quality = self::$QualityPriority[$match[1]];

                $match = array();
                preg_match('#<videoBitrate>(.*?)</videoBitrate>#is', $matches[2][$index], $match);

                $bitrate = $match[1];

                if($quality >= $bestFormat['quality'] && $bitrate > $bestFormat['bitrate'])
                {
                    $match = array();
                    preg_match('#<url>(.*?)</url>#is', $matches[2][$index], $match);

                    $url = $match[1];

                    $bestFormat = array(
                        'quality'   => $quality,
                        'bitrate'   => $bitrate,
                        'url'       => $url,
                    );
                }
            }
        }

        if($bestFormat['url'] === '')
        {
            $this->DebugLog('No format found');
            return false;
        }

        $this->DebugLog('Best format is ' . json_encode($bestFormat));

        $url = trim($bestFormat['url']);

        $match = array();
        $title = '';
        $episodeTitle = '';
        $filename = '';
        $pathinfo = pathinfo($url);

        if(preg_match('#<originChannelTitle>(.*?)<\/originChannelTitle>#i', $RawXML, $match) == 1)
        {
            $title = $match[1];
            $filename = $title;
        }

        $match = array();

        if(preg_match('#<title>(.*?)<\/title>#i', $RawXML, $match) == 1)
        {
            $episodeTitle = $match[1];
            $filename .= ' - ' . $episodeTitle;
        }
        else
        {
            $filename .= ' - ' . $pathinfo['basename'];
        }


        if(empty($filename))
        {
            $filename = $pathinfo['basename'];
        }
        else
        {
            $filename .= '.' . $pathinfo['extension'];
        }

        $this->DebugLog('Filename based on title "' . $title . '" and episodeTitle "' . $episodeTitle . '" is: "' . $filename . '"');

        $DownloadInfo = array();
        $DownloadInfo[DOWNLOAD_URL] = $url;
        $DownloadInfo[DOWNLOAD_FILENAME] = $this->safeFilename($filename);

        return $DownloadInfo;
    }

}
?>
