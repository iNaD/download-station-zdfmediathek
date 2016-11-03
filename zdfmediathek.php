<?php

/**
 * @author Daniel Gehn <me@theinad.com>
 * @version 0.5
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
        'restriction_useragent',
    );

    protected static $ApiBaseUrl = 'https://api.zdf.de';
    protected static $BaseUrl = 'https://www.zdf.de';

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

    protected function dreisat()
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

    protected function zdf()
    {
        $videoPage = $this->curlRequest($this->Url);

        if($videoPage == null) {
            return false;
        }

        $this->DebugLog("got video page");

        $configUrl = $this->getConfigUrl($videoPage);

        if($configUrl == null) {
            return false;
        }

        $this->DebugLog("got config url");

        $metaUrl = $this->getMetaUrl($videoPage);

        if($metaUrl == null) {
            return false;
        }

        $this->DebugLog("got meta url");

        $configRaw = $this->curlRequest($configUrl);

        if($configRaw == null) {
            return false;
        }

        $this->DebugLog("got config");

        $config = json_decode($configRaw);

        $metaRaw = $this->apiRequest($metaUrl, $config->apiToken);

        if($metaRaw == null) {
            return false;
        }

        $this->DebugLog("got meta");

        $meta = json_decode($metaRaw);

        $streamsUri = self::$ApiBaseUrl . $meta->mainVideoContent->{"http://zdf.de/rels/target"}->{"http://zdf.de/rels/streams/ptmd"};

        $streamsRaw = $this->apiRequest($streamsUri, $config->apiToken);

        $streams = json_decode($streamsRaw);

        $bestQuality = array(
            'quality' => null,
            'rating' => -1,
            'uri' => null,
        );

        foreach ($streams->priorityList as $priority) {
            foreach ($priority->formitaeten as $formitaet) {
                if(strpos($formitaet->type, 'mp4_http') !== false) {
                    $unsupportedFacet = false;

                    foreach ($formitaet->facets as $facet) {
                        if(in_array($facet, self::$UnsupportedFacets)) {
                            $unsupportedFacet = true;
                        }
                    }

                    if($unsupportedFacet === false) {
                        foreach ($formitaet->qualities as $quality) {
                            if(isset(self::$QualityPriority[$quality->quality])) {
                                if (self::$QualityPriority[$quality->quality] > $bestQuality['rating']) {
                                    $currentQuality = array(
                                        'quality' => $quality->quality,
                                        'rating' => self::$QualityPriority[$quality->quality],
                                        'uri' => null,
                                    );

                                    foreach ($quality->audio->tracks as $track) {
                                        if ($track->language === "deu") {
                                            $currentQuality['uri'] = $track->uri;
                                            break;
                                        }
                                    }

                                    if ($currentQuality['uri'] !== null) {
                                        $bestQuality = $currentQuality;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if($bestQuality['uri'] !== null) {
            $splitNielsenTitle = explode('|', $meta->tracking->nielsen->content->title);

            $url = $bestQuality['uri'];
            $videoName = $splitNielsenTitle[0] . ' - ' . $splitNielsenTitle[1];

            $filename = $this->buildFilename($url, $videoName);

            $this->DebugLog('Filename based on title "' . $splitNielsenTitle[0] . '" and episodeTitle "' . $splitNielsenTitle[1] . '" is: "' . $filename . '"');

            $DownloadInfo = array();
            $DownloadInfo[DOWNLOAD_URL] = $url;
            $DownloadInfo[DOWNLOAD_FILENAME] = $filename;

            return $DownloadInfo;
        }

        return false;
    }

    protected function processXML($RawXML)
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

        $filename = $this->buildFilename($url, $filename);

        $this->DebugLog('Filename based on title "' . $title . '" and episodeTitle "' . $episodeTitle . '" is: "' . $filename . '"');

        $DownloadInfo = array();
        $DownloadInfo[DOWNLOAD_URL] = $url;
        $DownloadInfo[DOWNLOAD_FILENAME] = $filename;

        return $DownloadInfo;
    }

    protected function getConfigUrl($content) {
        if(preg_match('#"config": "(.*?)",#i', $content, $match) !== 1) {
            return null;
        }

        return self::$BaseUrl . $match[1];
    }

    protected function getMetaUrl($content)
    {
        if(preg_match('#"content": "(.*?)",#i', $content, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    protected function apiRequest($url, $apiToken) {
        $this->DebugLog('API Request to "' . $url . '" with token "' . $apiToken .'"');

        return $this->curlRequest($url, array(
            CURLOPT_HTTPHEADER => array(
                'Api-Auth: Bearer ' . $apiToken
            )
        ));
    }

}
?>
