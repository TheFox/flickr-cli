<?php

namespace TheFox\FlickrCli\Command;

use Exception;

use Symfony\Component\Console\Command\Command;
// use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use OAuth\Common\Consumer\Credentials;
use OAuth\OAuth1\Signature\Signature;
use OAuth\Common\Storage\Memory;
use Rezzza\Flickr\Metadata;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;

use TheFox\OAuth\Common\Http\Client\GuzzleStreamClient;
use TheFox\OAuth\OAuth1\Service\Flickr;

class AuthCommand extends Command{
	
	private $configPath;
	
	protected function configure(){
		$this->setName('auth');
		$this->setDescription('Retrieve the Access Token for your Flickr application.');
		
		$this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: config.yml');
		
		$this->configPath = 'config.yml';
	}
	
	protected function execute(InputInterface $input, OutputInterface $output){
		if($input->hasOption('config') && $input->getOption('config')){
			$this->configPath = $input->getOption('config');
		}
		$output->writeln('Use config: '.$this->configPath);
		
		$filesystem = new Filesystem();
		if(!$filesystem->exists($this->configPath)){
			$output->write('Consumer key: ');
			$consumerKey = trim(fgets(STDIN));
			
			$output->write('Consumer secret: ');
			$consumerSecret = trim(fgets(STDIN));
			
			$parameters = array(
				'flickr' => array(
					'consumer_key' => $consumerKey,
					'consumer_secret' => $consumerSecret,
				),
				// 'uploader' => array(
				// 	'move' => true,
				// 	'uploaded_dir' => 'uploaded',
				// ),
			);
			
			$filesystem->touch($this->configPath);
			$filesystem->chmod($this->configPath, 0600);
			file_put_contents($this->configPath, Yaml::dump($parameters));
		}
		
		$parameters = Yaml::parse($this->configPath);
		if(!isset($parameters['flickr']['token']) && !isset($parameters['flickr']['token_secret'])){
			$storage = new Memory(false);
			$credentials = new Credentials(
				$parameters['flickr']['consumer_key'],
				$parameters['flickr']['consumer_secret'],
				'oob' // Out-of-band, i.e. no callback required for a CLI application.
			);
			$flickrService = new Flickr($credentials, new GuzzleStreamClient(), $storage, new Signature($credentials));
			if($token = $flickrService->requestRequestToken()){
				$accessToken = $token->getAccessToken();
				$accessTokenSecret = $token->getAccessTokenSecret();
				
				if($accessToken && $accessTokenSecret){
					$url = $flickrService->getAuthorizationUri(array(
						'oauth_token' => $accessToken,
						'perms' => 'write',
					));
					
					$output->writeln('');
					$output->writeln("Go to this URL to authorize Flickr Uploader:\n\n\t$url\n");
					
					// Flickr says, at this point:
					// "You have successfully authorized the application XYZ to use your credentials.
					// You should now type this code into the application:"
					$output->write('Paste the 9-digit code (with or without hyphens) here: ');
					$verifier = preg_replace('/[^0-9]/', '', fgets(STDIN));

					try{
						if($token = $flickrService->requestAccessToken($token, $verifier, $accessTokenSecret)){
							$accessToken = $token->getAccessToken();
							$accessTokenSecret = $token->getAccessTokenSecret();

							$output->writeln('Save config');
							$parameters['flickr']['token'] = $accessToken;
							$parameters['flickr']['token_secret'] = $accessTokenSecret;
							file_put_contents($this->configPath, Yaml::dump($parameters));
						}
					}
					catch(Exception $e){
						$output->writeln('ERROR: '.$e->getMessage());
						return 1;
					}
				}
			}
		}

		try{
			$metadata = new Metadata($parameters['flickr']['consumer_key'], $parameters['flickr']['consumer_secret']);
			$metadata->setOauthAccess($parameters['flickr']['token'], $parameters['flickr']['token_secret']);
			
			$factory = new ApiFactory($metadata, new RezzzaGuzzleAdapter());
			
			$xml = $factory->call('flickr.test.login');
			
			$output->writeln('Status: '.(string)$xml->attributes()->stat);
		}
		catch(Exception $e){
			$output->writeln('ERROR: '.$e->getMessage());
			return 1;
		}
		
		return 0;
	}
	
}
