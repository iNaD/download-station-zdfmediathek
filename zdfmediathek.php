<?php

/**
 * @author Daniel Gehn <me@theinad.com>
 * @version 0.2
 * @copyright 2015 Daniel Gehn
 * @license http://opensource.org/licenses/MIT Licensed under MIT License
 */

class SynoFileHostingZdfMediathek {
    private $Url;
    private $Username;
    private $Password;
    private $HostInfo;

    static $QualityPriority = array(
        'veryhigh'  => 2,
        'high'      => 1,
        'low'       => 0,
    );

    static $UnsupportedFacets = array(
        'hbbtv',
    );

    private $LogPath = '/tmp/zdf-mediathek.log';
    private $LogEnabled = false;

    public function __construct($Url, $Username = '', $Password = '', $HostInfo = '') {
        $this->Url = $Url;
        $this->Username = $Username;
        $this->Password = $Password;
        $this->HostInfo = $HostInfo;

        $this->DebugLog("URL: $Url");
    }

    //This function returns download url.
    public function GetDownloadInfo() {
        $ret = FALSE;

        $this->DebugLog("GetDownloadInfo called");

        $ret = $this->Download();

        return $ret;
    }

    public function onDownloaded()
    {
    }

    public function Verify($ClearCookie = '')
    {
        $this->DebugLog("Verifying User");

        return USER_IS_PREMIUM;
    }

    //This function gets the download url
    private function Download() {
        $hits = array();

        $this->DebugLog("Getting download url for $this->Url");

        if(preg_match('#beitrag/video/([0-9]+)#i', $this->Url, $match) === 1)
        {
            $id = $match[1];

            $this->DebugLog("ID is $id");

            $this->DebugLog("Getting XML data from http://zdf.de/ZDFmediathek/xmlservice/web/beitragsDetails?id=$id&ak=web");

            $curl = curl_init();

            curl_setopt($curl, CURLOPT_URL, 'http://zdf.de/ZDFmediathek/xmlservice/web/beitragsDetails?id=' . $id . '&ak=web');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

            $RawXML = curl_exec($curl);

            if(!$RawXML)
            {
                $this->DebugLog("Failed to retrieve XML. Error Info: " . curl_error($curl));
                return false;
            }

            curl_close($curl);

            $this->DebugLog("Processing XML data");

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

            $DownloadInfo = array();
            $DownloadInfo[DOWNLOAD_URL] = trim($bestFormat['url']);

            return $DownloadInfo;
        }

        $this->DebugLog("Couldn't identify id");

        return FALSE;
    }

    private function DebugLog($message)
    {
        if($this->LogEnabled === true)
        {
            file_put_contents($this->LogPath, $message . "\n", FILE_APPEND);
        }
    }
}
?>
