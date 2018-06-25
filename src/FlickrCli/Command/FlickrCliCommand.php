<?php

namespace TheFox\FlickrCli\Command;

use Psr\Log\NullLogger;
use RuntimeException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use TheFox\FlickrCli\Exception\SignalException;
use TheFox\FlickrCli\Service\ApiService;

/**
 * This is the common parent class of all Flickr CLI commands. It handles configuration, logging, and the filesystem.
 */
abstract class FlickrCliCommand extends Command
{
    /**
     * @var int
     */
    private $exit;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var string
     */
    private $configFilePath;

    /**
     * @var bool
     */
    private $isConfigFileRequired;

    /**
     * @var string[][]
     */
    private $config;

    /**
     * @var ApiService
     */
    private $apiService;

    /**
     * FlickrCliCommand constructor.
     * @param null|string $name
     */
    public function __construct($name = null)
    {
        $this->exit = 0;
        $this->logger = new NullLogger();
        $this->output = new NullOutput();
        $this->configFilePath = getcwd() . '/config.yml';
        $this->isConfigFileRequired = true;
        $this->config = [];

        // Set variables before parent constructor so that they can be used in self::configure().
        parent::__construct($name);
    }

    /**
     * @return int
     */
    public function getExit(): int
    {
        return $this->exit;
    }

    /**
     * @param int $exit
     */
    public function setExit(int $exit)
    {
        $this->exit = $exit;
    }

    public function incExit(int $inc = 1)
    {
        $this->exit += $inc;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return InputInterface
     */
    public function getInput(): InputInterface
    {
        return $this->input;
    }

    /**
     * @return OutputInterface
     */
    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * @return string
     */
    public function getConfigFilePath(): string
    {
        return $this->configFilePath;
    }

    /**
     * @return bool
     */
    public function isConfigFileRequired(): bool
    {
        return $this->isConfigFileRequired;
    }

    /**
     * @param bool $isConfigFileRequired
     */
    public function setIsConfigFileRequired(bool $isConfigFileRequired)
    {
        $this->isConfigFileRequired = $isConfigFileRequired;
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return ApiService
     */
    public function getApiService(): ApiService
    {
        return $this->apiService;
    }

    /**
     * Configure the command.
     * This adds the standard 'config' and 'log' options that are common to all Flickr CLI commands.
     */
    protected function configure()
    {
        $desc = "Path of the config file.\n"
            . "Can also be set with the FLICKRCLI_CONFIG environment variable.\n"
            . "Will default to current directory.";
        $this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, $desc, $this->configFilePath);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->signalHandlerSetup();
        $this->setupLogger();

        $this->setupConfig();

        $this->setupServices();
    }

    private function setupLogger()
    {
        $this->logger = new Logger($this->getName());

        switch ($this->output->getVerbosity()) {
            case OutputInterface::VERBOSITY_DEBUG: // -vvv
                $logLevel = Logger::DEBUG;
                break;

            case OutputInterface::VERBOSITY_VERY_VERBOSE: // -vv
                $logLevel = Logger::INFO;
                break;

            case OutputInterface::VERBOSITY_VERBOSE: // -v
                $logLevel = Logger::NOTICE;
                break;

            case OutputInterface::VERBOSITY_QUIET:
                $logLevel = Logger::ERROR;
                break;

            case OutputInterface::VERBOSITY_NORMAL:
            default:
                $logLevel = Logger::WARNING;
        }

        $handler = new StreamHandler('php://stdout', $logLevel);
        $this->logger->pushHandler($handler);
    }

    private function setupConfig()
    {
        $input = $this->getInput();

        // Get the name of the config file from the CLI, or environment,
        // or use the default (which is set in the constructor).
        $cliConfigFile = $input->getOption('config');
        $envConfigFile = getenv('FLICKRCLI_CONFIG');
        if ($cliConfigFile) {
            $this->configFilePath = $cliConfigFile;
        } elseif ($envConfigFile) {
            $this->configFilePath = $envConfigFile;
        }

        $filesystem = new Filesystem();
        if ($filesystem->exists($this->configFilePath)) {
            $this->loadConfig();
        } elseif ($this->isConfigFileRequired()) {
            throw new RuntimeException(sprintf('Config file not found: %s', $this->configFilePath));
        }
    }

    /**
     * @return array
     */
    public function loadConfig(): array
    {
        $configFilePath = $this->getConfigFilePath();
        if (!$configFilePath) {
            throw new RuntimeException('Config File Path is not set.');
        }

        $this->getLogger()->debug(sprintf('Load configuration: %s', $this->getConfigFilePath()));

        /** @var string[][] $config */
        $config = Yaml::parse(file_get_contents($configFilePath));

        if (!isset($config)
            || !isset($config['flickr'])
            || !isset($config['flickr']['consumer_key'])
            || !isset($config['flickr']['consumer_secret'])
        ) {
            throw new RuntimeException('Invalid configuration file.');
        }

        $this->config = $config;
        return $this->config;
    }

    /**
     * @param array|null $config
     */
    public function saveConfig(array $config = null)
    {
        if ($config) {
            $this->setConfig($config);
        } else {
            $config = $this->getConfig();
        }

        $configContent = Yaml::dump($config);

        $configFilePath = $this->getConfigFilePath();

        $filesystem = new Filesystem();
        $filesystem->touch($configFilePath);
        $filesystem->chmod($configFilePath, 0600);
        $filesystem->dumpFile($configFilePath, $configContent);
    }

    /**
     * @return bool
     */
    private function setupServices()
    {
        $config = $this->getConfig();
        if (!$config) {
            return false;
        }

        if (!array_key_exists('flickr', $config)) {
            return false;
        }

        if (isset($config['flickr']['consumer_key']) && $config['flickr']['consumer_key']) {
            $consumerKey = $config['flickr']['consumer_key'];
        } else {
            return false;
        }

        if (isset($config['flickr']['consumer_secret']) && $config['flickr']['consumer_secret']) {
            $consumerSecret = $config['flickr']['consumer_secret'];
        } else {
            return false;
        }

        if (isset($config['flickr']['token']) && $config['flickr']['token']) {
            $token = $config['flickr']['token'];
        } else {
            return false;
        }

        if (isset($config['flickr']['token_secret']) && $config['flickr']['token_secret']) {
            $tokenSecret = $config['flickr']['token_secret'];
        } else {
            return false;
        }

        $this->apiService = new ApiService($consumerKey, $consumerSecret, $token, $tokenSecret);
        $this->apiService->setLogger($this->logger);

        return true;
    }

    private function signalHandlerSetup()
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_dispatch')) {
            throw new SignalException('pcntl_signal function not found. You need to install pcntl PHP extention.');
        }

        //printf("signalHandlerSetup FlickrCliCommand\n");
        declare(ticks=1);

        $signalFn = $this->getSignalHandlerFunction();

        pcntl_signal(SIGTERM, $signalFn);
        pcntl_signal(SIGINT, $signalFn);
        pcntl_signal(SIGHUP, $signalFn);
    }

    public function getSignalHandlerFunction()
    {
        //printf("getSignalHandlerFunction FlickrCliCommand\n");
        /**
         * @param int $signal
         */
        $fn = function (int $signal) {
            $this->incExit();

            $msg = sprintf('Signal %d %d', $signal, $this->exit);

            //printf("\nsignal2 FlickrCliCommand %d %d\n", $signal, $this->exit);
            $this->logger->info($msg);

            if ($this->exit >= 2) {
                throw new SignalException($msg);
            }
        };

        return $fn;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setup($input, $output);

        return $this->getExit();
    }
}
