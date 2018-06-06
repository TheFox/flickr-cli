<?php

namespace TheFox\FlickrCli\Command;

use Exception;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Rezzza\Flickr\ApiFactory;
use Guzzle\Http\Client as GuzzleHttpClient;
use Guzzle\Stream\PhpStreamRequestFactory;
use Rych\ByteSize\ByteSize;
use TheFox\FlickrCli\FlickrCli;

class DownloadCommand extends FlickrCliCommand
{
    /**
     * @var string The destination directory for downloaded files. No trailing slash.
     */
    private $destinationPath;

    /**
     * @var bool Whether to download even if a local copy already exists.
     */
    private $forceDownload;

    protected function configure()
    {
        parent::configure();

        $this->setName('download');
        $this->setDescription('Download files from Flickr.');

        $this->addOption('destination', 'd', InputOption::VALUE_OPTIONAL, 'Path to save files. Default: photosets');

        $idDirsDescr = 'Save downloaded files into ID-based directories. Default is to group by Album titles instead.';
        $this->addOption('id-dirs', 'i', InputOption::VALUE_NONE, $idDirsDescr);

        $forceDescr = 'Force Flickr CLI to download photos even if they already exist locally. ';
        $forceDescr .= 'Default is to skip existing downloads.';
        $this->addOption('force', 'f', InputOption::VALUE_NONE, $forceDescr);

        $this->addArgument('photosets', InputArgument::IS_ARRAY, 'Photosets to download.');

        $this->destinationPath = 'photosets';
    }

    /**
     * Executes the download command.
     *
     * @param InputInterface $input An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     * @return int 0 if everything went fine, or an error code.
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->setupDestination();

        // Force download?
        $this->forceDownload = $input->getOption('force');

        // Run the actual download.
        if ($input->getOption('id-dirs')) {
            // If downloaded files should be saved into download-dir/hash/hash/photo-id/ directories.
            $exit = $this->downloadById();
        } else {
            // If download directories should match Album titles.
            $exit = $this->downloadByAlbumTitle();
        }

        return $exit;
    }

    private function setupDestination()
    {
        $filesystem = new Filesystem();

        // Destination directory. Default to 'photosets'.
        $customDestDir = $this->getInput()->getOption('destination');
        if (!empty($customDestDir)) {
            $this->destinationPath = rtrim($customDestDir, '/');
        }
        if (!$filesystem->exists($this->destinationPath)) {
            $filesystem->mkdir($this->destinationPath, 0755);
        }
    }

    /**
     * Download photos to directories named after the album (i.e. photoset, in the original parlance).
     *
     * @return int
     * @throws Exception
     */
    private function downloadByAlbumTitle(): int
    {
        $this->getLogger()->info(sprintf('Downloading to Album-based directories in: %s', $this->destinationPath));

        $apiService = $this->getApiService();
        $apiFactory = $apiService->getApiFactory();
        $xml = $apiFactory->call('flickr.photosets.getList');

        $photosets = $this->getInput()->getArgument('photosets');
        if (!is_array($photosets)) {
            throw new RuntimeException('photosets is not an array');
        }

        $photosetsInUse = [];
        if (count($photosets)) {
            $photosetTitles = $apiService->getPhotosetTitles();

            foreach ($photosets as $argPhotosetTitle) {
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                if (!in_array($argPhotosetTitle, $photosetTitles)) {
                    continue;
                }

                $photosetsInUse[] = $argPhotosetTitle;
            }

            foreach ($photosets as $argPhotosetTitle) {
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                if (in_array($argPhotosetTitle, $photosetsInUse)) {
                    continue;
                }

                foreach ($photosetTitles as $photosetTitle) {
                    if (!fnmatch($argPhotosetTitle, $photosetTitle)) {
                        continue;
                    }

                    $photosetsInUse[] = $photosetTitle;
                }
            }
        } else {
            foreach ($xml->photosets->photoset as $photoset) {
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                $photosetsInUse[] = $photoset->title;
            }
        }

        $filesystem = new Filesystem();
        $totalBytesDownloaded = 0;
        $totalFiles = 0;

        /** @var $photoset SimpleXMLElement */
        foreach ($xml->photosets->photoset as $photoset) {
            pcntl_signal_dispatch();
            if ($this->getExit()) {
                break;
            }

            if (!in_array($photoset->title, $photosetsInUse)) {
                continue;
            }

            $photosetId = (int)$photoset->attributes()->id;
            $photosetTitle = (string)$photoset->title;
            $this->getLogger()->info(sprintf('[photoset] %s', $photosetTitle));

            $destinationPath = sprintf('%s/%s', $this->destinationPath, $photosetTitle);

            if (!$filesystem->exists($destinationPath)) {
                $this->getLogger()->info(sprintf('[dir] create: %s', $destinationPath));
                $filesystem->mkdir($destinationPath);
            }

            $this->getLogger()->info(sprintf('[photoset] %s: get photo list', $photosetTitle));
            $xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', [
                'photoset_id' => $photosetId,
            ]);
            $xmlPhotoListPagesTotal = (int)$xmlPhotoList->photoset->attributes()->pages;
            // $xmlPhotoListPhotosTotal = (int)$xmlPhotoList->photoset->attributes()->total;

            $photosetFileCount = 0;

            for ($page = 1; $page <= $xmlPhotoListPagesTotal; $page++) {
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                $this->getLogger()->info(sprintf('[page] %d', $page));

                if ($page > 1) {
                    $this->getLogger()->info(sprintf('[photoset] %s: get photo list', $photosetTitle));
                    $xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', [
                        'photoset_id' => $photosetId,
                        'page' => $page,
                    ]);
                }

                /** @var $photo SimpleXMLElement */
                foreach ($xmlPhotoList->photoset->photo as $photo) {
                    pcntl_signal_dispatch();
                    if ($this->getExit()) {
                        break;
                    }

                    $this->getLogger()->debug(sprintf('[media] %d/%d photo %s', $page, $photosetFileCount, $photo['id']));
                    $downloaded = $this->downloadPhoto($photo, $destinationPath);
                    if ($downloaded && isset($downloaded->filesize)) {
                        $totalBytesDownloaded += $downloaded->filesize;
                    }
                    ++$photosetFileCount;
                    ++$totalFiles;
                }
            }
        }

        if ($totalBytesDownloaded > 0) {
            $bytesize = new ByteSize();
            $totalDownloadedMsg = $bytesize->format($totalBytesDownloaded);
        } else {
            $totalDownloadedMsg = 0;
        }

        $this->getLogger()->info(sprintf('[main] total downloaded: %s (%d)', $totalDownloadedMsg, $totalBytesDownloaded));
        $this->getLogger()->info(sprintf('[main] total files:      %d', $totalFiles));
        $this->getLogger()->info('[main] exit');

        return $this->getExit();
    }

    /**
     * Download a single given photo from Flickr. Won't be downloaded if already exists locally; if it is downloaded the
     * additional 'filesize' property will be set on the return element.
     *
     * @param SimpleXMLElement $photo
     * @param string $destinationPath The full filesystem path to the directory into which to download the photo. Must
     * already exist.
     * @param string $basename The filename to save the downloaded file to (without extension).
     * @return SimpleXMLElement|boolean Photo metadata as returned by Flickr, or false if something went wrong.
     * @throws Exception
     */
    protected function downloadPhoto(SimpleXMLElement $photo, string $destinationPath, string $basename = null)
    {
        $id = (string)$photo->attributes()->id;

        $apiFactory = $this->getApiService()->getApiFactory();

        try {
            $xmlPhoto = $apiFactory->call('flickr.photos.getInfo', [
                'photo_id' => $id,
                'secret' => (string)$photo->attributes()->secret,
            ]);
            if (!$xmlPhoto) {
                return false;
            }
        } catch (Exception $e) {
            $this->getLogger()->error(sprintf(
                '%s, GETINFO FAILED: %s',
                $id,
                $e->getMessage()
            ))
            ;

            return false;
        }

        if (isset($xmlPhoto->photo->title) && (string)$xmlPhoto->photo->title) {
            $title = (string)$xmlPhoto->photo->title;
        } else {
            $title = '';
        }

        $server = (string)$xmlPhoto->photo->attributes()->server;
        $farm = (string)$xmlPhoto->photo->attributes()->farm;
        $originalSecret = (string)$xmlPhoto->photo->attributes()->originalsecret;
        $originalFormat = (string)$xmlPhoto->photo->attributes()->originalformat;
        $description = (string)$xmlPhoto->photo->description;
        $media = (string)$xmlPhoto->photo->attributes()->media;

        // Set the filename.
        if (empty($basename)) {
            $fileName = sprintf('%s.%s', $title ? $title : $id, $originalFormat);
        } else {
            $fileName = sprintf('%s.%s', $basename, $originalFormat);
        }
        $filePath = sprintf('%s/%s', rtrim($destinationPath, '/'), $fileName);
        $filePathTmp = sprintf('%s/%s.%s.tmp', $destinationPath, $id, $originalFormat);

        $filesystem = new Filesystem();
        if ($filesystem->exists($filePath) && !$this->forceDownload) {
            $this->getLogger()->debug(sprintf('File %s already downloaded to %s', $id, $filePath));

            /** @var SimpleXMLElement $photo */
            $photo = $xmlPhoto->photo;

            return $photo;
        }

        // URL format for the original image. See https://www.flickr.com/services/api/misc.urls.html
        // https://farm{farm-id}.staticflickr.com/{server-id}/{id}_{o-secret}_o.(jpg|gif|png)
        $urlFormat = 'https://farm%s.staticflickr.com/%s/%s_%s_o.%s';
        $url = sprintf($urlFormat, $farm, $server, $id, $originalSecret, $originalFormat);

        if ($media == 'video') {
            $this->getLogger()->error('video not supported yet');
            return false;
        }

        $client = new GuzzleHttpClient($url);

        $streamRequestFactory = new PhpStreamRequestFactory();
        try {
            $request = $client->get();
            $stream = $streamRequestFactory->fromRequest($request);
        } catch (Exception $e) {
            $this->getLogger()->error(sprintf(
                '[%s] %s, farm %s, server %s, %s FAILED: %s',
                $media,
                $id,
                $farm,
                $server,
                $fileName,
                $e->getMessage()
            ))
            ;

            return false;
        }

        $size = $stream->getSize();
        if (false !== $size) {
            $bytesize = new ByteSize();
            $sizeStr = $bytesize->format((int)$size);
        } else {
            $sizeStr = 'N/A';
        }

        $this->getLogger()->info(sprintf(
            "[%s] %s, farm %s, server %s, %s, '%s', %s",
            $media,
            $id,
            $farm,
            $server,
            $fileName,
            $description,
            $sizeStr
        ))
        ;

        $timePrev = time();
        $downloaded = 0;
        $downloadedPrev = 0;
        $downloadedDiff = 0;

        $fh = fopen($filePathTmp, 'wb');
        if (false === $fh) {
            throw new RuntimeException(sprintf('Unable to open %s for writing.', $filePathTmp));
        }
        while (!$stream->feof()) {
            pcntl_signal_dispatch();
            if ($this->getExit() >= 2) {
                break;
            }

            $data = $stream->read(FlickrCli::DOWNLOAD_STREAM_READ_LEN);
            $dataLen = strlen($data);
            fwrite($fh, $data);

            $downloaded += $dataLen;

            if ($size !== false) {
                $percent = $downloaded / $size * 100;
            } else {
                $percent = 0;
            }
            if ($percent > 100) {
                $percent = 100;
            }

            $progressbarDownloaded = round($percent / 100 * FlickrCli::DOWNLOAD_PROGRESSBAR_ITEMS);
            $progressbarRest = FlickrCli::DOWNLOAD_PROGRESSBAR_ITEMS - $progressbarDownloaded;

            $timeCur = time();
            if ($timeCur != $timePrev) {
                $timePrev = $timeCur;
                $downloadedDiff = $downloaded - $downloadedPrev;
                $downloadedPrev = $downloaded;
            }

            $downloadedDiffStr = '';
            if ($downloadedDiff) {
                $bytesize = new ByteSize();
                $downloadedDiffStr = $bytesize->format($downloadedDiff) . '/s';
            }

            if ($size !== false) {
                // If we know the stream size, show a progress bar.
                printf(
                    "[file] %6.2f%% [%s%s] %s %10s\x1b[0K\r",
                    $percent,
                    str_repeat('#', $progressbarDownloaded),
                    str_repeat(' ', $progressbarRest),
                    number_format($downloaded),
                    $downloadedDiffStr
                );
            } else {
                // Otherwise, just show the amount downloaded and speed.
                printf("[file] %s %10s\x1b[0K\r", number_format($downloaded), $downloadedDiffStr);
            }
        }
        fclose($fh);
        print "\n";

        // Inform the user about the signal.
        if ($this->getExit()) {
            $this->getLogger()->info(sprintf('Catched a Signal while downloading. [%d]', $this->getExit()));
        }

        if (!$filesystem->exists($filePathTmp)) {
            $this->getLogger()->error(sprintf('[%s] %s FAILED: temp file does not exist: %s', $media, $id, $filePathTmp));
            return false;
        }

        $fileTmpSize = filesize($filePathTmp);

        if (($size && $fileTmpSize != $size) || $fileTmpSize <= 1024) {
            $filesystem->remove($filePathTmp);

            $this->getLogger()->error(sprintf('[%s] %s FAILED: temp file size wrong: %d', $media, $id, $fileTmpSize));
        } else {
            // Rename to its final destination, and return the photo metadata.
            $filesystem->rename($filePathTmp, $filePath, $this->forceDownload);
            $xmlPhoto->photo->filesize = $fileTmpSize;

            /** @var SimpleXMLElement $photo */
            $photo = $xmlPhoto->photo;

            return $photo;
        }

        return false;
    }

    /**
     * Download all photos, whether in a set/album or not, into directories named by photo ID.
     */
    private function downloadById()
    {
        $this->getLogger()->info(sprintf('Downloading to ID-based directories in: %s', $this->destinationPath));

        $apiFactory = $this->getApiService()->getApiFactory();

        // 1. Download any photos not in a set.
        $notInSetPage = 1;
        do {
            pcntl_signal_dispatch();
            if ($this->getExit()) {
                break;
            }

            $notInSet = $apiFactory->call('flickr.photos.getNotInSet', ['page' => $notInSetPage]);
            $pages = (int)$notInSet->photos['pages'];
            $this->getLogger()->info(sprintf('Not in set p%s/%d', $notInSetPage, $pages));

            $notInSetPage++;
            foreach ($notInSet->photos->photo as $photo) {
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                $this->downloadPhotoById($photo);
            }
        } while ($notInSetPage <= $notInSet->photos['pages']);

        // 2. Download all photos in all sets.
        $setsPage = 1;
        do {
            pcntl_signal_dispatch();
            if ($this->getExit()) {
                break;
            }

            $sets = $apiFactory->call('flickr.photosets.getList', ['page' => $setsPage]);
            $pages = (int)$sets->photosets['pages'];
            $this->getLogger()->info(sprintf('Sets p%d/%d', $setsPage, $pages));

            foreach ($sets->photosets->photoset as $set) {
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                // Loop through all pages in this set.
                $setPhotosPage = 1;
                do {
                    pcntl_signal_dispatch();
                    if ($this->getExit()) {
                        break;
                    }

                    $params = [
                        'photoset_id' => $set['id'],
                        'page' => $setPhotosPage,
                    ];
                    $setPhotos = $apiFactory->call('flickr.photosets.getPhotos', $params);

                    $title = (string)$set->title;
                    $total = (int)$setPhotos->photoset['total'];
                    $setPages = (int)$setPhotos->photoset['pages'];

                    $this->getLogger()->info(sprintf(
                        '[Set %s] %s photos (p%s/%s)',
                        $title,
                        $total,
                        $setPhotosPage,
                        $setPages
                    ))
                    ;
                    foreach ($setPhotos->photoset->photo as $photo) {
                        pcntl_signal_dispatch();
                        if ($this->getExit()) {
                            break;
                        }

                        $this->downloadPhotoById($photo);
                    }
                    $setPhotosPage++;
                } while ($setPhotosPage <= $setPhotos->photos['pages']);
            }
            $setsPage++;
        } while ($setsPage <= (int)$sets->photosets['pages']);

        return $this->getExit();
    }

    /**
     * Download a single photo.
     *
     * @param SimpleXMLElement $photo Basic photo metadata.
     * @throws Exception
     */
    private function downloadPhotoById(SimpleXMLElement $photo)
    {
        $id = $photo['id'];
        $idHash = md5($id);
        $destinationPath = sprintf(
            '%s/%s%s/%s%s/%s',
            $this->destinationPath,
            $idHash[0],
            $idHash[1],
            $idHash[2],
            $idHash[3],
            $id
        );

        $filesystem = new Filesystem();
        if (!$filesystem->exists($destinationPath)) {
            $filesystem->mkdir($destinationPath, 0755);
        }

        // Save the actual file.
        $apiFactory = $this->getApiService()->getApiFactory();
        $photo = $this->downloadPhoto($photo, $destinationPath, $id);
        if (false === $photo) {
            $this->getLogger()->error(sprintf('Unable to get metadata about photo: %s', $id));
            return;
        }

        $fn = $this->getMappingFunction($apiFactory);

        $metadata = $fn($photo);

        $content = Yaml::dump($metadata);
        $filesystem->dumpFile(sprintf('%s/metadata.yml', $destinationPath), $content);
    }

    /**
     * @param ApiFactory $apiFactory
     * @return \Closure
     */
    private function getMappingFunction(ApiFactory $apiFactory)
    {
        /**
         * @param SimpleXMLElement $photo
         * @return array
         */
        $fn = function (SimpleXMLElement $photo) use ($apiFactory) {
            // Metadata
            $metadataFn = $this->getMetadataMappingFunction();
            $metadata = $metadataFn($photo);

            if (isset($photo->photo->description->_content)) {
                $metadata['description'] = (string)$photo->photo->description->_content;
            }

            // Tags
            if (isset($photo->tags->tag)) {
                foreach ($photo->tags->tag as $tag) {
                    $metadata['tags'][] = [
                        'id' => (string)$tag['id'],
                        'slug' => (string)$tag,
                        'title' => (string)$tag['raw'],
                        'machine' => $tag['machine_tag'] !== '0',
                    ];
                }
            }

            // Location
            if (isset($photo->location)) {
                $metadata['location'] = [
                    'latitude' => (float)$photo->location['latitude'],
                    'longitude' => (float)$photo->location['longitude'],
                    'accuracy' => (integer)$photo->location['accuracy'],
                ];
            }

            // Contexts
            $contexts = $apiFactory->call('flickr.photos.getAllContexts', ['photo_id' => $photo['id']]);
            foreach ($contexts->set as $set) {
                $metadata['sets'][] = [
                    'id' => (string)$set['id'],
                    'title' => (string)$set['title'],
                ];
            }

            // Pools
            foreach ($contexts->pool as $pool) {
                $metadata['pools'][] = [
                    'id' => (string)$pool['id'],
                    'title' => (string)$pool['title'],
                    'url' => (string)$pool['url'],
                ];
            }

            return $metadata;
        };

        return $fn;
    }

    /**
     * @return \Closure
     */
    private function getMetadataMappingFunction()
    {
        /**
         * @param SimpleXMLElement $photo
         * @return array
         */
        $fn = function (SimpleXMLElement $photo) {
            $metadata = [
                'id' => (int)$photo['id'],
                'title' => (string)$photo->title,
                'license' => (string)$photo['license'],
                'safety_level' => (string)$photo['safety_level'],
                'rotation' => (string)$photo['rotation'],
                'media' => (string)$photo['media'],
                'format' => (string)$photo['originalformat'],
                'owner' => [
                    'nsid' => (string)$photo->owner['nsid'],
                    'username' => (string)$photo->owner['username'],
                    'realname' => (string)$photo->owner['realname'],
                    'path_alias' => (string)$photo->owner['path_alias'],
                ],
                'visibility' => [
                    'ispublic' => (boolean)$photo->visibility['ispublic'],
                    'isfriend' => (boolean)$photo->visibility['isfriend'],
                    'isfamily' => (boolean)$photo->visibility['isfamily'],
                ],
                'dates' => [
                    'posted' => (string)$photo->dates['posted'],
                    'taken' => (string)$photo->dates['taken'],
                    'takengranularity' => (int)$photo->dates['takengranularity'],
                    'takenunknown' => (string)$photo->dates['takenunknown'],
                    'lastupdate' => (string)$photo->dates['lastupdate'],
                    'uploaded' => (string)$photo['dateuploaded'],
                ],
                'tags' => [],
                'sets' => [],
                'pools' => [],
            ];
            return $metadata;
        };

        return $fn;
    }
}
