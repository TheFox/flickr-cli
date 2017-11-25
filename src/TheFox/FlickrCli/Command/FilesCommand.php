<?php

namespace TheFox\FlickrCli\Command;

use SimpleXMLElement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Rezzza\Flickr\Metadata;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;

class FilesCommand extends FlickrCliCommand
{
    /**
     * @deprecated
     * @var int
     */
    public $exit = 0;

    protected function configure()
    {
        $this->setName('files');
        $this->setDescription('List Files.');

        $this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: config.yml');
        $this->addArgument('photosets', InputArgument::IS_ARRAY, 'Photosets to use.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $photosets = $input->getArgument('photosets');

        $apiService = $this->getApiService();
        $apiFactory = $apiService->getApiFactory();

        $photosetTitles = $apiService->getPhotosetTitles();
        foreach ($photosetTitles as $photosetId => $photosetTitle) {
            pcntl_signal_dispatch();
            if ($this->getExit()) {
                break;
            }

            if (!in_array($photosetTitle, $photosets)) {
                continue;
            }

            $xmlPhotoListOptions = ['photoset_id' => $photosetId];
            $xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', $xmlPhotoListOptions);
            $xmlPhotoListPagesTotal = (int)$xmlPhotoList->photoset->attributes()->pages;
            $xmlPhotoListPhotosTotal = (int)$xmlPhotoList->photoset->attributes()->total;

            printf('%s (%d)' . "\n", $photosetTitle, $xmlPhotoListPhotosTotal);

            $fileCount = 0;

            for ($page = 1; $page <= $xmlPhotoListPagesTotal; $page++) {
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                if ($page > 1) {
                    $xmlPhotoListOptions['page'] = $page;
                    $xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', $xmlPhotoListOptions);
                }

                /**
                 * @var int $n
                 * @var SimpleXMLElement $photo
                 */
                foreach ($xmlPhotoList->photoset->photo as $n => $photo) {
                    pcntl_signal_dispatch();
                    if ($this->getExit()) {
                        break;
                    }

                    $id = (string)$photo->attributes()->id;
                    $fileCount++;

                    printf('  %d/%d %s' . "\n", $page, $fileCount, $id);
                }
            }
        }

        return $this->getExit();
    }
}
