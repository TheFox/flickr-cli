<?php

namespace TheFox\FlickrCli\Command;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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

class AuthCommand extends Command
{
    /**
     * @var string The name of the configuration file. Defaults to 'config.yml'.
     */
    private $configPath;

    protected function configure()
    {
        $this->setName('auth');
        $this->setDescription('Retrieve the Access Token for your Flickr application.');

        $this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: config.yml');

        $msg = 'Request authorisation even if the Access Token has already been stored.';
        $this->addOption('force', 'f', InputOption::VALUE_NONE, $msg);

        $this->configPath = 'config.yml';
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->hasOption('config') && $input->getOption('config')) {
            $this->configPath = $input->getOption('config');
        }
        $io = new SymfonyStyle($input, $output);
        $io->text('Using config: ' . $this->configPath);

        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->configPath)) {
            $parameters = [
                'flickr' => [
                    'consumer_key' => $io->ask('Consumer key:'),
                    'consumer_secret' => $io->ask('Consumer secret:'),
                ],
            ];

            $filesystem->touch($this->configPath);
            $filesystem->chmod($this->configPath, 0600);
            file_put_contents($this->configPath, Yaml::dump($parameters));
        }

        $parameters = Yaml::parse($this->configPath);
        $hasToken = isset($parameters['flickr']['token']) && isset($parameters['flickr']['token_secret']);
        if (!$hasToken || $input->getOption('force')) {
            $storage = new Memory();
            $credentials = new Credentials(
                $parameters['flickr']['consumer_key'],
                $parameters['flickr']['consumer_secret'],
                'oob' // Out-of-band, i.e. no callback required for a CLI application.
            );
            $flickrService = new Flickr($credentials, new GuzzleStreamClient(), $storage, new Signature($credentials));
            if ($token = $flickrService->requestRequestToken()) {
                $accessToken = $token->getAccessToken();
                $accessTokenSecret = $token->getAccessTokenSecret();

                if ($accessToken && $accessTokenSecret) {
                    $url = $flickrService->getAuthorizationUri([
                        'oauth_token' => $accessToken,
                        'perms' => $this->getPermissionType($io),
                    ]);

                    $io->text("Go to this URL to authorize FlickrCLI:\n\n\t" . $url);

                    // Flickr says, at this point:
                    // "You have successfully authorized the application XYZ to use your credentials.
                    // You should now type this code into the application:"
                    $question = 'Paste the 9-digit code (with or without hyphens) here:';
                    $verifier = $io->ask($question, null, function ($code) {
                        return preg_replace('/[^0-9]/', '', $code);
                    });

                    try {
                        if ($token = $flickrService->requestAccessToken($token, $verifier, $accessTokenSecret)) {
                            $accessToken = $token->getAccessToken();
                            $accessTokenSecret = $token->getAccessTokenSecret();

                            $io->success('Saving config to ' . $this->configPath);
                            $parameters['flickr']['token'] = $accessToken;
                            $parameters['flickr']['token_secret'] = $accessTokenSecret;
                            file_put_contents($this->configPath, Yaml::dump($parameters));
                        }
                    } catch (Exception $e) {
                        $io->error($e->getMessage());
                        return 1;
                    }
                }
            }
        }

        try {
            $metadata = new Metadata($parameters['flickr']['consumer_key'], $parameters['flickr']['consumer_secret']);
            $metadata->setOauthAccess($parameters['flickr']['token'], $parameters['flickr']['token_secret']);

            $factory = new ApiFactory($metadata, new RezzzaGuzzleAdapter());

            $xml = $factory->call('flickr.test.login');
            $io->text('Status: ' . (string)$xml->attributes()->stat);
        } catch (Exception $e) {
            $io->error($e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Ask the user if they want to authenticate with read, write, or delete permissions.
     * @param SymfonyStyle $io The IO object.
     * @return string The permission, one of 'read', write', or 'delete'. Defaults to 'read'.
     */
    protected function getPermissionType(SymfonyStyle $io)
    {
        $question = 'The permission you grant to this application depends on what you want to do with it.';
        $question .= 'Please select from the following three options:';
        $choices = [
            'read' => 'download photos',
            'write' => 'upload upload photos',
            'delete' => 'download and delete photos from Flickr',
        ];
        // Note that we're not currently setting a default here, because it is not yet possible
        // to set a non-numeric key as the default. https://github.com/symfony/symfony/issues/15032
        return $io->choice($question, $choices);
    }
}
