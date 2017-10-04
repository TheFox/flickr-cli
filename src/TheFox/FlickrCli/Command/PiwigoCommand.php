<?php

namespace TheFox\FlickrCli\Command;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter;
use Rezzza\Flickr\Metadata;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class PiwigoCommand extends Command
{

    /** @var string[] Array of photoset titles, keyed by their ID. */
    protected $photosets;

    protected function configure()
    {
        $this->setName('piwigo');
        $this->setDescription('Upload files from Piwigo to Flickr');

        $this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file; default: config.yml');
        $this->addOption('log', 'l', InputOption::VALUE_OPTIONAL, 'Path to log directory; default: log');

        $this->addOption('piwigo-uploads', null, InputOption::VALUE_REQUIRED, "Path to the Piwigo 'uploads' directory");
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return null|int null or 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = Yaml::parse('config.yml');

        // Piwigo.
        if (!isset($config['piwigo'])
            || !isset($config['piwigo']['dbname'])
            || !isset($config['piwigo']['dbuser'])
            || !isset($config['piwigo']['dbpass'])
            || !isset($config['piwigo']['dbhost'])
        ) {
            $output->writeln(
                "<error>Please set the all of the following options in the 'piwigo' section of config.yml:\n"
                . "dbname, dbuser, dbpass, & dbhost.</error>"
            );
            return 1;
        }
        $dbConfig = new Configuration();
        $connectionParams = array(
            'dbname' => $config['piwigo']['dbname'],
            'user' => $config['piwigo']['dbuser'],
            'password' => $config['piwigo']['dbpass'],
            'host' => $config['piwigo']['dbhost'],
            'driver' => 'pdo_mysql',
        );
        $conn = DriverManager::getConnection($connectionParams, $dbConfig);
        $count = $conn->query("SELECT COUNT(*) AS count FROM images")->fetchColumn();
        $output->writeln(number_format($count)." images found in the Piwigo database");
        $piwigoUploadsPath = $input->getOption('piwigo-uploads');

        // Flickr.
        $metadata = new Metadata($config['flickr']['consumer_key'], $config['flickr']['consumer_secret']);
        $metadata->setOauthAccess($config['flickr']['token'], $config['flickr']['token_secret']);
        $guzzleAdapterVerbose = new GuzzleAdapter();
        $apiFactory = new ApiFactory($metadata, $guzzleAdapterVerbose);

        // Photos.
        $images = $conn->query("SELECT * FROM images")->fetchAll();
        foreach ($images as $image) {
            $this->processOne($image, $piwigoUploadsPath, $conn, $output, $apiFactory);
        }
    }

    protected function processOne(
        $image,
        $piwigoUploadsPath,
        Connection $conn,
        OutputInterface $output,
        ApiFactory $apiFactory
    ) {
        // Check file.
        $filePath = $piwigoUploadsPath.substr($image['path'], 9);
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        // Figure out the privacy level.
        //   1 = Contacts
        //   2 = Friends
        //   4 = Family
        //   8 = Admins
        $isPublic = false;
        $isFriend = false;
        $isFamily = false;
        switch ($image['level']) {
            case 0:
                $isPublic = true;
                break;
            case 1:
            case 2:
            case 4:
                $isFriend = true;
                $isFamily = true;
                break;
            case 8:
            default:
                break;
        }

        // Get tags (including a checksum machine tag).
        $cats = $conn->prepare("SELECT t.name FROM image_tag it JOIN tags t ON it.tag_id=t.id WHERE it.image_id=:id");
        $cats->bindValue('id', $image['id']);
        $cats->execute();
        $md5sum = !empty($image['md5sum']) ? $image['md5sum'] : md5_file($filePath);
        $tags = ['checksum:md5='.$md5sum];
        while ($cat = $cats->fetch()) {
            $tags[] = $cat['name'];
        }

        // Make sure it's not already on Flickr (by MD5 checksum only).
        $md5search = $apiFactory->call('flickr.photos.search', [
            'user_id' => 'me',
            'tags' => "checksum:md5=$md5sum",
        ]);
        if (((int)$md5search->photos['total']) > 0) {
            $output->writeln('Already exists: '.$image['name']);
            return;
        }

        // Upload to Flickr.
        $output->write("Uploading: ".$image['name']);
        $comment = $image['comment'];
        $xml = $apiFactory->upload($filePath, $image['name'], $comment, $tags, $isPublic, $isFriend, $isFamily);
        $photoId = isset($xml->photoid) ? (int)$xml->photoid : 0;
        $stat = isset($xml->attributes()->stat) ? strtolower((string)$xml->attributes()->stat) : '';
        $successful = $stat == 'ok' && $photoId != 0;
        if (!$successful) {
            throw new Exception("Failed to upload $filePath to ".$image['name']);
        }

        // Add to albums (categories, in Piwigo parlance).
        $output->write(' [photosets]');
        $sql = "SELECT c.name FROM image_category ic JOIN categories c ON ic.category_id=c.id WHERE ic.image_id=:id";
        $cats = $conn->prepare($sql);
        $cats->bindValue('id', $image['id']);
        $cats->execute();
        while ($cat = $cats->fetch()) {
            $photosetId = $this->getPhotosetId($apiFactory, $cat['name'], $photoId, $output);
            $apiFactory->call('flickr.photosets.addPhoto', [
                'photoset_id' => $photosetId,
                'photo_id' => $photoId,
            ]);
        }

        // Add to an import photoset.
        $importFromPiwigoId = $this->getPhotosetId($apiFactory, 'Imported from Piwigo', $photoId, $output);
        $apiFactory->call('flickr.photosets.addPhoto', [
            'photoset_id' => $importFromPiwigoId,
            'photo_id' => $photoId,
        ]);

        // Set location on Flickr.
        if (!empty($image['latitude']) && !empty($image['longitude'])) {
            $output->write(' [location]');
            $apiFactory->call('flickr.photos.geo.setLocation', [
                'photo_id' => $photoId,
                'lat' => $image['latitude'],
                'lon' => $image['longitude'],
            ]);
        } else {
            $output->write(' [no location]');
        }

        $output->writeln(' -- done');
    }

    /**
     * Get a photoset's ID from a name, creating a new photo set if required.
     * Case insensitive.
     * @param ApiFactory $apiFactory
     * @param string $photosetName
     * @param int $primaryPhotoId
     * @param OutputInterface $output
     * @return int
     */
    protected function getPhotosetId(ApiFactory $apiFactory, $photosetName, $primaryPhotoId, OutputInterface $output)
    {
        // First get all existing albums (once only).
        if (!is_array($this->photosets)) {
            $this->photosets = [];
            $getList = $apiFactory->call('flickr.photosets.getList');
            foreach ($getList->photosets->photoset as $n => $photoset) {
                $this->photosets[(int)$photoset->attributes()->id] = (string)$photoset->title;
            }
        }

        // See if we've already got it.
        foreach ($this->photosets as $id => $name) {
            if (mb_strtolower($photosetName) == mb_strtolower($name)) {
                return (int)$id;
            }
        }

        // Otherwise, create it.
        $output->write(" [creating new photoset: $photosetName]");
        $newPhotoset = $apiFactory->call('flickr.photosets.create', array(
            'title' => $photosetName,
            'primary_photo_id' => $primaryPhotoId,
        ));
        $newId = (int)$newPhotoset->photoset->attributes()->id;
        $this->photosets[$newId] = $photosetName;
        return $newId;
    }
}
