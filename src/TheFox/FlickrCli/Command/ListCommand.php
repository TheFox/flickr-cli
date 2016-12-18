<?php

namespace TheFox\FlickrCli\Command;

// use Exception;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
// use OAuth\Common\Consumer\Credentials;
// use OAuth\OAuth1\Signature\Signature;
// use OAuth\Common\Storage\Memory;
use Rezzza\Flickr\Metadata;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;

class ListCommand extends Command{
	
	public $exit = 0;
	
	/**
	 * @var string The name of the configuration file. Defaults to 'config.yml'.
	 */
	private $configPath;
	
	// private $log;
	
	protected function configure(){
		$this->setName('list');
		$this->setDescription('List Photosets.');
		
		$this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: config.yml');
		
		$this->configPath = 'config.yml';
	}
	
	protected function execute(InputInterface $input, OutputInterface $output){
		// $logFormatter = new LineFormatter("[%datetime%] %level_name%: %message%\n");
		// $this->log = new Logger('flickr_list');
		
		$this->signalHandlerSetup();
		
		// Load and check the configuration file.
		if($input->hasOption('config') && $input->getOption('config')){
			$this->configPath = $input->getOption('config');
		}
		$filesystem = new Filesystem();
		if(!$filesystem->exists($this->configPath)){
			// $this->log->critical('Config file not found: '.$this->configPath);
			return 1;
		}
		// $this->log->info('Config file: '.$this->configPath);
		$config = Yaml::parse($this->configPath);
		if(
			!isset($config)
			|| !isset($config['flickr'])
			|| !isset($config['flickr']['consumer_key'])
			|| !isset($config['flickr']['consumer_secret'])
		){
			// $this->log->critical('[main] config invalid');
			return 1;
		}
		
		// Set up the Flickr API.
		$metadata = new Metadata($config['flickr']['consumer_key'], $config['flickr']['consumer_secret']);
		$metadata->setOauthAccess($config['flickr']['token'], $config['flickr']['token_secret']);
		$apiFactory = new ApiFactory($metadata, new RezzzaGuzzleAdapter());
		$xml = $apiFactory->call('flickr.photosets.getList');
		
		$photosetsTitles = array();
		foreach($xml->photosets->photoset as $n => $photoset){
			if($this->exit){
				break;
			}
			
			$photosetsTitles[(int)$photoset->attributes()->id] = (string)$photoset->title;
		}
		
		asort($photosetsTitles);
		
		foreach($photosetsTitles as $photosetId => $photosetTitle){
			if($this->exit){
				break;
			}
			
			print $photosetTitle."\n";
		}
		
		return 0;
	}
	
	private function signalHandlerSetup(){
		if(function_exists('pcntl_signal')){
			// $this->log->info('Setup Signal Handler');
			
			declare(ticks = 1);
			
			$setup = pcntl_signal(SIGTERM, array($this, 'signalHandler'));
			// $this->log->debug('Setup Signal Handler, SIGTERM: '.($setup ? 'OK' : 'FAILED'));
			
			$setup = pcntl_signal(SIGINT, array($this, 'signalHandler'));
			// $this->log->debug('Setup Signal Handler, SIGINT: '.($setup ? 'OK' : 'FAILED'));
			
			$setup = pcntl_signal(SIGHUP, array($this, 'signalHandler'));
			// $this->log->debug('Setup Signal Handler, SIGHUP: '.($setup ? 'OK' : 'FAILED'));
		}
		// else{
		// 	$this->log->warning('pcntl_signal() function not found for Signal Handler Setup');
		// }
	}
	
	private function signalHandler($signal){
		$this->exit++;
		
		switch($signal){
			case SIGTERM:
				// $this->log->notice('signal: SIGTERM');
				break;
			case SIGINT:
				print PHP_EOL;
				// $this->log->notice('signal: SIGINT');
				break;
			case SIGHUP:
				// $this->log->notice('signal: SIGHUP');
				break;
			case SIGQUIT:
				// $this->log->notice('signal: SIGQUIT');
				break;
			case SIGKILL:
				// $this->log->notice('signal: SIGKILL');
				break;
			case SIGUSR1:
				// $this->log->notice('signal: SIGUSR1');
				break;
			default:
				// $this->log->notice('signal: N/A');
		}
		
		// $this->log->notice('main abort ['.$this->exit.']');
		
		if($this->exit >= 2){
			exit(1);
		}
	}
	
}
