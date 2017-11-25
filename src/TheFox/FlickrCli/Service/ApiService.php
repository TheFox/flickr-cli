<?php

namespace TheFox\FlickrCli\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Metadata;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;
use SimpleXMLElement;

class ApiService extends AbstractService
{
    /**
     * @var string
     */
    private $consumerKey;

    /**
     * @var string
     */
    private $consumerSecret;

    /**
     * @var string
     */
    private $token;

    /**
     * @var string
     */
    private $tokenSecret;

    /**
     * ApiService constructor.
     * @param string $consumerKey
     * @param string $consumerSecret
     * @param string $token
     * @param string $tokenSecret
     */
    public function __construct($consumerKey, $consumerSecret, $token, $tokenSecret)
    {
        $this->setLogger(new NullLogger());

        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->token = $token;
        $this->tokenSecret = $tokenSecret;
    }

    /**
     * @return ApiFactory
     */
    public function getApiFactory(): ApiFactory
    {
        // Set up the Flickr API.
        $metadata = new Metadata($this->consumerKey, $this->consumerSecret);
        $metadata->setOauthAccess($this->token, $this->tokenSecret);
        $adapter = new RezzzaGuzzleAdapter();
        $apiFactory = new ApiFactory($metadata, $adapter);

        return $apiFactory;
    }

    /**
     * @return array
     */
    public function getPhotosetTitles(): array
    {
        $apiFactory = $this->getApiFactory();

        $this->getLogger()->info('[main] get photosets');
        $xml = $apiFactory->call('flickr.photosets.getList');

        /**
         * @var int $n
         * @var SimpleXMLElement $photoset
         */
        foreach ($xml->photosets->photoset as $n => $photoset) {
            $id = (int)$photoset->attributes()->id;
            $photosetsTitles[$id] = (string)$photoset->title;
        }

        asort($photosetsTitles);

        return $photosetsTitles;
    }
}
