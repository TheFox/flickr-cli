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
        parent::__construct($name);

        $this->exit = 0;
        $this->logger = new NullLogger();
        $this->output = new NullOutput();
        $this->configFilePath = 'config.yml';
        $this->isConfigFileRequired = true;
        $this->config = [];
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
        $this
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: ./config.yml')//->addOption('log', 'l', InputOption::VALUE_OPTIONAL, 'Path to log directory. Default: ./log')
        ;
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

        //$logFormatter = new LineFormatter("[%datetime%] %level_name%: %message%\n");

        $handler = new StreamHandler('php://stdout', $logLevel);
        //$handler->setFormatter($logFormatter);
        $this->logger->pushHandler($handler);
    }

    private function setupConfig()
    {
        $input = $this->getInput();

        if ($input->hasOption('config') && $input->getOption('config')) {
            $configFilePath = $input->getOption('config');
        } elseif ($envConfigFile = getenv('FLICKRCLI_CONFIG')) {
            $configFilePath = $envConfigFile;
        }

        if (!isset($configFilePath) || !$configFilePath) {
            throw new RuntimeException('No config file path found.');
        }
        $this->configFilePath = $configFilePath;

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
        $config = Yaml::parse($configFilePath);

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

        $consumerKey = $config['flickr']['consumer_key'];
        if (!$consumerKey) {
            return false;
        }

        $consumerSecret = $config['flickr']['consumer_secret'];
        if (!$consumerSecret) {
            return false;
        }

        $token = $config['flickr']['token'];
        if (!$token) {
            return false;
        }

        $tokenSecret = $config['flickr']['token_secret'];
        if (!$tokenSecret) {
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

        declare(ticks=1);

        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        /** @uses $this::signalHandler() */
        pcntl_signal(SIGINT, [$this, 'signalHandler']);
        /** @uses $this::signalHandler() */
        pcntl_signal(SIGHUP, [$this, 'signalHandler']);
        /** @uses $this::signalHandler() */
    }

    /**
     * @param int $signal
     */
    private function signalHandler(int $signal)
    {
        $this->exit++;

        if ($this->exit >= 2) {
            throw new SignalException(sprintf('Signal %d', $signal));
        }
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
