<?php

namespace TheFox\FlickrCli\Command;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PiwigoCommand extends FlickrCliCommand
{
    /**
     * Array of photoset titles, keyed by their ID.
     *
     * @var string[]
     */
    private $photosets;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @param Connection $connection
     */
    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
    }

    protected function configure()
    {
        parent::configure();

        $this->setName('piwigo');
        $this->setDescription('Upload files from Piwigo to Flickr');

        $this->addOption('piwigo-uploads', null, InputOption::VALUE_REQUIRED, "Path to the Piwigo 'uploads' directory");
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface $input An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     * @return int
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->checkPiwigoConfig();
        $this->setupPiwigoConnection();

        $count = $this->getConnection()->query('SELECT COUNT(*) AS count FROM images')->fetchColumn();
        $output->writeln(sprintf('%s images found in the Piwigo database', number_format($count)));

        $piwigoUploadsPath = $input->getOption('piwigo-uploads');

        // Photos.
        $images = $this->getConnection()->query('SELECT * FROM images')->fetchAll();
        foreach ($images as $image) {
            $this->processOne($image, $piwigoUploadsPath);
        }

        return $this->getExit();
    }

    /**
     * @throws RuntimeException
     */
    private function checkPiwigoConfig()
    {
        $config = $this->getConfig();

        // Piwigo.
        if (!isset($config['piwigo'])
            || !isset($config['piwigo']['dbname'])
            || !isset($config['piwigo']['dbuser'])
            || !isset($config['piwigo']['dbpass'])
            || !isset($config['piwigo']['dbhost'])
        ) {
            $msg = 'Please set the all of the following options in the \'piwigo\' section of config.yml: ';
            $msg .= 'dbname, dbuser, dbpass, & dbhost.';
            throw new RuntimeException($msg);
        }
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    private function setupPiwigoConnection()
    {
        $config = $this->getConfig();

        $dbConfig = new Configuration();
        $connectionParams = [
            'dbname' => $config['piwigo']['dbname'],
            'user' => $config['piwigo']['dbuser'],
            'password' => $config['piwigo']['dbpass'],
            'host' => $config['piwigo']['dbhost'],
            'driver' => 'pdo_mysql',
        ];
        $conn = DriverManager::getConnection($connectionParams, $dbConfig);

        $this->setConnection($conn);
    }

    /**
     * @param $image
     * @param $piwigoUploadsPath
     * @throws \Doctrine\DBAL\DBALException
     */
    private function processOne($image, $piwigoUploadsPath)
    {
        // Check file.
        $filePath = $piwigoUploadsPath . substr($image['path'], 9);
        if (!file_exists($filePath)) {
            throw new RuntimeException(sprintf('File not found: %s', $filePath));
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
        $sql = 'SELECT t.name FROM image_tag it JOIN tags t ON it.tag_id=t.id WHERE it.image_id=:id';
        $cats = $this->getConnection()->prepare($sql);
        $cats->bindValue('id', $image['id']);
        $cats->execute();

        if (empty($image['md5sum'])) {
            $md5sum = md5_file($filePath);
        } else {
            $md5sum = $image['md5sum'];
        }
        $tags = [sprintf('checksum:md5=%s', $md5sum)];
        while ($cat = $cats->fetch()) {
            $tags[] = $cat['name'];
        }

        // Make sure it's not already on Flickr (by MD5 checksum only).
        $apiFactory = $this->getApiService()->getApiFactory();
        $md5search = $apiFactory->call('flickr.photos.search', [
            'user_id' => 'me',
            'tags' => sprintf('checksum:md5=%s', $md5sum),
        ]);
        if (((int)$md5search->photos['total']) > 0) {
            $this->getOutput()->writeln(sprintf('Already exists: %s', $image['name']));
            return;
        }

        // Upload to Flickr.
        $this->getOutput()->write(sprintf('Uploading: %s', $image['name']));
        $comment = $image['comment'];
        $xml = $apiFactory->upload($filePath, $image['name'], $comment, $tags, $isPublic, $isFriend, $isFamily);
        $photoId = isset($xml->photoid) ? (int)$xml->photoid : 0;
        $stat = isset($xml->attributes()->stat) ? strtolower((string)$xml->attributes()->stat) : '';
        $successful = $stat == 'ok' && $photoId != 0;
        if (!$successful) {
            throw new RuntimeException(sprintf('Failed to upload %s to %s', $filePath, $image['name']));
        }

        // Add to albums (categories, in Piwigo parlance).
        $this->getOutput()->write(' [photosets]');
        $sql = 'SELECT c.name FROM image_category ic JOIN categories c ON ic.category_id=c.id WHERE ic.image_id=:id';
        $cats = $this->getConnection()->prepare($sql);
        $cats->bindValue('id', $image['id']);
        $cats->execute();
        while ($cat = $cats->fetch()) {
            $photosetId = $this->getPhotosetId($cat['name'], $photoId);
            $apiFactory->call('flickr.photosets.addPhoto', [
                'photoset_id' => $photosetId,
                'photo_id' => $photoId,
            ]);
        }

        // Add to an import photoset.
        $importFromPiwigoId = $this->getPhotosetId('Imported from Piwigo', $photoId);
        $apiFactory->call('flickr.photosets.addPhoto', [
            'photoset_id' => $importFromPiwigoId,
            'photo_id' => $photoId,
        ]);

        // Set location on Flickr.
        if (!empty($image['latitude']) && !empty($image['longitude'])) {
            $this->getOutput()->write(' [location]');
            $apiFactory->call('flickr.photos.geo.setLocation', [
                'photo_id' => $photoId,
                'lat' => $image['latitude'],
                'lon' => $image['longitude'],
            ]);
        } else {
            $this->getOutput()->write(' [no location]');
        }

        $this->getOutput()->writeln(' -- done');
    }

    /**
     * Get a photoset's ID from a name, creating a new photo set if required.
     * Case insensitive.
     *
     * @param string $photosetName
     * @param int $primaryPhotoId
     * @return int
     */
    private function getPhotosetId($photosetName, $primaryPhotoId)
    {
        $apiFactory = $this->getApiService()->getApiFactory();

        // First get all existing albums (once only).
        if (!is_array($this->photosets)) {
            $this->photosets = [];
            $getList = $apiFactory->call('flickr.photosets.getList');
            /**
             * @var string $n
             * @var \SimpleXMLElement $photoset
             */
            foreach ($getList->photosets->photoset as $n => $photoset) {
                $this->photosets[(int)$photoset->attributes()->id] = (string)$photoset->title;
            }
        }

        // See if we've already got it.
        foreach ($this->photosets as $id => $name) {
            if (mb_strtolower($photosetName) != mb_strtolower($name)) {
                continue;
            }

            return (int)$id;
        }

        // Otherwise, create it.
        $this->getOutput()->write(sprintf(' [creating new photoset: %s]', $photosetName));
        $newPhotoset = $apiFactory->call('flickr.photosets.create', [
            'title' => $photosetName,
            'primary_photo_id' => $primaryPhotoId,
        ]);
        $newId = (int)$newPhotoset->photoset->attributes()->id;
        $this->photosets[$newId] = $photosetName;
        return $newId;
    }
}
