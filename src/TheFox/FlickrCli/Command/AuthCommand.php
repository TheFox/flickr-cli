<?php

namespace TheFox\FlickrCli\Command;

use Exception;
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

class AuthCommand extends FlickrCliCommand
{
    /** @var SymfonyStyle */
    protected $io;

    protected function configure()
    {
        parent::configure();

        $this->setName('auth');
        $this->setDescription('Retrieve the Access Token for your Flickr application.');

        $msg = 'Request authorisation even if the Access Token has already been stored.';
        $this->addOption('force', 'f', InputOption::VALUE_NONE, $msg);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $configPath = $this->getConfigFilepath($input, false);

        // Get the config file, or create one.
        try {
            $parameters = $this->getConfig($input);
        } catch (Exception $exception) {
            // If we couldn't get the config, ask for the basic config values and then try again.
            $filesystem = new Filesystem();
            if (!$filesystem->exists($configPath)) {
                $this->io->writeln("Go to https://www.flickr.com/services/apps/create/apply/ to create a new API key.");
                $parameters = [
                    'flickr' => [
                        'consumer_key' => $this->io->ask('Consumer key'),
                        'consumer_secret' => $this->io->ask('Consumer secret'),
                    ],
                ];
                $filesystem->touch($configPath);
                $filesystem->chmod($configPath, 0600);
                file_put_contents($configPath, Yaml::dump($parameters));
            }
            // Fetch again, to make sure it's saved correctly.
            $parameters = $this->getConfig($input);
        }

        $hasToken = isset($parameters['flickr']['token']) && isset($parameters['flickr']['token_secret']);
        if (!$hasToken || $input->getOption('force')) {
            try {
                $this->authenticate($configPath, $parameters);
            } catch (Exception $e) {
                $this->io->error($e->getMessage());
                return 1;
            }
        }

        // Now test the stored credentials.
        try {
            $parameters = $this->getConfig($input);
            $metadata = new Metadata($parameters['flickr']['consumer_key'], $parameters['flickr']['consumer_secret']);
            $metadata->setOauthAccess($parameters['flickr']['token'], $parameters['flickr']['token_secret']);

            $factory = new ApiFactory($metadata, new RezzzaGuzzleAdapter());

            $xml = $factory->call('flickr.test.login');
            $this->io->text('Status: ' . (string)$xml->attributes()->stat);
        } catch (Exception $e) {
            $this->io->error($e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Authenticate with Flickr.
     * @param string $configPath The config filename.
     * @param string[][] $parameters The config parameters; must contain the key and secret.
     */
    protected function authenticate($configPath, $parameters)
    {
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
                    'perms' => $this->getPermissionType(),
                ]);

                $this->io->writeln("Go to this URL to authorize FlickrCLI:\n\n" . $url);

                // Flickr says, at this point:
                // "You have successfully authorized the application XYZ to use your credentials.
                // You should now type this code into the application:"
                $question = 'Paste the 9-digit code (with or without hyphens) here:';
                $verifier = $this->io->ask($question, null, function ($code) {
                    return preg_replace('/[^0-9]/', '', $code);
                });

                if ($token = $flickrService->requestAccessToken($token, $verifier, $accessTokenSecret)) {
                    $accessToken = $token->getAccessToken();
                    $accessTokenSecret = $token->getAccessTokenSecret();

                    $this->io->success('Saving config to ' . $configPath);
                    $parameters['flickr']['token'] = $accessToken;
                    $parameters['flickr']['token_secret'] = $accessTokenSecret;
                    file_put_contents($configPath, Yaml::dump($parameters));
                }
            }
        }
    }

    /**
     * Ask the user if they want to authenticate with read, write, or delete permissions.
     * @return string The permission, one of 'read', write', or 'delete'. Defaults to 'read'.
     */
    protected function getPermissionType(): string
    {
        $this->io->writeln('The permission you grant to FlickrCLI depends on what you want to do with it.');
        $question = 'Please select from the following three options';
        $choices = [
            'read' => 'download photos',
            'write' => 'upload photos',
            'delete' => 'download and/or delete photos from Flickr',
        ];
        // Note that we're not currently setting a default here, because it is not yet possible
        // to set a non-numeric key as the default. https://github.com/symfony/symfony/issues/15032
        return $this->io->choice($question, $choices);
    }
}
