<?php

namespace Zendesk\API;

/**
 * HTTP functions via curl
 * @package Zendesk\API
 */
class Http
{
    public static $curl;

    /**
     * Prepares an endpoint URL with optional side-loading
     *
     * @param string $endPoint
     * @param array $sideload
     * @param array $iterators
     *
     * @return string
     */
    public static function prepareQueryParams(array $sideload = null, array $iterators = null)
    {
        $addParams = array();
        // First look for side-loaded variables
        if (is_array($sideload)) {
            $addParams['include'] = implode(',', $sideload);
        }

        // Next look for special collection iterators
        if (is_array($iterators)) {
            foreach ($iterators as $k => $v) {
                if (in_array($k, array('per_page', 'page', 'sort_order', 'sort_by'))) {
                    $addParams[$k] = $v;
                }
            }
        }

        return $addParams;
    }

    /**
     * Use the send method to call every endpoint except for oauth/tokens
     *
     * @param HttpClient $client
     * @param string $endPoint E.g. "/tickets.json"
     * @param array $options
     *          Available options are listed below:
     *          array $queryParams Array of unencoded key-value pairs, e.g. ["ids" => "1,2,3,4"]
     *          array $postFields Array of unencoded key-value pairs, e.g. ["filename" => "blah.png"]
     *          string $method "GET", "POST", etc. Default is GET.
     *          string $contentType Default is "application/json"
     *
     * @return array The response body, parsed from JSON into an associative array
     */
    public static function send_with_options(
      HttpClient $client,
      $endPoint,
      $options = []
    ) {
        $options = array_merge(
          [
            'method'      => 'GET',
            'contentType' => 'application/json',
            'postFields'  => [],
            'queryParams' => [],
            'file'        => null,
          ],
          $options
        );

        $requestOptions = [
          'headers' => [
            'Accept'       => 'application/json',
            'Content-Type' => $options['contentType']
          ]
        ];

        if ( ! empty($options['queryParams'])) {
            $requestOptions['query'] = $options['queryParams'];
        }

        if ( ! empty($options['postFields'])) {
            $requestOptions['body'] = json_encode($options['postFields']);
        } elseif ( ! empty($options['file'])) {
            if (file_exists($options['file'])) {
                $file                   = fopen($options['file'], 'r');
                $requestOptions['body'] = $file;
            }
        }

        $request = $client->guzzle->createRequest(
          $options['method'],
          $client->getApiUrl() . $endPoint,
          $requestOptions
        );

        try {
            $response = $client->guzzle->send($request);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            var_dump($e->getRequest()->getUri());
            var_dump($e->getResponse()->getStatusCode());
        }

        $responseCode = $response->getStatusCode();
        $parsedResponseBody = json_decode($response->getBody());

        $client->setDebug(
          $response->getHeaders(),
          $responseCode,
          10,
          null
        );

        if (isset($file)) {
            fclose($file);
        }

        return $parsedResponseBody;
    }

    /**
     * Specific case for OAuth. Run /oauth.php via your browser to get an access token
     *
     * @param HttpClient $client
     * @param string $code
     * @param string $oAuthId
     * @param string $oAuthSecret
     *
     * @throws \Exception
     *
     * @return mixed
     */
    public static function oauth(HttpClient $client, $code, $oAuthId, $oAuthSecret)
    {
        $url = 'https://' . $client->getSubdomain() . '.zendesk.com/oauth/tokens';
        $method = 'POST';

        $curl = (isset(self::$curl)) ? self::$curl : new CurlRequest;
        $curl->setopt(CURLOPT_URL, $url);
        $curl->setopt(CURLOPT_POST, true);
        $curl->setopt(CURLOPT_POSTFIELDS, json_encode(array(
          'grant_type'    => 'authorization_code',
          'code'          => $code,
          'client_id'     => $oAuthId,
          'client_secret' => $oAuthSecret,
          'redirect_uri'  => ($_SERVER['HTTPS'] ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'],
          'scope'         => 'read'
        )));
        $curl->setopt(CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $curl->setopt(CURLINFO_HEADER_OUT, true);
        $curl->setopt(CURLOPT_RETURNTRANSFER, true);
        $curl->setopt(CURLOPT_CONNECTTIMEOUT, 30);
        $curl->setopt(CURLOPT_TIMEOUT, 30);
        $curl->setopt(CURLOPT_SSL_VERIFYPEER, false);
        $curl->setopt(CURLOPT_HEADER, true);
        $curl->setopt(CURLOPT_VERBOSE, true);
        $curl->setopt(CURLOPT_FOLLOWLOCATION, true);
        $curl->setopt(CURLOPT_MAXREDIRS, 3);
        $response = $curl->exec();
        if ($response === false) {
            throw new \Exception(sprintf('Curl error message: "%s" in %s', $curl->error(), __METHOD__));
        }
        $headerSize = $curl->getinfo(CURLINFO_HEADER_SIZE);
        $responseBody = substr($response, $headerSize);
        $responseObject = json_decode($responseBody);
        $client->setDebug(
          $curl->getinfo(CURLINFO_HEADER_OUT),
          $curl->getinfo(CURLINFO_HTTP_CODE),
          substr($response, 0, $headerSize),
          (isset($responseObject->error) ? $responseObject : null)
        );
        $curl->close();
        self::$curl = null;

        return $responseObject;
    }
}
