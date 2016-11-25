<?php

namespace TheFox\FlickrCli\Command;

// use Exception;
// use SplFileInfo;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
// use Symfony\Component\Finder\Finder;
use Rezzza\Flickr\Metadata;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;
use Guzzle\Http\Client as GuzzleHttpClient;
use Guzzle\Stream\PhpStreamRequestFactory;
// use Guzzle\Http\Message\Response;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
// use Monolog\Handler\ErrorLogHandler;
use Rych\ByteSize\ByteSize;
use Carbon\Carbon;

use TheFox\FlickrCli\FlickrCli;

class DownloadCommand extends Command{
	
	public $exit = 0;
	private $configPath;
	private $logDirPath;
	private $dstDirPath;
	private $log;
	private $logFilesFailed;
	
	protected function configure(){
		$this->setName('download');
		$this->setDescription('Download files from Flickr.');
		
		$this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: config.yml');
		$this->addOption('log', 'l', InputOption::VALUE_OPTIONAL, 'Path to log directory. Default: log');
		$this->addOption('destination', 'd', InputOption::VALUE_OPTIONAL, 'Path to save files.');
		// $this->addOption('tags', 't', InputOption::VALUE_OPTIONAL, 'Comma separated names. For example: --tags=tag1,tag2');
		// $this->addOption('sets', 's', InputOption::VALUE_OPTIONAL, 'Comma separated names. For example: --sets=set1,set2');
		// $this->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Recurse into directories.');
		// $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would have been transferred.');
		
		$this->addArgument('photosets', InputArgument::IS_ARRAY, 'Photosets to download.');

		$this->configPath = 'config.yml';
		$this->logDirPath = 'log';
		$this->dstDirPath = 'photosets';
	}
	
	protected function execute(InputInterface $input, OutputInterface $output){
		if($input->hasOption('config') && $input->getOption('config')){
			$this->configPath = $input->getOption('config');
		}
		
		$filesystem = new Filesystem();
		if(!$filesystem->exists($this->configPath)){
			$output->writeln('ERROR: config file not found: '.$this->configPath);
			return 1;
		}
		
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
		
		if($input->hasOption('log') && $input->getOption('log')){
			$this->logDirPath = $input->getOption('log');
		}
		if(!$filesystem->exists($this->logDirPath)){
			$filesystem->mkdir($this->logDirPath);
		}
		
		if($input->hasOption('destination')){
			$this->dstDirPath = $input->getOption('destination');
			if(!$filesystem->exists($this->dstDirPath)){
				$filesystem->mkdir($this->dstDirPath);
			}
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
		
		$logFilesFailedStream = new StreamHandler($this->logDirPath.'/flickr_download_files_failed_'.$nowFormated.'.log', Logger::INFO);
		$logFilesFailedStream->setFormatter($logFormatter);
		$this->logFilesFailed = new Logger('flickr_downloader');
		$this->logFilesFailed->pushHandler($logFilesFailedStream);
		
		$this->log->info('start');
		$this->logFilesFailed->info('start');
		
		$this->log->info('Config file: '.$this->configPath);
		
		$streamRequestFactory = new PhpStreamRequestFactory();
		$metadata = new Metadata($config['flickr']['consumer_key'], $config['flickr']['consumer_secret']);
		$metadata->setOauthAccess($config['flickr']['token'], $config['flickr']['token_secret']);

		$apiFactory = new ApiFactory($metadata, new RezzzaGuzzleAdapter());

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
		
		$bytesize = new ByteSize();
		
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
				
				foreach($xmlPhotoList->photoset->photo as $photo){
					if($this->exit){
						break;
					}
					
					$fileCount++;
					
					$id = (string)$photo->attributes()->id;
					
					$this->log->debug('[media] '.$page.'/'.$fileCount.' '.$id.': get info');
					$xmlPhoto = null;
					try{
						$xmlPhoto = $apiFactory->call('flickr.photos.getInfo', array(
							'photo_id' => $id,
							'secret' => (string)$photo->attributes()->secret,
						));
						if(!$xmlPhoto){
							continue;
						}
					}
					catch(Exception $e){
						$this->log->error('['.$media.'] '.$id.', farm '.$farm.', server '.$server.' GETINFO FAILED: '.$e->getMessage());
						$this->logFilesFailed->error($id.'.'.$originalFormat);
						
						continue;
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
					
					$fileName = ($title ? $title : $id).'.'.$originalFormat;
					$filePath = $dstDirFullPath.'/'.$fileName;
					$filePathTmp = $dstDirFullPath.'/'.$id.'.'.$originalFormat.'.tmp';
					
					if($filesystem->exists($filePath)){
						continue;
					}
					
					// $url = 'https://farm'.$farm.'.staticflickr.com/'.$server.'/'.$id.'_'.$originalSecret.'_o.'.$originalFormat;
					$url = sprintf('https://c1.staticflickr.com/%s/%s/%s_%s_o.png',
						$farm, $server, $id, $originalSecret
					);
					
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
						// 	$this->log->info('['.$media.'] '.$id.', farm '.$farm.', server '.$server.', '.$fileName.' HEAD FAILED: '.$e->getMessage());
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
						continue;
					}
					
					$client = new GuzzleHttpClient($url);
					$stream = null;
					
					try{
						$request = $client->get();
						$stream = $streamRequestFactory->fromRequest($request);
					}
					catch(Exception $e){
						$this->log->error('['.$media.'] '.$id.', farm '.$farm.', server '.$server.', '.$fileName.' FAILED: '.$e->getMessage());
						$this->logFilesFailed->error($id.'.'.$originalFormat);
						
						continue;
					}
					
					$downloaded = 0;
					$size = $stream->getSize();
					$sizeStr = 'N/A';
					if($size){
						$sizeStr = $bytesize->format($size);
					}
					
					$this->log->info(sprintf("[%s] %s/%s %s, farm %s, server %s, %s, '%s', %s",
						$media, $page, $fileCount, $id, $farm, $server, $fileName, $description, $sizeStr
					));
					
					$timePrev = time();
					$downloadedPrev = 0;
					$downloadedDiff = 0;
					
					$fh = fopen($filePathTmp, 'wb');
					while(!$stream->feof()){
						pcntl_signal_dispatch();
						if($this->exit){
							break;
						}
						
						$data = $stream->read(FlickrCli::DOWNLOAD_STREAM_READ_LEN);
						$dataLen = strlen($data);
						fwrite($fh, $data);
						
						$downloaded += $dataLen;
						$totalDownloaded += $dataLen;
						
						$percent = 0;
						if($size){
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
						
						printf("[file] %6.2f%% [%s%s] %s %10s\x1b[0K\r",
							$percent,
							str_repeat('#', $progressbarDownloaded),
							str_repeat(' ', $progressbarRest),
							number_format($downloaded),
							$downloadedDiffStr
						);
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
						while($filesystem->exists($filePath)){
							$filePath = fileNameCountup($filePath);
						}
						if($filePath){
							$filesystem->rename($filePathTmp, $filePath);
							$totalFiles++;
						}
					}
				}
			}
			
		}
		
		$this->log->info('[main] total downloaded: '.($totalDownloaded > 0 ? $bytesize->format($totalDownloaded) : 0));
		$this->log->info('[main] total files:      '.$totalFiles);
		$this->log->info('[main] end');
		
		$this->log->info('exit');
		$this->logFilesFailed->info('exit');
		
		return $this->exit;
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
