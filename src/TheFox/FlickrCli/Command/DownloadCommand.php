<?php

namespace TheFox\FlickrCli\Command;

use Exception;
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
use Guzzle\Http\Client as GuzzleHttpClient;
use Guzzle\Stream\PhpStreamRequestFactory;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Rych\ByteSize\ByteSize;
use Carbon\Carbon;
use TheFox\FlickrCli\FlickrCli;

class DownloadCommand extends Command{

	public $exit = 0;
	private $configPath;
	private $logDirPath;

	/** @var string The destination directory for downloaded files. No trailing slash. */
	private $dstDirPath;

	/** @var Logger General logger */
	private $log;

	/** @var Logger Log for information about failed downloads.  */
	private $logFilesFailed;

	/** @var boolean Whether to download even if a local copy already exists. */
	protected $forceDownload;

	protected function configure(){
		$this->setName('download');
		$this->setDescription('Download files from Flickr.');
		
		$this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: config.yml');
		$this->addOption('log', 'l', InputOption::VALUE_OPTIONAL, 'Path to log directory. Default: log');
		$this->addOption('destination', 'd', InputOption::VALUE_OPTIONAL, 'Path to save files. Default: photosets');
		
		$idDirsDescr = 'Save downloaded files into ID-based directories. Default is to group by Album titles instead.';
		$this->addOption('id-dirs', 'i', InputOption::VALUE_NONE, $idDirsDescr);
		
		$forceDescr = 'Force Flickr CLI to download photos even if they already exist locally. ';
		$forceDescr .= 'Default is to skip existing downloads.';
		$this->addOption('force', 'f', InputOption::VALUE_NONE, $forceDescr);
		// $this->addOption('tags', 't', InputOption::VALUE_OPTIONAL, 'Comma separated names. For example: --tags=tag1,tag2');
		// $this->addOption('sets', 's', InputOption::VALUE_OPTIONAL, 'Comma separated names. For example: --sets=set1,set2');
		// $this->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Recurse into directories.');
		// $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would have been transferred.');
		
		$this->addArgument('photosets', InputArgument::OPTIONAL, 'Photosets to download.');

		$this->configPath = 'config.yml';
		$this->logDirPath = 'log';
		$this->dstDirPath = 'photosets';
	}

	/**
	 * Executes the download command.
	 * 
	 * @param InputInterface  $input  An InputInterface instance
	 * @param OutputInterface $output An OutputInterface instance
	 * @return null|int null or 0 if everything went fine, or an error code
	 */
	protected function execute(InputInterface $input, OutputInterface $output){
		$filesystem = new Filesystem();

		// Set up logging.
		if($input->hasOption('log') && $input->getOption('log')){
			$this->logDirPath = $input->getOption('log');
		}
		if(!$filesystem->exists($this->logDirPath)){
			$filesystem->mkdir($this->logDirPath);
		}
		
		$now = Carbon::now();
		$nowFormated = $now->format('Ymd');
		
		$logFormatter = new LineFormatter("[%datetime%] %level_name%: %message%\n");
		
		$this->log = new Logger('flickr_downloader');
		
		$logHandlerStderr = new StreamHandler('php://stderr', Logger::DEBUG);
		$logHandlerStderr->setFormatter($logFormatter);
		$this->log->pushHandler($logHandlerStderr);
		
		$logHandlerFile = new StreamHandler($this->logDirPath.'/flickr_download_'.$nowFormated.'.log', Logger::INFO);
		$logHandlerFile->setFormatter($logFormatter);
		$this->log->pushHandler($logHandlerFile);
		
		$logFilesFailedStreamFilePath = $this->logDirPath.'/flickr_download_files_failed_'.$nowFormated.'.log';
		$logFilesFailedStream = new StreamHandler($logFilesFailedStreamFilePath, Logger::INFO);
		$logFilesFailedStream->setFormatter($logFormatter);
		$this->logFilesFailed = new Logger('flickr_downloader');
		$this->logFilesFailed->pushHandler($logFilesFailedStream);
		
		$this->log->info('start');
		$this->logFilesFailed->info('start');

		// Destination directory. Default to 'photosets'.
		$customDestDir = $input->getOption('destination');
		if(!empty($customDestDir)){
			$this->dstDirPath = rtrim($customDestDir, '/');
		}
		if(!$filesystem->exists($this->dstDirPath)){
			$filesystem->mkdir($this->dstDirPath);
		}

		// Force download?
		$this->forceDownload = $input->getOption('force');

		// Load and check the configuration file.
		if($input->hasOption('config') && $input->getOption('config')){
			$this->configPath = $input->getOption('config');
		}
		if(!$filesystem->exists($this->configPath)){
			$this->log->critical('Config file not found: '.$this->configPath);
			return 1;
		}
		$this->log->info('Config file: '.$this->configPath);
		$config = Yaml::parse($this->configPath);
		if(
			!isset($config)
			|| !isset($config['flickr'])
			|| !isset($config['flickr']['consumer_key'])
			|| !isset($config['flickr']['consumer_secret'])
		){
			$this->log->critical('[main] config invalid');
			return 1;
		}

		// Set up the Flickr API.
		$metadata = new Metadata($config['flickr']['consumer_key'], $config['flickr']['consumer_secret']);
		$metadata->setOauthAccess($config['flickr']['token'], $config['flickr']['token_secret']);
		$apiFactory = new ApiFactory($metadata, new RezzzaGuzzleAdapter());

		// Run the actual download.
		if($input->getOption('id-dirs')){
			// If downloaded files should be saved into download-dir/hash/hash/photo-id/ directories.
			$this->log->info('Downloading to ID-based directories in: '.$this->dstDirPath);
			$this->downloadById($apiFactory, $filesystem);
		}
		else{
			// If download directories should match Album titles.
			$this->log->info('Downloading to Album-based directories in: '.$this->dstDirPath);
			$this->downloadByAlbumTitle($apiFactory, $input, $filesystem);
		}
		return 0;
	}

	/**
	 * Download photos to directories named after the album (i.e. photoset, in the original parlance).
	 * 
	 * @param ApiFactory $apiFactory
	 * @param InputInterface $input
	 * @param Filesystem $filesystem
	 * @return integer
	 */
	protected function downloadByAlbumTitle(ApiFactory $apiFactory, InputInterface $input, Filesystem $filesystem){
		$xml = $apiFactory->call('flickr.photosets.getList');
		
		$photosets = $input->getArgument('photosets');
		
		$photosetsInUse = array();
		if(count($photosets)){
			
			$photosetsTitles = array();
			foreach($xml->photosets->photoset as $photoset){
				if($this->exit){
					break;
				}
				
				$photosetsTitles[] = (string)$photoset->title;
			}
			
			asort($photosetsTitles);
			
			foreach($photosets as $argPhotosetTitle){
				if($this->exit){
					break;
				}
				
				if(in_array($argPhotosetTitle, $photosetsTitles)){
					$photosetsInUse[] = $argPhotosetTitle;
				}
			}
			
			foreach($photosets as $argPhotosetTitle){
				if($this->exit){
					break;
				}
				
				if(!in_array($argPhotosetTitle, $photosetsInUse)){
					foreach($photosetsTitles as $photosetTitle){
						if(fnmatch($argPhotosetTitle, $photosetTitle)){
							$photosetsInUse[] = $photosetTitle;
						}
					}
				}
			}
		}
		else{
			foreach($xml->photosets->photoset as $photoset){
				if($this->exit){
					break;
				}
				
				$photosetsInUse[] = $photoset->title;
			}
		}
		
		$totalDownloaded = 0;
		$totalFiles = 0;
		
		/** @var $photoset SimpleXMLElement */
		foreach($xml->photosets->photoset as $photoset){
			if($this->exit){
				break;
			}
			
			if(!in_array($photoset->title, $photosetsInUse)){
				continue;
			}
			
			$photosetId = (int)$photoset->attributes()->id;
			$photosetTitle = (string)$photoset->title;
			$this->log->info('[photoset] '.$photosetTitle);
			
			$dstDirFullPath = $this->dstDirPath.'/'.$photosetTitle;
			
			if(!$filesystem->exists($dstDirFullPath)){
				$this->log->info('[dir] create: '.$dstDirFullPath);
				$filesystem->mkdir($dstDirFullPath);
			}
			
			$this->log->info('[photoset] '.$photosetTitle.': get photo list');
			$xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', array(
				'photoset_id' => $photosetId,
			));
			$xmlPhotoListPagesTotal = (int)$xmlPhotoList->photoset->attributes()->pages;
			// $xmlPhotoListPhotosTotal = (int)$xmlPhotoList->photoset->attributes()->total;
			
			$fileCount = 0;
			
			for($page = 1; $page <= $xmlPhotoListPagesTotal; $page++){
				if($this->exit){
					break;
				}
				
				$this->log->info('[page] '.$page);
				
				if($page > 1){
					$this->log->info('[photoset] '.$photosetTitle.': get photo list');
					$xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', array(
						'photoset_id' => $photosetId,
						'page' => $page,
					));
				}
				
				/** @var $photo SimpleXMLElement */
				foreach($xmlPhotoList->photoset->photo as $photo){
					if($this->exit){
						break;
					}
					$this->log->debug('[media] '.$page.'/'.$fileCount.' '.$photo['id']);
					$downloaded = $this->fetchSinglePhoto($apiFactory, $photo, $dstDirFullPath, $filesystem);
					if(isset($downloaded->filesize)){
						$totalDownloaded += $downloaded->filesize;
					}
					$fileCount++;
				}
			}
			
		}

		$bytesize = new ByteSize();
		$this->log->info('[main] total downloaded: '.($totalDownloaded > 0 ? $bytesize->format($totalDownloaded) : 0));
		$this->log->info('[main] total files:      '.$totalFiles);
		$this->log->info('[main] end');
		
		$this->log->info('exit');
		$this->logFilesFailed->info('exit');
		
		return $this->exit;
	}

	/**
	 * Download a single given photo from Flickr. Won't be downloaded if already exists locally; if it is downloaded the
	 * additional 'filesize' property will be set on the return element.
	 * 
	 * @param ApiFactory $apiFactory
	 * @param SimpleXMLElement $photo
	 * @param string $dstDirFullPath
	 * @param Filesystem $filesystem
	 * @param string $basename The filename to save the downloaded file to (without extension).
	 * @return SimpleXMLElement|boolean Photo metadata as returned by Flickr, or false if something went wrong.
	 * @throws Exception
	 */
	protected function fetchSinglePhoto(ApiFactory $apiFactory, SimpleXMLElement $photo, $dstDirFullPath, Filesystem $filesystem, $basename = null){
		$id = (string)$photo->attributes()->id;
		
		try{
			$xmlPhoto = $apiFactory->call('flickr.photos.getInfo', array(
				'photo_id' => $id,
				'secret' => (string)$photo->attributes()->secret,
			));
			if(!$xmlPhoto){
				return false;
			}
		}
		catch(Exception $e){
			$this->log->error(sprintf('%s, GETINFO FAILED: %s',
				$id, $e->getMessage()
			));
			
			$this->logFilesFailed->error($id);

			return false;
		}

		$title = isset($xmlPhoto->photo->title) && (string)$xmlPhoto->photo->title ? (string)$xmlPhoto->photo->title : '';
		$server = (string)$xmlPhoto->photo->attributes()->server;
		$farm = (string)$xmlPhoto->photo->attributes()->farm;
		$originalSecret = (string)$xmlPhoto->photo->attributes()->originalsecret;
		$originalFormat = (string)$xmlPhoto->photo->attributes()->originalformat;
		$description = (string)$xmlPhoto->photo->description;
		$media = (string)$xmlPhoto->photo->attributes()->media;
		$ownerPathalias = (string)$xmlPhoto->photo->owner->attributes()->path_alias;
		$ownerNsid = (string)$xmlPhoto->photo->owner->attributes()->nsid;

		// Set the filename.
		if(!empty($basename)){
			$fileName = $basename.'.'.$originalFormat;
		}
		else{
			$fileName = ($title ? $title : $id).'.'.$originalFormat;
		}
		$filePath = rtrim($dstDirFullPath, '/').'/'.$fileName;
		$filePathTmp = $dstDirFullPath.'/'.$id.'.'.$originalFormat.'.tmp';

		if($filesystem->exists($filePath) && !$this->forceDownload){
			$this->log->debug('File '.$id.' already downloaded to '.$filePath);
			return $xmlPhoto->photo;
		}

		// URL format for the original image. See https://www.flickr.com/services/api/misc.urls.html
		// https://farm{farm-id}.staticflickr.com/{server-id}/{id}_{o-secret}_o.(jpg|gif|png)
		$urlFormat = 'https://farm%s.staticflickr.com/%s/%s_%s_o.%s';
		$url = sprintf($urlFormat, $farm, $server, $id, $originalSecret, $originalFormat);

		if($media == 'video'){
			// $url = 'http://www.flickr.com/photos/'.$ownerPathalias.'/'.$id.'/play/orig/'.$originalSecret.'/';
			// $url = 'https://www.flickr.com/video_download.gne?id='.$id;

			// $contentDispositionHeaderArray = array();

			// try{
			// 	$client = new GuzzleHttpClient();
			// 	$request = $client->head($url);
			// 	$response = $request->send();

			// 	$url = $response->getEffectiveUrl();

			// 	$contentDispositionHeader = $response->getHeader('content-disposition');
			// 	$contentDispositionHeaderArray = $contentDispositionHeader->toArray();
			// }
			// catch(Exception $e){
				// $this->log->info(sprintf('[%s] %s, farm %s, server %s, %s HEAD FAILED: %s',
				// 	$media, $id, $farm, $server, $fileName, $e->getMessage()));
			// 	$this->logFilesFailed->error($id.'.'.$originalFormat);

			// 	continue;
			// }

			// if(count($contentDispositionHeaderArray)){
			// 	$pos = strpos(strtolower($contentDispositionHeaderArray[0]), 'filename=');
			// 	if($pos !== false){
			// 		$pathinfo = pathinfo(substr($contentDispositionHeaderArray[0], $pos + 9));
			// 		if(isset($pathinfo['extension'])){
			// 			$originalFormat = $pathinfo['extension'];
			// 			$fileName = ($title ? $title : $id).'.'.$originalFormat;
			// 			$filePath = $dstDirFullPath.'/'.$fileName;
			// 			$filePathTmp = $dstDirFullPath.'/'.$id.'.'.$originalFormat.'.tmp';

			// 			if($filesystem->exists($filePath)){
			// 				continue;
			// 			}
			// 		}
			// 	}
			// }

			$this->log->error('video not supported yet');
			$this->logFilesFailed->error($id.': video not supported yet');
			return false;
		}

		$client = new GuzzleHttpClient($url);
		$stream = null;

		$streamRequestFactory = new PhpStreamRequestFactory();
		try{
			$request = $client->get();
			$stream = $streamRequestFactory->fromRequest($request);
		}
		catch(Exception $e){
			$this->log->error(sprintf('[%s] %s, farm %s, server %s, %s FAILED: %s',
				$media, $id, $farm, $server, $fileName, $e->getMessage()
			));
			$this->logFilesFailed->error($id.'.'.$originalFormat);

			return false;
		}

		$size = $stream->getSize();
		$bytesize = new ByteSize();
		if($size !== false){
			$sizeStr = $bytesize->format((int)$size);
		}
		else{
			$sizeStr = 'N/A';
		}

		$this->log->info(sprintf("[%s] %s, farm %s, server %s, %s, '%s', %s",
			$media, $id, $farm, $server, $fileName, $description, $sizeStr
		));

		$timePrev = time();
		$downloaded = 0;
		$downloadedPrev = 0;
		$downloadedDiff = 0;

		$fh = fopen($filePathTmp, 'wb');
		if($fh === false){
			throw new Exception('Unable to open '.$filePathTmp.' for writing.');
		}
		while(!$stream->feof()){
			pcntl_signal_dispatch();
			if($this->exit){
				break;
			}

			$data = $stream->read(FlickrCli::DOWNLOAD_STREAM_READ_LEN);
			$dataLen = strlen($data);
			fwrite($fh, $data);

			$downloaded += $dataLen;
			//$totalDownloaded += $dataLen;

			$percent = 0;
			if($size !== false){
				$percent = $downloaded / $size * 100;
			}
			if($percent > 100){
				$percent = 100;
			}

			$progressbarDownloaded = round($percent / 100 * FlickrCli::DOWNLOAD_PROGRESSBAR_ITEMS);
			$progressbarRest = FlickrCli::DOWNLOAD_PROGRESSBAR_ITEMS - $progressbarDownloaded;

			$timeCur = time();
			if($timeCur != $timePrev){
				$timePrev = $timeCur;
				$downloadedDiff = $downloaded - $downloadedPrev;
				$downloadedPrev = $downloaded;
			}

			$downloadedDiffStr = '';
			if($downloadedDiff){
				$downloadedDiffStr = $bytesize->format($downloadedDiff).'/s';
			}

			if($size !== false){
				// If we know the stream size, show a progress bar.
				printf("[file] %6.2f%% [%s%s] %s %10s\x1b[0K\r",
					$percent,
					str_repeat('#', $progressbarDownloaded),
					str_repeat(' ', $progressbarRest),
					number_format($downloaded),
					$downloadedDiffStr
				);
			}
			else{
				// Otherwise, just show the amount downloaded and speed.
				printf("[file] %s %10s\x1b[0K\r", number_format($downloaded), $downloadedDiffStr );
			}
		}
		fclose($fh);
		print "\n";

		$fileTmpSize = filesize($filePathTmp);

		if($this->exit){
			$filesystem->remove($filePathTmp);
		}
		elseif(($size && $fileTmpSize != $size) || $fileTmpSize <= 1024){
			$filesystem->remove($filePathTmp);

			$this->log->error('['.$media.'] '.$id.' FAILED: temp file size wrong: '.$fileTmpSize);
			$this->logFilesFailed->error($id.'.'.$originalFormat);
		}
		else{
			// Rename to its final destination, and return the photo metadata.
			$filesystem->rename($filePathTmp, $filePath, $this->forceDownload);
			$xmlPhoto->photo->filesize = $size;
			return $xmlPhoto->photo;
		}
	}

	/**
	 * Download all photos, whether in a set/album or not, into directories named by photo ID.
	 * @param ApiFactory $apiFactory
	 * @param Filesystem $filesystem
	 */
	public function downloadById(ApiFactory $apiFactory, Filesystem $filesystem){
		// 1. Download any photos not in a set.
		$notInSetPage = 1;
		do{
			$notInSet = $apiFactory->call('flickr.photos.getNotInSet', ['page' => $notInSetPage]);
			$this->log->info('Not in set p'.$notInSetPage.'/'.$notInSet->photos['pages']);
			$notInSetPage++;
			foreach($notInSet->photos->photo as $photo){
				$this->downloadByIdOnePhoto($photo, $apiFactory, $filesystem);
			}
		}while($notInSetPage <= $notInSet->photos['pages']);

		// 2. Download all photos in all sets.
		$setsPage = 1;
		do{
			$sets = $apiFactory->call('flickr.photosets.getList', ['page' => $setsPage]);
			$this->log->info('Sets p'.$setsPage.'/'.$sets->photosets['pages']);
			foreach($sets->photosets->photoset as $set){
				// Loop through all pages in this set.
				$setPhotosPage = 1;
				do{
					$params = [
						'photoset_id' => $set['id'],
						'page' => $setPhotosPage,
					];
					$setPhotos = $apiFactory->call('flickr.photosets.getPhotos', $params);
					$this->log->info(sprintf('[Set %s] %s photos (p%s/%s)',
						$set->title, $setPhotos->photoset['total'], $setPhotosPage, $setPhotos->photoset['pages']));
					foreach($setPhotos->photoset->photo as $photo){
						$this->downloadByIdOnePhoto($photo, $apiFactory, $filesystem);
					}
					$setPhotosPage++;
				}while($setPhotosPage <= $setPhotos->photos['pages']);
			}
			$setsPage++;
		}while($setsPage <= $sets->photosets['pages']);
	}

	/**
	 * Download a single photo.
	 * @param SimpleXMLElement $photo Basic photo metadata.
	 * @param ApiFactory $apiFactory
	 * @param Filesystem $filesystem
	 */
	protected function downloadByIdOnePhoto(SimpleXMLElement $photo, ApiFactory $apiFactory, Filesystem $filesystem){
		$idHash = md5($photo['id']);
		$destinationPath = $this->dstDirPath.'/'.$idHash[0].$idHash[1].'/'.$idHash[2].$idHash[3].'/'.$photo['id'].'/';
		if(!$filesystem->exists($destinationPath)){
			$filesystem->mkdir($destinationPath);
		}

		// Save the actual file.
		$info = $this->fetchSinglePhoto($apiFactory, $photo, $destinationPath, $filesystem, $photo['id']);
		if($info === false){
			$this->log->error('Unable to get metadata about photo: '.$photo['id']);
			return;
		}

		// Also save metadata to a separate Yaml file.
		$metadata = [
			'id' => (int)$info['id'],
			'title' => (string)$info->title,
			'license' => (string)$info['license'],
			'safety_level' => (string)$info['safety_level'],
			'rotation' => (string)$info['rotation'],
			'media' => (string)$info['media'],
			'format' => (string)$info['originalformat'],
			'owner' => [
				'nsid' => (string)$info->owner['nsid'],
				'username' => (string)$info->owner['username'],
				'realname' => (string)$info->owner['realname'],
				'path_alias' => (string)$info->owner['path_alias'],
			],
			'visibility' => [
				'ispublic' => (boolean)$info->visibility['ispublic'],
				'isfriend' => (boolean)$info->visibility['isfriend'],
				'isfamily' => (boolean)$info->visibility['isfamily'],
			],
			'dates' => [
				'posted' => (string)$info->dates['posted'],
				'taken' => (string)$info->dates['taken'],
				'takengranularity' => (int)$info->dates['takengranularity'],
				'takenunknown' => (string)$info->dates['takenunknown'],
				'lastupdate' => (string)$info->dates['lastupdate'],
				'uploaded' => (string)$info['dateuploaded'],
			],
			'tags' => [],
			'sets' => [],
			'pools' => [],
		];
		if(isset($info->photo->description->_content)){
			$metadata['description'] = (string)$info->photo->description->_content;
		}
		if(isset($info->tags->tag)){
			foreach($info->tags->tag as $tag){
				$metadata['tags'][] = [
					'id' => (string)$tag['id'],
					'slug' => (string)$tag,
					'title' => (string)$tag['raw'],
					'machine' => $tag['machine_tag'] !== '0',
				];
			}
		}
		if(isset($info->location)){
			$metadata['location'] = [
				'latitude' => (float)$info->location['latitude'],
				'longitude' => (float)$info->location['longitude'],
				'accuracy' => (integer)$info->location['accuracy'],
			];
		}
		$contexts = $apiFactory->call('flickr.photos.getAllContexts', [ 'photo_id' => $info['id'] ]);
		foreach($contexts->set as $set){
			$metadata['sets'][] = [
				'id' => (string)$set['id'],
				'title' => (string)$set['title'],
			];
		}
		foreach($contexts->pool as $pool){
			$metadata['pools'][] = [
				'id' => (string)$pool['id'],
				'title' => (string)$pool['title'],
				'url' => (string)$pool['url'],
			];
		}
		file_put_contents($destinationPath.'/metadata.yml', Yaml::dump($metadata));
	}

	private function signalHandlerSetup(){
		if(function_exists('pcntl_signal')){
			$this->log->info('Setup Signal Handler');
			
			declare(ticks = 1);
			
			$setup = pcntl_signal(SIGTERM, array($this, 'signalHandler'));
			$this->log->debug('Setup Signal Handler, SIGTERM: '.($setup ? 'OK' : 'FAILED'));
			
			$setup = pcntl_signal(SIGINT, array($this, 'signalHandler'));
			$this->log->debug('Setup Signal Handler, SIGINT: '.($setup ? 'OK' : 'FAILED'));
			
			$setup = pcntl_signal(SIGHUP, array($this, 'signalHandler'));
			$this->log->debug('Setup Signal Handler, SIGHUP: '.($setup ? 'OK' : 'FAILED'));
		}
		else{
			$this->log->warning('pcntl_signal() function not found for Signal Handler Setup');
		}
	}
	
	private function signalHandler($signal){
		$this->exit++;
		
		switch($signal){
			case SIGTERM:
				$this->log->notice('signal: SIGTERM');
				break;
			case SIGINT:
				print PHP_EOL;
				$this->log->notice('signal: SIGINT');
				break;
			case SIGHUP:
				$this->log->notice('signal: SIGHUP');
				break;
			case SIGQUIT:
				$this->log->notice('signal: SIGQUIT');
				break;
			case SIGKILL:
				$this->log->notice('signal: SIGKILL');
				break;
			case SIGUSR1:
				$this->log->notice('signal: SIGUSR1');
				break;
			default:
				$this->log->notice('signal: N/A');
		}
		
		$this->log->notice('main abort ['.$this->exit.']');
		
		if($this->exit >= 2){
			exit(1);
		}
	}
	
}
