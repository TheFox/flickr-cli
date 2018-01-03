<?php

namespace TheFox\FlickrCli\Command;

use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use OAuth\Common\Consumer\Credentials;
use OAuth\OAuth1\Signature\Signature;
use OAuth\Common\Storage\Memory;
use Rezzza\Flickr\Metadata;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;
use TheFox\OAuth\Common\Http\Client\GuzzleStreamClient;
use TheFox\OAuth\OAuth1\Service\Flickr;

final class AuthCommand extends FlickrCliCommand
{
    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @param null|string $name
     */
    public function __construct($name = null)
    {
        parent::__construct($name);

        // Set default value.
        $nullInput = new StringInput('');
        $nullOutput = new NullOutput();
        $this->io = new SymfonyStyle($nullInput, $nullOutput);
    }

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
     */
    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $this->setIsConfigFileRequired(false);

        parent::setup($input, $output);

        $this->setupIo();
    }

    private function setupIo()
    {
        $io = new SymfonyStyle($this->getInput(), $this->getOutput());
        $this->io = $io;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setup($input, $output);

        $configFilePath = $this->getConfigFilePath();

        // Get the config file, or create one.
        try {
            $config = $this->loadConfig();
        } catch (RuntimeException $exception) {
            $filesystem = new Filesystem();
            if ($filesystem->exists($configFilePath)) {
                throw $exception;
            }

            // If we couldn't get the config, ask for the basic config values and then try again.
            $this->io->writeln('Go to https://www.flickr.com/services/apps/create/apply/ to create a new API key.');
            $customerKey = $this->io->ask('Consumer key');
            $customerSecret = $this->io->ask('Consumer secret');

            $config = [
                'flickr' => [
                    'consumer_key' => $customerKey,
                    'consumer_secret' => $customerSecret,
                ],
            ];
            $this->saveConfig($config);

            // Fetch again, to make sure it's saved correctly.
            $config = $this->loadConfig();
        }

        $consumerKey=$config['flickr']['consumer_key'];
        $consumerSecret=$config['flickr']['consumer_secret'];

        $hasToken = isset($config['flickr']['token']) && isset($config['flickr']['token_secret']);
        $hasForceOpt = $this->getInput()->hasOption('force') && $this->getInput()->getOption('force');
        if (!$hasToken || $hasForceOpt) {
            $newConfig = $this->authenticate($configFilePath, $consumerKey, $consumerSecret);

            $config['flickr']['token'] = $newConfig['token'];
            $config['flickr']['token_secret'] = $newConfig['token_secret'];

            $this->io->success(sprintf('Saving config to %s', $configFilePath));
            $this->saveConfig($config);
        }

        // Now test the stored credentials.
        $metadata = new Metadata($consumerKey, $consumerSecret);
        $metadata->setOauthAccess($config['flickr']['token'], $consumerSecret);

        $factory = new ApiFactory($metadata, new RezzzaGuzzleAdapter());

        $this->io->text('Test Login');
        $xml = $factory->call('flickr.test.login');

        $attributes = $xml->attributes();
        $stat = (string)$attributes->stat;

        if (strtolower($stat) == 'ok') {
            $this->io->success('Test Login successful');
        } else {
            $this->io->text(sprintf('Status: %s', $stat));
        }

        return $this->getExit();
    }

    /**
     * Authenticate with Flickr.
     *
     * @param string $configPath The config filename.
     * @param string $customerKey
     * @param string $customerSecret
     * @return array
     */
    private function authenticate(string $configPath, string $customerKey, string $customerSecret)
    {
        $storage = new Memory();

        // Out-of-band, i.e. no callback required for a CLI application.
        $credentials = new Credentials($customerKey, $customerSecret, 'oob');

        $streamClient = new GuzzleStreamClient();
        $signature = new Signature($credentials);

        $flickrService = new Flickr($credentials, $streamClient, $storage, $signature);
        $token = $flickrService->requestRequestToken();
        if (!$token) {
            throw new RuntimeException('Request RequestToken failed.');
        }

        $accessToken = $token->getAccessToken();
        if (!$accessToken) {
            throw new RuntimeException('Cannot get Access Token.');
        }

        $accessTokenSecret = $token->getAccessTokenSecret();
        if (!$accessTokenSecret) {
            throw new RuntimeException('Cannot get Access Token Secret.');
        }

        // Ask user for permissions.
        $permissions = $this->getPermissionType();

        $additionalParameters = [
            'oauth_token' => $accessToken,
            'perms' => $permissions,
        ];
        $url = $flickrService->getAuthorizationUri($additionalParameters);

        $this->io->writeln(sprintf("Go to this URL to authorize FlickrCLI:\n\n%s", $url));

        // Flickr says, at this point:
        // "You have successfully authorized the application XYZ to use your credentials.
        // You should now type this code into the application:"
        $question = 'Paste the 9-digit code (with or without hyphens) here:';
        $verifier = $this->io->ask($question, null, function ($code) {
            $newCode = preg_replace('/[^0-9]/', '', $code);
            return $newCode;
        });

        $token = $flickrService->requestAccessToken($token, $verifier, $accessTokenSecret);
        if (!$token) {
            throw new RuntimeException('Request AccessToken failed.');
        }

        $accessToken = $token->getAccessToken();
        $accessTokenSecret = $token->getAccessTokenSecret();

        $newConfig = [
            'token' => $accessToken,
            'token_secret' => $accessTokenSecret,
        ];
        return $newConfig;
    }

    /**
     * Ask the user if they want to authenticate with read, write, or delete permissions.
     *
     * @return string The permission, one of 'read', write', or 'delete'. Defaults to 'read'.
     */
    private function getPermissionType(): string
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
        $permissions = $this->io->choice($question, $choices);
        return $permissions;
    }
}
