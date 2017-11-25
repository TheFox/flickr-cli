<?php

namespace TheFox\FlickrCli\Command;

use Exception;
use SimpleXMLElement;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Finder\Finder;
use Rezzza\Flickr\Metadata;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;
use Guzzle\Http\Client as GuzzleHttpClient;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Rych\ByteSize\ByteSize;
use Carbon\Carbon;
use TheFox\FlickrCli\FlickrCli;

class UploadCommand extends FlickrCliCommand
{
    /**
     * @deprecated
     * @var int
     */
    public $OLDExit = 0;

    /**
     * @deprecated
     * @var string
     */
    private $configPath;

    /**
     * @deprecated
     * @var string
     */
    private $configRealPath;

    /**
     * @deprecated
     * @var string
     */
    private $logDirPath;

    /**
     * @deprecated
     * @var Logger
     */
    private $logger;

    /**
     * @deprecated
     * @var Logger
     */
    private $loggerFilesSuccessful;

    /**
     * @deprecated
     * @var Logger
     */
    private $loggerFilesFailed;

    /**
     * @var int
     */
    private $uploadFileSize;

    /**
     * @var int
     */
    private $uploadFileSizeLen;

    protected function configure()
    {
        parent::configure();

        $this->setName('upload');
        $this->setDescription('Upload files to Flickr.');

        $this->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'Description for all uploaded files.');
        $csvTagsDesc = 'Comma separated names. For example: --tags=tag1,"Tag two"';
        $this->addOption('tags', 't', InputOption::VALUE_OPTIONAL, $csvTagsDesc);
        $csvSetsDesc = 'Comma separated names. For example: --sets="Set one",set2';
        $this->addOption('sets', 's', InputOption::VALUE_OPTIONAL, $csvSetsDesc);
        $this->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Recurse into directories.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would have been transferred.');
        $this->addOption('move', 'm', InputOption::VALUE_OPTIONAL, 'Move uploaded files to this directory.');

        $this->addArgument('directory', InputArgument::IS_ARRAY, 'Path to directories.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->getLogger()->info('start');
        //$this->loggerFilesSuccessful->info('start');
        //$this->loggerFilesFailed->info('start');

        $description = null;
        if ($input->hasOption('description') && $input->getOption('description')) {
            $description = $input->getOption('description');
            $this->getLogger()->info('Description: ' . $description);
        }

        $tags = null;
        if ($input->hasOption('tags') && $input->getOption('tags')) {
            $tags = $input->getOption('tags');
            $this->getLogger()->debug('Tags String: ' . $tags);
        }

        $recursive = $input->getOption('recursive');
        $dryrun = $input->getOption('dry-run');

        $this->signalHandlerSetup();

        $metadata = new Metadata($config['flickr']['consumer_key'], $config['flickr']['consumer_secret']);
        $metadata->setOauthAccess($config['flickr']['token'], $config['flickr']['token_secret']);

        $guzzleAdapter = new RezzzaGuzzleAdapter();
        $guzzleAdapterVerbose = new RezzzaGuzzleAdapter();
        $guzzleAdapterClient = $guzzleAdapterVerbose->getClient();
        $guzzleAdapterClientConfig = $guzzleAdapterClient->getConfig();

        $curlOptions = $guzzleAdapterClientConfig->get(GuzzleHttpClient::CURL_OPTIONS);
        $curlOptions[CURLOPT_CONNECTTIMEOUT] = 60;
        $curlOptions[CURLOPT_NOPROGRESS] = false;

        $timePrev = 0;
        $uploadedTotal = 0;
        $uploadedPrev = 0;
        $uploadedDiffPrev = [0, 0, 0, 0, 0];

        $curlOptions[CURLOPT_PROGRESSFUNCTION] = function ($ch, $dlTotal = 0, $dlNow = 0, $ulTotal = 0, $ulNow = 0)
        use ($timePrev, $uploadedTotal, $uploadedPrev, $uploadedDiffPrev) {

            $uploadedDiff = $ulNow - $uploadedPrev;
            $uploadedPrev = $ulNow;
            $uploadedTotal += $uploadedDiff;

            $percent = 0;
            if ($ulTotal) {
                $percent = $ulNow / $ulTotal * 100;
            }
            if ($percent > 100) {
                $percent = 100;
            }

            $progressbarUploaded = round($percent / 100 * FlickrCli::UPLOAD_PROGRESSBAR_ITEMS);
            $progressbarRest = FlickrCli::UPLOAD_PROGRESSBAR_ITEMS - $progressbarUploaded;

            $uploadedDiffStr = '';
            $timeCur = time();
            if ($timeCur != $timePrev) {
                $timePrev = $timeCur;

                $uploadedDiff = ($uploadedDiff + array_sum($uploadedDiffPrev)) / 6;
                array_shift($uploadedDiffPrev);
                $uploadedDiffPrev[] = $uploadedDiff;

                if ($uploadedDiff > 0) {
                    $bytesize = new ByteSize();
                    $uploadedDiffStr = $bytesize->format($uploadedDiff) . '/s';
                }
            }

            printf(
                "[file] %6.2f%% [%s%s] %s %10s\x1b[0K\r",
                $percent,
                str_repeat('#', $progressbarUploaded),
                str_repeat(' ', $progressbarRest),
                number_format($ulNow),
                $uploadedDiffStr
            );

            pcntl_signal_dispatch();

            return $this->getExit() >= 2 ? 1 : 0;
        };
        $guzzleAdapterClientConfig->set(GuzzleHttpClient::CURL_OPTIONS, $curlOptions);

        $apiFactory = new ApiFactory($metadata, $guzzleAdapter);
        $apiFactoryVerbose = new ApiFactory($metadata, $guzzleAdapterVerbose);

        $photosetNames = [];
        if ($input->getOption('sets')) {
            $photosetNames = preg_split('/,/', $input->getOption('sets'));
        }

        $photosetAll = [];
        $photosetAllLower = [];

        $xml = $apiFactory->call('flickr.photosets.getList');

        /**
         * @var int $n
         * @var SimpleXMLElement $photoset
         */
        foreach ($xml->photosets->photoset as $n => $photoset) {
            pcntl_signal_dispatch();
            if ($this->getExit()) {
                break;
            }

            $id=(int)$photoset->attributes()->id;
            $title = (string)$photoset->title;

            $photosetAll[$id] = $title;
            $photosetAllLower[$id] = strtolower($title);
        }

        $photosets = [];
        $photosetsNew = [];
        foreach ($photosetNames as $photosetTitle) {
            $id = 0;

            foreach ($photosetAllLower as $photosetAllId => $photosetAllTitle) {
                if (strtolower($photosetTitle) == $photosetAllTitle) {
                    $id = $photosetAllId;
                    break;
                }
            }
            if ($id) {
                $photosets[] = $id;
            } else {
                $photosetsNew[] = $photosetTitle;
            }
        }

        // Move files after they've been successfully uploaded?
        $configUploadedBaseDir = false;
        $move = $input->getOption('move');
        if ($move !== null) {
            $configPathDirname = realpath(dirname($this->configRealPath));
            $configUploadedBaseDir = $configPathDirname . '/' . $move;
            // Make the local directory if it doesn't exist.
            if (!$filesystem->exists($configUploadedBaseDir)) {
                $filesystem->mkdir($configUploadedBaseDir);
                $this->getLogger()->info('Created directory: ' . $configUploadedBaseDir);
            }
            $this->getLogger()->info('Uploaded files will be moved to: ' . $configUploadedBaseDir);
        }

        $totalFiles = 0;
        $totalFilesUploaded = 0;
        $fileErrors = 0;
        $filesFailed = [];

        $filter = function (SplFileInfo $file) {
            if (in_array($file->getFilename(), FlickrCli::FILES_INORE)) {
                return false;
            }
            return true;
        };
        $finder = new Finder();
        $finder->files()->filter($filter);
        if (!$recursive) {
            $finder->depth(0);
        }

        $bytesize = new ByteSize();

        $directories = $input->getArgument('directory');
        foreach ($directories as $argDir) {
            //$srcDir = new SplFileInfo($argDir);

            $uploadBaseDirPath = '';
            if ($configUploadedBaseDir) {
                $uploadBaseDirPath = $configUploadedBaseDir . '/' . str_replace('/', '_', $argDir);
            }

            $this->getLogger()->info('[dir] upload dir: ' . $argDir . ' ' . $uploadBaseDirPath);

            foreach ($finder->in($argDir) as $file) {
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                $fileName = $file->getFilename();
                $fileExt = $file->getExtension();
                $filePath = $file->getRealPath();
                $fileRelativePath = new SplFileInfo($file->getRelativePathname());
                $fileRelativePathStr = (string)$fileRelativePath;
                $dirRelativePath = $fileRelativePath->getPath();

                $this->uploadFileSize = filesize($filePath);
                $this->uploadFileSizeLen = strlen(number_format($this->uploadFileSize));

                $uploadDirPath = '';
                if ($uploadBaseDirPath) {
                    $uploadDirPath = $uploadBaseDirPath . '/' . $dirRelativePath;

                    if (!$filesystem->exists($uploadDirPath)) {
                        $this->getLogger()->info("[dir] create '" . $uploadDirPath . "'");
                        $filesystem->mkdir($uploadDirPath);
                    }
                }

                $totalFiles++;

                if (!in_array(strtolower($fileExt), FlickrCli::ACCEPTED_EXTENTIONS)) {
                    $fileErrors++;
                    $filesFailed[] = $fileRelativePathStr;
                    $this->getLogger()->error('[file] invalid extension: ' . $fileRelativePathStr);
                    //$this->loggerFilesFailed->error($fileRelativePathStr);

                    continue;
                }

                if ($dryrun) {
                    $this->getLogger()->info(sprintf(
                        "[file] dry upload '%s' '%s' %s",
                        $fileRelativePathStr,
                        $dirRelativePath,
                        $bytesize->format($this->uploadFileSize)
                    ));
                    continue;
                }

                $this->getLogger()->info("[file] upload '"
                    . $fileRelativePathStr . "'  " . $bytesize->format($this->uploadFileSize));
                $xml = null;
                try {
                    $xml = $apiFactoryVerbose->upload($filePath, $fileName, $description, $tags);

                    // print "\r\x1b[0K";
                    print "\n";
                } catch (Exception $e) {
                    $this->getLogger()->error('[file] upload: ' . $e->getMessage());
                    $xml = null;
                }

                $photoId = 0;
                $stat = '';
                $successful = false;
                if ($xml) {
                    $photoId = isset($xml->photoid) ? (int)$xml->photoid : 0;
                    $stat = isset($xml->attributes()->stat) ? strtolower((string)$xml->attributes()->stat) : '';
                    $successful = $stat == 'ok' && $photoId != 0;
                }

                $logLine = '';
                if ($successful) {
                    $logLine = 'OK';
                    $totalFilesUploaded++;

                    $this->loggerFilesSuccessful->info($fileRelativePathStr);

                    if ($uploadDirPath) {
                        $this->getLogger()->info('[file] move to uploaded dir: ' . $uploadDirPath);
                        $filesystem->rename($filePath, $uploadDirPath . '/' . $fileName);
                    }
                } else {
                    $logLine = 'FAILED';
                    $fileErrors++;
                    $filesFailed[] = $fileRelativePathStr;

                    $this->loggerFilesFailed->error($fileRelativePathStr);
                }
                $this->getLogger()->info('[file] status: ' . $logLine . ' - ID ' . $photoId);

                if (!$successful) {
                    continue;
                }

                if ($photosetsNew) {
                    foreach ($photosetsNew as $photosetTitle) {
                        $this->getLogger()->info('[photoset] create ' . $photosetTitle . ' ... ');

                        $xml = null;
                        try {
                            $xml = $apiFactory->call('flickr.photosets.create', [
                                'title' => $photosetTitle,
                                'primary_photo_id' => $photoId,
                            ]);
                        } catch (Exception $e) {
                            $this->getLogger()->critical(
                                '[photoset] create ' . $photosetTitle . ' FAILED: ' . $e->getMessage()
                            );
                            return 1;
                        }
                        if ($xml) {
                            if ((string)$xml->attributes()->stat == 'ok') {
                                $photosetId = (int)$xml->photoset->attributes()->id;
                                $photosets[] = $photosetId;

                                $this->getLogger()->info('[photoset] create ' . $photosetTitle . ' OK - ID ' . $photosetId);
                            } else {
                                $code = (int)$xml->err->attributes()->code;
                                $this->getLogger()->critical('[photoset] create ' . $photosetTitle . ' FAILED: ' . $code);
                                return 1;
                            }
                        } else {
                            $this->getLogger()->critical('[photoset] create ' . $photosetTitle . ' FAILED');
                            return 1;
                        }
                    }
                    $photosetsNew = null;
                }

                if (count($photosets)) {
                    $this->getLogger()->info('[file] add to sets ... ');

                    $logLine = '';
                    foreach ($photosets as $photosetId) {
                        $logLine .= substr($photosetId, -5) . ' ';

                        $xml = null;
                        try {
                            $xml = $apiFactory->call('flickr.photosets.addPhoto', [
                                'photoset_id' => $photosetId,
                                'photo_id' => $photoId,
                            ]);
                        } catch (Exception $e) {
                            $this->logger->critical('[file] add to sets FAILED: ' . $e->getMessage());
                            return 1;
                        }
                        if ($xml) {
                            if ($xml->attributes()->stat == 'ok') {
                                $logLine .= 'OK ';
                            } else {
                                if (isset($xml->err)) {
                                    $code = (int)$xml->err->attributes()->code;
                                    if ($code == 3) {
                                        $logLine .= 'OK ';
                                    } else {
                                        $this->getLogger()->critical('[file] add to sets FAILED: ' . $code);
                                        return 1;
                                    }
                                } else {
                                    $this->getLogger()->critical('[file] add to sets FAILED');
                                    return 1;
                                }
                            }
                        }
                    }

                    $this->getLogger()->info('[file] added to sets: ' . $logLine);
                }
            }
        }

        $this->getLogger()->info('[main] total uploaded: ' . ($uploadedTotal > 0 ? $bytesize->format($uploadedTotal) : 0));
        $this->getLogger()->info('[main] total files:    ' . $totalFiles);
        $this->getLogger()->info('[main] files uploaded: ' . $totalFilesUploaded);

        $filesFailedMsg = count($filesFailed) ? "\n" . join("\n", $filesFailed) : '';
        $this->getLogger()->info('[main] files failed:   ' . $fileErrors . $filesFailedMsg);

        $this->getLogger()->info('exit');
        //$this->loggerFilesSuccessful->info('exit');
        //$this->loggerFilesFailed->info('exit');

        return $this->getExit();
    }
}
