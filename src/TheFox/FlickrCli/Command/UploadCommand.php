<?php

namespace TheFox\FlickrCli\Command;

use Exception;
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
// use Monolog\Handler\ErrorLogHandler;
use Rych\ByteSize\ByteSize;
use Carbon\Carbon;

use TheFox\FlickrCli\FlickrCli;

class UploadCommand extends Command{
	
	public $exit = 0;
	private $configPath;
	private $logDirPath;
	private $log;
	private $logFilesSuccessful;
	private $logFilesFailed;
	private $uploadFileSize;
	private $uploadFileSizeLen;
	
	protected function configure(){
		$this->setName('upload');
		$this->setDescription('Upload files to Flickr.');
		
		$this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: config.yml');
		$this->addOption('log', 'l', InputOption::VALUE_OPTIONAL, 'Path to log directory. Default: log');
		$this->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'Description for all uploaded files.');
		$this->addOption('tags', 't', InputOption::VALUE_OPTIONAL, 'Comma separated names. For example: --tags=tag1,tag2');
		$this->addOption('sets', 's', InputOption::VALUE_OPTIONAL, 'Comma separated names. For example: --sets=set1,set2');
		$this->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Recurse into directories.');
		$this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would have been transferred.');
		
		$this->addArgument('directory', InputArgument::IS_ARRAY, 'Path to directories.');

		$this->configPath = 'config.yml';
		$this->configRealPath = 'config.yml';
		$this->logDirPath = 'log';
	}
	
	protected function execute(InputInterface $input, OutputInterface $output){
		if($input->hasOption('config') && $input->getOption('config')){
			$this->configPath = $input->getOption('config');
		}
		$this->configRealPath = realpath($this->configPath);
		
		$filesystem = new Filesystem();
		if(!$filesystem->exists($this->configPath)){
			$output->writeln('ERROR: config file not found: '.$this->configPath);
			return 1;
		}
		
		if($input->hasOption('log') && $input->getOption('log')){
			$this->logDirPath = $input->getOption('log');
		}
		if(!$filesystem->exists($this->logDirPath)){
			$filesystem->mkdir($this->logDirPath);
		}
		
		$now = Carbon::now();
		$nowFormated = $now->format('Ymd');
		
		$logFormatter = new LineFormatter("[%datetime%] %level_name%: %message%\n"); # %context%
		$this->log = new Logger('flickr_uploader');

		$logHandlerStderr = new StreamHandler('php://stderr', Logger::DEBUG);
		$logHandlerStderr->setFormatter($logFormatter);
		$this->log->pushHandler($logHandlerStderr);

		$logHandlerFile = new StreamHandler($this->logDirPath.'/flickr_upload_'.$nowFormated.'.log', Logger::INFO);
		$logHandlerFile->setFormatter($logFormatter);
		$this->log->pushHandler($logHandlerFile);

		$logFilesSuccessfulStream = new StreamHandler($this->logDirPath.'/flickr_upload_files_successful_'.$nowFormated.'.log', Logger::INFO);
		$logFilesSuccessfulStream->setFormatter($logFormatter);
		$this->logFilesSuccessful = new Logger('flickr_uploader');
		$this->logFilesSuccessful->pushHandler($logFilesSuccessfulStream);

		$logFilesFailedStream = new StreamHandler($this->logDirPath.'/flickr_upload_files_failed_'.$nowFormated.'.log', Logger::INFO);
		$logFilesFailedStream->setFormatter($logFormatter);
		$this->logFilesFailed = new Logger('flickr_uploader');
		$this->logFilesFailed->pushHandler($logFilesFailedStream);
		
		$config = Yaml::parse($this->configPath);
		
		if(
			!isset($config)
			|| !isset($config['flickr'])
			|| !isset($config['flickr']['consumer_key'])
			|| !isset($config['flickr']['consumer_secret'])
			|| !isset($config['upload']['move_on_success'])
			|| !isset($config['upload']['uploaded_dir'])
		){
			$this->log->critical('[main] config invalid');
			return 1;
		}
		
		$this->log->info('start');
		$this->logFilesSuccessful->info('start');
		$this->logFilesFailed->info('start');
		
		$this->log->info('Config file: '.$this->configPath);
		
		$description = null;
		if($input->hasOption('description') && $input->getOption('description')){
			$description = $input->getOption('description');
			$this->log->info('Description: '.$description);
		}
		
		$tags = null;
		if($input->hasOption('tags') && $input->getOption('tags')){
			$tags = $input->getOption('tags');
			$this->log->debug('Tags String: '.$tags);
		}
		
		$recursive = $input->getOption('recursive');
		$dryrun = $input->getOption('dry-run');
		
		// $this->log->info('Recursive: '.(int)$recursive);
		// $this->log->info('Dry Run: '.(int)$dryrun);
		// return 0;
		
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
		$uploadedDiffPrev = array(0, 0, 0, 0, 0);

		$curlOptions[CURLOPT_PROGRESSFUNCTION] = function($ch, $dlTotal = 0, $dlNow = 0, $ulTotal = 0, $ulNow = 0)
			use($timePrev, $uploadedTotal, $uploadedPrev, $uploadedDiffPrev){
			
			$uploadedDiff = $ulNow - $uploadedPrev;
			$uploadedPrev = $ulNow;
			$uploadedTotal += $uploadedDiff;
			
			$percent = 0;
			if($ulTotal){
				$percent = $ulNow / $ulTotal * 100;
			}
			if($percent > 100){
				$percent = 100;
			}
			
			$progressbarUploaded = round($percent / 100 * FlickrCli::UPLOAD_PROGRESSBAR_ITEMS);
			$progressbarRest = FlickrCli::UPLOAD_PROGRESSBAR_ITEMS - $progressbarUploaded;
			
			$uploadedDiffStr = '';
			$timeCur = time();
			if($timeCur != $timePrev){
				$timePrev = $timeCur;
				
				$uploadedDiff = ($uploadedDiff + array_sum($uploadedDiffPrev)) / 6;
				array_shift($uploadedDiffPrev);
				$uploadedDiffPrev[] = $uploadedDiff;
				
				if($uploadedDiff > 0){
					$bytesize = new ByteSize();
					$uploadedDiffStr = $bytesize->format($uploadedDiff).'/s';
				}
			}
			
			printf("[file] %6.2f%% [%s%s] %s %10s\x1b[0K\r",
				$percent,
				str_repeat('#', $progressbarUploaded),
				str_repeat(' ', $progressbarRest),
				number_format($ulNow),
				$uploadedDiffStr
			);
			
			pcntl_signal_dispatch();
			
			return $this->exit >= 2 ? 1 : 0;
		};
		$guzzleAdapterClientConfig->set(GuzzleHttpClient::CURL_OPTIONS, $curlOptions);

		$apiFactory = new ApiFactory($metadata, $guzzleAdapter);
		$apiFactoryVerbose = new ApiFactory($metadata, $guzzleAdapterVerbose);
		
		$photosetNames = array();
		if($input->getOption('sets')){
			$photosetNames = preg_split('/,/', $input->getOption('sets'));
		}
		
		$photosetAll = array();
		$photosetAllLower = array();

		$xml = null;
		try{
			$xml = $apiFactory->call('flickr.photosets.getList');
		}
		catch(Exception $e){
			$this->log->critical('[main] flickr.photosets.getList ERROR: '.$e->getMessage());
			return 1;
		}

		foreach($xml->photosets->photoset as $n => $photoset){
			pcntl_signal_dispatch();
			if($this->exit){
				break;
			}
			
			$photosetAll[(int)$photoset->attributes()->id] = (string)$photoset->title;
			$photosetAllLower[(int)$photoset->attributes()->id] = strtolower((string)$photoset->title);
		}

		$photosets = array();
		$photosetsNew = array();
		foreach($photosetNames as $photosetTitle){
			$id = 0;
			
			foreach($photosetAllLower as $photosetAllId => $photosetAllTitle){
				if(strtolower($photosetTitle) == $photosetAllTitle){
					$id = $photosetAllId;
					break;
				}
			}
			if($id){
				$photosets[] = $id;
			}
			else{
				$photosetsNew[] = $photosetTitle;
			}
		}
		
		$configUploadedBaseDir = '';
		if($config['upload']['move_on_success']){
			$configPathDirname = realpath(dirname($this->configRealPath));
			$configUploadedBaseDir = $configPathDirname.'/'.$config['upload']['uploaded_dir'];
			
			if(!$filesystem->exists($configUploadedBaseDir)){
				$config['upload']['move_on_success'] = $filesystem->mkdir($configUploadedBaseDir);
				$this->log->info('[main] create dir: '.$configUploadedBaseDir.', '.($config['upload']['move_on_success'] ? 'OK' : 'FAILED'));
			}
			
			$this->log->info('[config] move on success: '.($config['upload']['move_on_success'] ? 'Y' : 'N'));
			if($config['upload']['move_on_success']){
				$this->log->info('[config] move after upload path: '.$configUploadedBaseDir);
			}
		}
		
		$totalFiles = 0;
		$totalFilesUploaded = 0;
		$fileErrors = 0;
		$filesFailed = array();
		
		$finderFilter = $filter = function(SplFileInfo $file){
			if(in_array($file->getFilename(), FlickrCli::FILES_INORE)){
				return false;
			}
			return true;
		};
		$finder = new Finder();
		$finder->files()
				->filter($filter);
		if(!$recursive){
			$finder->depth(0);
		}
		
		$directories = $input->getArgument('directory');
		foreach($directories as $argDir){
			
			$srcDir = new SplFileInfo($argDir);
			
			$uploadBaseDirPath = '';
			if($configUploadedBaseDir){
				$uploadBaseDirPath = $configUploadedBaseDir.'/'.str_replace('/', '_', $argDir);
			}
			
			$this->log->info('[dir] upload dir: '.$argDir.' '.$uploadBaseDirPath);
			
			foreach($finder->in($argDir) as $file){
				pcntl_signal_dispatch();
				if($this->exit){
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
				$uploaded = 0;
				$timePrev = time();
				
				$uploadDirPath = '';
				if($uploadBaseDirPath){
					$uploadDirPath = $uploadBaseDirPath.'/'.$dirRelativePath;
					
					if(!$filesystem->exists($uploadDirPath)){
						$this->log->info("[dir] create '".$uploadDirPath."'");
						$filesystem->mkdir($uploadDirPath);
					}
				}
				
				$totalFiles++;
				
				if(!in_array(strtolower($fileExt), FlickrCli::ACCEPTED_EXTENTIONS)){
					$fileErrors++;
					$filesFailed[] = $fileRelativePathStr;
					$this->log->error('[file] invalid extension: '.$fileRelativePathStr);
					$this->logFilesFailed->error($fileRelativePathStr);
					
					continue;
				}
				
				$bytesize = new ByteSize();
				
				if($dryrun){
					$this->log->info("[file] dry upload '".$fileRelativePathStr."' '".$dirRelativePath."' ".$bytesize->format($this->uploadFileSize));
					continue;
				}
				
				$this->log->info("[file] upload '".$fileRelativePathStr."'  ".$bytesize->format($this->uploadFileSize));
				$xml = null;
				try{
					$xml = $apiFactoryVerbose->upload($filePath, $fileName, $description, $tags);
					
					// print "\r\x1b[0K";
					print "\n";
				}
				catch(Exception $e){
					$this->log->error('[file] upload: '.$e->getMessage());
					$xml = null;
				}
				
				$photoId = 0;
				$stat = '';
				$successful = false;
				if($xml){
					$photoId = isset($xml->photoid) ? (int)$xml->photoid : 0;
					$stat = isset($xml->attributes()->stat) ? strtolower((string)$xml->attributes()->stat) : '';
					$successful = $stat == 'ok' && $photoId != 0;
				}
				
				$logLine = '';
				if($successful){
					$logLine = 'OK';
					$totalFilesUploaded++;
					
					$this->logFilesSuccessful->info($fileRelativePathStr);
					
					if($config['upload']['move_on_success'] && $uploadBaseDirPath){
						$this->log->info('[file] move to uploaded dir: '.$uploadDirPath);
						$filesystem->rename($filePath, $uploadDirPath.'/'.$fileName);
					}
				}
				else{
					$logLine = 'FAILED';
					$fileErrors++;
					$filesFailed[] = $fileRelativePathStr;
					
					$this->logFilesFailed->error($fileRelativePathStr);
				}
				$this->log->info('[file] status: '.$logLine.' - ID '.$photoId);
				
				if(!$successful){
					continue;
				}
				
				if($photosetsNew){
					foreach($photosetsNew as $photosetTitle){
						$this->log->info('[photoset] create '.$photosetTitle.' ... ');
						
						$photosetId = 0;
						$xml = null;
						try{
							$xml = $apiFactory->call('flickr.photosets.create', array(
								'title' => $photosetTitle,
								'primary_photo_id' => $photoId,
							));
						}
						catch(Exception $e){
							$this->log->critical('[photoset] create '.$photosetTitle.' FAILED: '.$e->getMessage());
							return 1;
						}
						if($xml){
							if( (string)$xml->attributes()->stat == 'ok' ){
								$photosetId = (int)$xml->photoset->attributes()->id;
								$photosets[] = $photosetId;
								
								$this->log->info('[photoset] create '.$photosetTitle.' OK - ID '.$photosetId);
							}
							else{
								$code = (int)$xml->err->attributes()->code;
								$this->log->critical('[photoset] create '.$photosetTitle.' FAILED: '.$code);
								return 1;
							}
						}
						else{
							$this->log->critical('[photoset] create '.$photosetTitle.' FAILED');
							return 1;
						}
					}
					$photosetsNew = null;
				}
				
				if(count($photosets)){
					$this->log->info('[file] add to sets ... ');
					
					$logLine = '';
					foreach($photosets as $photosetId){
						$logLine .= substr($photosetId, -5).' ';
						
						$xml = null;
						try{
							$xml = $apiFactory->call('flickr.photosets.addPhoto', array(
								'photoset_id' => $photosetId,
								'photo_id' => $photoId,
							));
						}
						catch(Exception $e){
							$this->log->critical('[file] add to sets FAILED: '.$e->getMessage());
							return 1;
						}
						if($xml){
							if($xml->attributes()->stat == 'ok'){
								$logLine .= 'OK ';
							}
							else{
								if(isset($xml->err)){
									$code = (int)$xml->err->attributes()->code;
									if($code == 3){
										$logLine .= 'OK ';
									}
									else{
										$this->log->critical('[file] add to sets FAILED: '.$code);
										return 1;
									}
								}
								else{
									$this->log->critical('[file] add to sets FAILED');
									return 1;
								}
							}
						}
					}
					
					$this->log->info('[file] added to sets: '.$logLine);
				}
			}
		}
		
		$this->log->info('[main] total uploaded: '.($uploadedTotal > 0 ? $bytesize->format($uploadedTotal) : 0));
		$this->log->info('[main] total files:    '.$totalFiles);
		$this->log->info('[main] files uploaded: '.$totalFilesUploaded);
		$this->log->info('[main] files failed:   '.$fileErrors.( count($filesFailed) ? "\n".join("\n", $filesFailed) : '' ));
		
		$this->log->info('exit');
		$this->logFilesSuccessful->info('exit');
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
