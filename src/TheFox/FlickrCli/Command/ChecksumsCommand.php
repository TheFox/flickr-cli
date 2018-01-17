<?php

namespace TheFox\FlickrCli\Command;

use Exception;
use Rezzza\Flickr\ApiFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class ChecksumsCommand extends DownloadCommand
{

    /** @var string */
    protected $tmpDir;

    /** @var ApiFactory */
    protected $apiFactory;

    protected function configure()
    {
        FlickrCliCommand::configure();
        $this->setName('checksums');
        $this->setDescription('Add checksum machine tags to photos already on Flickr.');
        $this->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'The hash function to use.', 'sha1');
        $this->addOption('duplicates', 'd', InputOption::VALUE_NONE, "Search for duplicates,\nand output search-result URLs when found.");
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        FlickrCliCommand::execute($input, $output);
        $this->apiFactory = $this->getApiService()->getApiFactory();

        // Set up the temporary directory.
        $this->tmpDir = sys_get_temp_dir() . '/flickr-cli';
        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->tmpDir)) {
            $filesystem->mkdir($this->tmpDir, 0755);
        }

        // Get all photos.
        $page = 1;
        do {
            pcntl_signal_dispatch();
            if ($this->getExit()) {
                break;
            }
            $params = [
                'user_id' => 'me',
                'page' => $page,
                'per_page' => 500,
                'extras' => 'o_url, tags',
            ];
            $photos = $this->apiFactory->call('flickr.people.getPhotos', $params);
            $this->getOutput()->writeln("Page $page of " . $photos->photos['pages']);
            foreach ($photos->photos->photo as $photo) {
                // Break if required.
                pcntl_signal_dispatch();
                if ($this->getExit()) {
                    break;
                }

                // Process this photo.
                $hashTag = $this->processPhoto($photo);
                if (!$hashTag) {
                    exit();
                    continue;
                }

                // If desired, perform a search for this tag.
                $dupes = $this->getInput()->getOption('duplicates');
                if ($dupes) {
                    $search = $this->apiFactory->call('flickr.photos.search', ['machine_tags' => $hashTag]);
                    if ((int)$search->photos['total'] > 1) {
                        $url = "https://www.flickr.com/photos/tags/$hashTag";
                        $this->getLogger()->alert("Duplicate found: $url");
                    }
                }
            }
            $page++;
        } while ($photos->photos['page'] !== $photos->photos['pages']);

        // Clean up the temporary directory.
        foreach (preg_grep('|^\..*|', scandir($this->tmpDir), PREG_GREP_INVERT) as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);

        return $this->getExit();
    }

    /**
     * @param $photo
     * @return string|bool The hash machine tag, or false.
     * @throws Exception
     */
    protected function processPhoto($photo)
    {
        // Find the hash function.
        $hash = $this->getInput()->getOption('hash');
        $hashFunction = $hash . '_file';
        if (!function_exists($hashFunction)) {
            throw new Exception("Hash function not available: $hashFunction");
        }

        // See if the photo has already got a checksum tag.
        preg_match("/(checksum:$hash=.*)/", $photo['tags'], $matches);
        if (isset($matches[1])) {
            // If it's already got a tag, do nothing more.
            $this->getLogger()->info(sprintf('Already has checksum: %s', $photo['id']));
            return $matches[1];
        }

        $this->getLogger()->info(sprintf('Adding checksum machine tag to: %s', $photo['id']));

        // Download the file.
        $tmpFilename = 'checksumming';
        $downloaded = $this->downloadPhoto($photo, $this->tmpDir, $tmpFilename);
        if (false === $downloaded) {
            $this->getLogger()->error(sprintf('Unable to download: %s', $photo['id']));
            return false;
        }

        // Calculate the file's hash, and remove the temporary file.
        $filename = $this->tmpDir . '/' . $tmpFilename . '.' . $downloaded['originalformat'];
        $fileHash = $hashFunction($filename);
        if (file_exists($filename)) {
            unlink($filename);
        }

        // Upload the new tag if it's not already present.
        $hashTag = "checksum:$hash=$fileHash";
        $tagAdded = $this->apiFactory->call('flickr.photos.setTags', [
            'photo_id' => $photo['id'],
            'tags' => $this->getTagsAsString($photo['id']) . ' ' . $hashTag,
        ]);
        if (isset($tagAdded->err)) {
            throw new Exception($tagAdded->err['msg']);
        }
        return $hashTag;
    }

    /**
     * Get a space-separated string of all tags.
     * @param int $photoId The photo ID.
     * @return string
     */
    protected function getTagsAsString($photoId)
    {
        $photoInfo = $this->apiFactory->call('flickr.photos.getInfo', ['photo_id' => $photoId]);
        $tags = [];
        foreach ($photoInfo->photo->tags->tag as $tag) {
            $tags[] = '"'.$tag['raw'].'"';
        }
        $tagsString = join(' ', $tags);
        return $tagsString;
    }
}
