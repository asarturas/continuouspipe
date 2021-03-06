<?php

namespace ContinuousPipe\River\Flow;

use ContinuousPipe\River\CodeRepository\FileSystem\FileException;
use ContinuousPipe\River\CodeReference;
use ContinuousPipe\River\CodeRepository\CodeRepositoryException;
use ContinuousPipe\River\CodeRepository\FileSystemResolver;
use ContinuousPipe\River\Flow\Projections\FlatFlow;
use ContinuousPipe\River\Task\TaskFactoryRegistry;
use ContinuousPipe\River\TideConfigurationException;
use ContinuousPipe\River\TideConfigurationFactory;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Yaml\Exception\ExceptionInterface as YamlException;
use Symfony\Component\Yaml\Yaml;

class ConfigurationFactory implements TideConfigurationFactory
{
    /**
     * @var FileSystemResolver
     */
    private $fileSystemResolver;

    /**
     * @var TaskFactoryRegistry
     */
    private $taskFactoryRegistry;

    /**
     * @var ConfigurationEnhancer[]
     */
    private $configurationEnhancers;

    /**
     * @var ConfigurationFinalizer[]
     */
    private $configurationFinalizers;

    /**
     * @param FileSystemResolver       $fileSystemResolver
     * @param TaskFactoryRegistry      $taskFactoryRegistry
     * @param ConfigurationEnhancer[]  $configurationEnhancers
     * @param ConfigurationFinalizer[] $configurationFinalizers
     */
    public function __construct(FileSystemResolver $fileSystemResolver, TaskFactoryRegistry $taskFactoryRegistry, array $configurationEnhancers, array $configurationFinalizers)
    {
        $this->fileSystemResolver = $fileSystemResolver;
        $this->taskFactoryRegistry = $taskFactoryRegistry;
        $this->configurationEnhancers = $configurationEnhancers;
        $this->configurationFinalizers = $configurationFinalizers;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(FlatFlow $flow, CodeReference $codeReference, bool $validated = true) : Configuration
    {
        try {
            $fileSystem = $this->fileSystemResolver->getFileSystem($flow, $codeReference);
        } catch (CodeRepositoryException $e) {
            throw new TideConfigurationException($e->getMessage(), $e->getCode(), $e);
        }

        $configs = [
            $flow->getConfiguration(),
        ];

        // Read configuration from YML
        try {
            $continuousPipeFileExists = $fileSystem->exists(self::FILENAME);
        } catch (FileException $e) {
            throw new TideConfigurationException(sprintf('Could not check the existence of the configuration file `%s`: '.$e->getMessage(), self::FILENAME), $e->getCode(), $e);
        }

        if ($continuousPipeFileExists) {
            try {
                $configs[] = Yaml::parse($fileSystem->getContents(self::FILENAME));
            } catch (FileException $e) {
                throw new TideConfigurationException(sprintf('Unable to read configuration file: %s', $e->getMessage()), $e->getCode(), $e);
            } catch (YamlException $e) {
                throw new TideConfigurationException(sprintf('YAML seems invalid: %s', $e->getMessage()), $e->getCode(), $e);
            }
        }

        // Enhance configuration
        foreach ($this->configurationEnhancers as $enhancer) {
            $configs = $enhancer->enhance($flow, $codeReference, $configs);
        }

        try {
            // Create the normalized configuration
            $configTree = (new ConfigurationDefinition($this->taskFactoryRegistry))->getConfigTreeBuilder()->buildTree();
            $configuration = $this->mergeConfigurations($configTree, $configs);

            // Enhance this configuration as much as possible
            foreach ($this->configurationFinalizers as $finalizer) {
                $configuration = $finalizer->finalize($flow, $codeReference, $configuration);
            }

            if ($validated) {
                $configuration = $configTree->finalize($configuration);
            }
        } catch (InvalidConfigurationException $e) {
            throw new TideConfigurationException($e->getMessage(), 0, $e);
        }

        return new Configuration($configuration, $continuousPipeFileExists);
    }

    /**
     * @param NodeInterface $configTree
     * @param array         $configs
     *
     * @return array
     */
    private function mergeConfigurations(NodeInterface $configTree, array $configs): array
    {
        $configuration = [];

        foreach ($configs as $config) {
            $config = $configTree->normalize($config);
            $configuration = $configTree->merge($configuration, $config);
        }

        return $configuration;
    }
}
