<?php

namespace TheFox\FlickrCli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AlbumsCommand extends FlickrCliCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('albums');
        $this->setDescription('List Photosets.');
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

        $photosetTitles = $apiService->getPhotosetTitles();
        foreach ($photosetTitles as $photosetId => $photosetTitle) {
            pcntl_signal_dispatch();
            if ($this->getExit()) {
                break;
            }

            printf('%s' . "\n", $photosetTitle);
        }

        return $this->getExit();
    }
}
