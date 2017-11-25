<?php

namespace TheFox\FlickrCli\Command;

use Exception;
use SimpleXMLElement;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteCommand extends FlickrCliCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('delete');
        $this->setDescription('Delete Photosets.');

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

        $this->getLogger()->notice('[main] start deleting files');
        foreach ($photosetTitles as $photosetId => $photosetTitle) {
            pcntl_signal_dispatch();
            if ($this->getExit()) {
                break;
            }

            if (!in_array($photosetTitle, $photosets)) {
                continue;
            }

            $xmlPhotoListOptions = [
                'photoset_id' => $photosetId,
            ];
            $xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', $xmlPhotoListOptions);
            $xmlPhotoListAttributes = $xmlPhotoList->photoset->attributes();
            $xmlPhotoListPagesTotal = (int)$xmlPhotoListAttributes->pages;
            $xmlPhotoListPhotosTotal = (int)$xmlPhotoListAttributes->total;

            $this->getLogger()->info(sprintf('[photoset] %s: %s', $photosetTitle, $xmlPhotoListPhotosTotal));

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

                    $fileCount++;
                    $id = (string)$photo->attributes()->id;
                    try {
                        $apiFactory->call('flickr.photos.delete', ['photo_id' => $id]);
                        $this->getLogger()->info(sprintf('[photo] %d/%d deleted %s', $page, $fileCount, $id));
                    } catch (Exception $e) {
                        $this->getLogger()->info(sprintf('[photo] %d/%d delete %s FAILED: %s', $page, $fileCount, $id, $e->getMessage()));
                    }
                }
            }
        }

        return $this->getExit();
    }
}
