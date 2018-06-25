<?php

namespace TheFox\FlickrCli\Command;

use Exception;
use SimpleXMLElement;
use SplFileInfo;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;
use Guzzle\Http\Client as GuzzleHttpClient;
use Rych\ByteSize\ByteSize;
use TheFox\FlickrCli\FlickrCli;

class UploadCommand extends FlickrCliCommand
{
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

        $apiService = $this->getApiService();

        if ($input->hasOption('description') && $input->getOption('description')) {
            $description = $input->getOption('description');
            $this->getLogger()->info(sprintf('Description: %s', $description));
        } else {
            $description = null;
        }

        if ($input->hasOption('tags') && $input->getOption('tags')) {
            $tags = $input->getOption('tags');
            $this->getLogger()->debug(sprintf('Tags String: %s', $tags));
        } else {
            $tags = null;
        }

        $recursive = $input->getOption('recursive');
        $dryrun = $input->getOption('dry-run');

        //$metadata = new Metadata($config['flickr']['consumer_key'], $config['flickr']['consumer_secret']);
        //$metadata->setOauthAccess($config['flickr']['token'], $config['flickr']['token_secret']);

        //$guzzleAdapter = new RezzzaGuzzleAdapter();
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

        $curlOptions[CURLOPT_PROGRESSFUNCTION] = function ($ch, $dlTotal = 0, $dlNow = 0, $ulTotal = 0, $ulNow = 0) use ($timePrev, $uploadedTotal, $uploadedPrev, $uploadedDiffPrev) {

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
                //$timePrev = $timeCur;

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

        //$apiFactory = new ApiFactory($metadata, $guzzleAdapter);
        $apiFactory = $apiService->getApiFactory();
        $metadata = $apiFactory->getMetadata();
        $apiFactoryVerbose = new ApiFactory($metadata, $guzzleAdapterVerbose);

        if ($input->getOption('sets')) {
            $photosetNames = preg_split('/,/', $input->getOption('sets'));
        } else {
            $photosetNames = [];
        }

        $photosetAll = [];
        $photosetAllLower = [];

        $apiFactory = $apiService->getApiFactory();
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

            $id = (int)$photoset->attributes()->id;
            $title = (string)$photoset->title;

            $photosetAll[$id] = $title;
            $photosetAllLower[$id] = strtolower($title);
        }

        $photosets = [];
        $photosetsNew = [];
        foreach ($photosetNames as $photosetTitle) {
            $id = 0;

            foreach ($photosetAllLower as $photosetAllId => $photosetAllTitle) {
                if (strtolower($photosetTitle) != $photosetAllTitle) {
                    continue;
                }

                $id = $photosetAllId;
                break;
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
        if (null !== $move) {
            $configUploadedBaseDir = dirname($move);

            $filesystem = new Filesystem();
            // Make the local directory if it doesn't exist.
            if (!$filesystem->exists($configUploadedBaseDir)) {
                $filesystem->mkdir($configUploadedBaseDir, 0755);
                $this->getLogger()->info(sprintf('Created directory: %s', $configUploadedBaseDir));
            }
            $this->getLogger()->info(sprintf('Uploaded files will be moved to: %s', $configUploadedBaseDir));
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
            if ($configUploadedBaseDir) {
                $argDirReplaced = str_replace('/', '_', $argDir);
                $uploadBaseDirPath = sprintf('%s/%s', $configUploadedBaseDir, $argDirReplaced);
            } else {
                $uploadBaseDirPath = '';
            }

            $this->getLogger()->info(sprintf('[dir] upload dir: %s %s', $argDir, $uploadBaseDirPath));

            /** @var \Symfony\Component\Finder\SplFileInfo[] $files */
            $files = iterator_to_array($finder->in($argDir));
            sort($files);
            foreach ($files as $file) {
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

                $uploadFileSize = filesize($filePath);
                //$uploadFileSizeLen = strlen(number_format($uploadFileSize));
                $uploadFileSizeFormatted = $bytesize->format($uploadFileSize);

                $uploadDirPath = '';
                if ($uploadBaseDirPath) {
                    $uploadDirPath = sprintf('%s/%s', $uploadBaseDirPath, $dirRelativePath);

                    $filesystem = new Filesystem();
                    if (!$filesystem->exists($uploadDirPath)) {
                        $this->getLogger()->info(sprintf('[dir] create "%s"', $uploadDirPath));
                        $filesystem->mkdir($uploadDirPath);
                    }
                }

                $totalFiles++;

                if (!in_array(strtolower($fileExt), FlickrCli::ACCEPTED_EXTENTIONS)) {
                    $fileErrors++;
                    $filesFailed[] = $fileRelativePathStr;
                    $this->getLogger()->warning(sprintf('[file] invalid extension: %s', $fileRelativePathStr));

                    continue;
                }

                if ($dryrun) {
                    $this->getLogger()->info(sprintf(
                        "[file] dry upload '%s' '%s' %s",
                        $fileRelativePathStr,
                        $dirRelativePath,
                        $uploadFileSizeFormatted
                    ))
                    ;
                    continue;
                }

                $this->getLogger()->info(sprintf('[file] upload "%s" %s', $fileRelativePathStr, $uploadFileSizeFormatted));
                try {
                    $xml = $apiFactoryVerbose->upload($filePath, $fileName, $description, $tags);

                    print "\n";
                } catch (Exception $e) {
                    $this->getLogger()->error(sprintf('[file] upload: %s', $e->getMessage()));
                    $xml = null;
                }

                if ($xml) {
                    $photoId = isset($xml->photoid) ? (int)$xml->photoid : 0;
                    $stat = isset($xml->attributes()->stat) ? strtolower((string)$xml->attributes()->stat) : '';
                    $successful = $stat == 'ok' && $photoId != 0;
                    if (!$successful) {
                        $this->getLogger()->error(sprintf('[file] error %s: %s (%s)',
                            $xml->err['code'], $xml->err['msg'], $fileName ));
                    }
                } else {
                    $photoId = 0;
                    $successful = false;
                }

                if ($successful) {
                    $logLine = 'OK';
                    $totalFilesUploaded++;

                    if ($uploadDirPath) {
                        $this->getLogger()->info(sprintf('[file] move to uploaded dir: %s', $uploadDirPath));

                        $filesystem = new Filesystem();
                        $filesystem->rename($filePath, sprintf('%s/%s', $uploadDirPath, $fileName));
                    }
                } else {
                    $logLine = 'FAILED';
                    $fileErrors++;
                    $filesFailed[] = $fileRelativePathStr;
                }
                $this->getLogger()->info(sprintf('[file] status: %s - ID %s', $logLine, $photoId));

                if (!$successful) {
                    continue;
                }

                if ($photosetsNew) {
                    foreach ($photosetsNew as $photosetTitle) {
                        $this->getLogger()->info(sprintf('[photoset] create %s ... ', $photosetTitle));

                        $xml = null;
                        try {
                            $xml = $apiFactory->call('flickr.photosets.create', [
                                'title' => $photosetTitle,
                                'primary_photo_id' => $photoId,
                            ]);
                        } catch (Exception $e) {
                            $this->getLogger()->critical(sprintf('[photoset] create %s FAILED: %s', $photosetTitle, $e->getMessage()));
                            return 1;
                        }

                        if ((string)$xml->attributes()->stat == 'ok') {
                            $photosetId = (int)$xml->photoset->attributes()->id;
                            $photosets[] = $photosetId;

                            $this->getLogger()->info(sprintf('[photoset] create %s OK - ID %s', $photosetTitle, $photosetId));
                        } else {
                            $code = (int)$xml->err->attributes()->code;
                            $this->getLogger()->critical(sprintf('[photoset] create %s FAILED: %s', $photosetTitle, $code));
                            return 1;
                        }
                    }
                    $photosetsNew = null;
                }

                if (count($photosets)) {
                    $this->getLogger()->info('[file] add to sets ... ');

                    $logLine = [];
                    foreach ($photosets as $photosetId) {
                        $logLine[] = substr($photosetId, -5);

                        try {
                            $xml = $apiFactory->call('flickr.photosets.addPhoto', [
                                'photoset_id' => $photosetId,
                                'photo_id' => $photoId,
                            ]);
                        } catch (Exception $e) {
                            $this->getLogger()->critical(sprintf('[file] add to sets FAILED: %s', $e->getMessage()));
                            return 1;
                        }

                        if ($xml->attributes()->stat == 'ok') {
                            $logLine[] = 'OK';
                        } else {
                            if (isset($xml->err)) {
                                $code = (int)$xml->err->attributes()->code;
                                if ($code == 3) {
                                    $logLine[] = 'OK';
                                } else {
                                    $this->getLogger()->critical(sprintf('[file] add to sets FAILED: %d', $code));
                                    return 1;
                                }
                            } else {
                                $this->getLogger()->critical('[file] add to sets FAILED');
                                return 1;
                            }
                        }
                    }

                    $this->getLogger()->info(sprintf('[file] added to sets: %s', join(' ', $logLine)));
                }
            }
        }

        if ($uploadedTotal > 0) {
            $uploadedTotalStr = $bytesize->format($uploadedTotal);
        } else {
            $uploadedTotalStr = 0;
        }

        $this->getLogger()->notice(sprintf('[main] total uploaded: %s', $uploadedTotalStr));
        $this->getLogger()->notice(sprintf('[main] total files:    %d', $totalFiles));
        $this->getLogger()->notice(sprintf('[main] files uploaded: %d', $totalFilesUploaded));

        $filesFailedMsg = count($filesFailed) ? "\n" . join("\n", $filesFailed) : '';
        $this->getLogger()->notice(sprintf('[main] files failed:   %s%s', $fileErrors, $filesFailedMsg));

        return $this->getExit();
    }
}
