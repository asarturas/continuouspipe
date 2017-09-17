<?php

namespace ContinuousPipe\River\Flex\ConfigurationGeneration;

use ContinuousPipe\Flex\ConfigurationGeneration\ConfigurationGenerator;
use ContinuousPipe\Flex\ConfigurationGeneration\GenerateConfigurationWithDefaultContext;
use ContinuousPipe\Flex\ConfigurationGeneration\Sequentially\SequentiallyGenerateFiles;
use ContinuousPipe\Flex\ConfigurationGeneration\Symfony\Context\WithSymfonyContext;
use ContinuousPipe\Flex\ConfigurationGeneration\Symfony\ContinuousPipeGenerator;
use ContinuousPipe\Flex\ConfigurationGeneration\Symfony\DockerComposeGenerator;
use ContinuousPipe\Flex\ConfigurationGeneration\Symfony\DockerGenerator;
use ContinuousPipe\River\Flex\FlexConfiguration;
use ContinuousPipe\River\Flow\EncryptedVariable\EncryptedVariableVault;
use ContinuousPipe\River\Flow\Projections\FlatFlow;
use ContinuousPipe\River\Managed\Resources\DockerRegistry\ReferenceRegistryResolver;

final class GeneratorForFlow
{
    /**
     * @var EncryptedVariableVault
     */
    private $vault;
    /**
     * @var ReferenceRegistryResolver
     */
    private $referenceRegistryResolver;
    /**
     * @var array
     */
    private $defaultVariables;

    /**
     * @param EncryptedVariableVault $vault
     * @param ReferenceRegistryResolver $referenceRegistryResolver
     * @param array $defaultVariables
     */
    public function __construct(EncryptedVariableVault $vault, ReferenceRegistryResolver $referenceRegistryResolver, array $defaultVariables)
    {
        $this->vault = $vault;
        $this->defaultVariables = $defaultVariables;
        $this->referenceRegistryResolver = $referenceRegistryResolver;
    }

    /**
     * @param FlatFlow $flow
     *
     * @throws \InvalidArgumentException
     *
     * @return ConfigurationGenerator
     */
    public function get(FlatFlow $flow) : ConfigurationGenerator
    {
        return new GenerateConfigurationWithDefaultContext(
            new WithSymfonyContext(
                new SequentiallyGenerateFiles([
                    new DockerGenerator(),
                    new DockerComposeGenerator(),
                    new ContinuousPipeGenerator(
                        new EncryptedVariableDefinitionGenerator($this->vault, $flow->getUuid())
                    ),
                ])
            ),
            [
                'variables' => $this->defaultVariables,
                'image_name' => $this->getImageName($flow),
            ]
        );
    }

    private function getImageName(FlatFlow $flow) : string
    {
        if (null !== ($registry = $this->referenceRegistryResolver->getReferenceRegistry($flow->getUuid()))) {
            if (null !== ($imageName = $registry->getFullAddress())) {
                return $imageName;
            }
        }

        return 'quay.io/continuouspipe-flex/flow-'.$flow->getUuid()->toString();
    }
}
