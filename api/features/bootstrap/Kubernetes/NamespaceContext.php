<?php

namespace Kubernetes;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use ContinuousPipe\Adapter\Kubernetes\Event\NamespaceCreated;
use ContinuousPipe\Adapter\Kubernetes\PrivateImages\SecretFactory;
use ContinuousPipe\Adapter\Kubernetes\Tests\Repository\Trace\TraceableNamespaceRepository;
use ContinuousPipe\Adapter\Kubernetes\Tests\Repository\Trace\TraceableSecretRepository;
use ContinuousPipe\Adapter\Kubernetes\Tests\Repository\Trace\TraceableServiceAccountRepository;
use ContinuousPipe\Model\Environment;
use ContinuousPipe\Pipe\View\Deployment;
use ContinuousPipe\Pipe\DeploymentContext;
use ContinuousPipe\Pipe\DeploymentRequest;
use ContinuousPipe\Pipe\Tests\MessageBus\TraceableMessageBus;
use ContinuousPipe\User\User;
use Kubernetes\Client\Exception\NamespaceNotFound;
use Kubernetes\Client\Model\KubernetesNamespace;
use Kubernetes\Client\Model\LocalObjectReference;
use Kubernetes\Client\Model\ObjectMetadata;
use Kubernetes\Client\Model\Secret;
use Kubernetes\Client\Model\ServiceAccount;
use LogStream\LoggerFactory;
use Symfony\Component\HttpFoundation\Request;

class NamespaceContext implements Context, SnippetAcceptingContext
{
    /**
     * @var \EnvironmentContext
     */
    private $environmentContext;

    /**
     * @var \Kubernetes\ProviderContext
     */
    private $providerContext;

    /**
     * @var TraceableNamespaceRepository
     */
    private $namespaceRepository;

    /**
     * @var TraceableMessageBus
     */
    private $eventBus;

    /**
     * @var TraceableSecretRepository
     */
    private $secretRepository;

    /**
     * @var TraceableServiceAccountRepository
     */
    private $serviceAccountRepository;
    /**
     * @var LoggerFactory
     */
    private $loggerFactory;

    /**
     * @param TraceableNamespaceRepository      $namespaceRepository
     * @param TraceableMessageBus               $eventBus
     * @param TraceableSecretRepository         $secretRepository
     * @param TraceableServiceAccountRepository $serviceAccountRepository
     * @param LoggerFactory                     $loggerFactory
     */
    public function __construct(TraceableNamespaceRepository $namespaceRepository, TraceableMessageBus $eventBus, TraceableSecretRepository $secretRepository, TraceableServiceAccountRepository $serviceAccountRepository, LoggerFactory $loggerFactory)
    {
        $this->namespaceRepository = $namespaceRepository;
        $this->eventBus = $eventBus;
        $this->secretRepository = $secretRepository;
        $this->serviceAccountRepository = $serviceAccountRepository;
        $this->loggerFactory = $loggerFactory;
    }

    /**
     * @BeforeScenario
     */
    public function gatherContexts(BeforeScenarioScope $scope)
    {
        $this->environmentContext = $scope->getEnvironment()->getContext('EnvironmentContext');
        $this->providerContext = $scope->getEnvironment()->getContext('Kubernetes\ProviderContext');
    }

    /**
     * @When I send a deployment request for a non-existing environment
     */
    public function iSendADeploymentRequestForANonExistingEnvironment()
    {
        $this->environmentContext->sendDeploymentRequest('kubernetes/'.ProviderContext::DEFAULT_PROVIDER_NAME, 'non-existing');
    }

    /**
     * @When I send a deployment request from application template :template
     */
    public function iSendADeploymentRequestFromApplicationTemplate($template)
    {
        $this->iHaveANamespace('existing');
        $this->environmentContext->sendDeploymentRequest('kubernetes/'.ProviderContext::DEFAULT_PROVIDER_NAME, 'existing', $template);
    }

    /**
     * @Then it should create a new namespace
     */
    public function itShouldCreateANewNamespace()
    {
        $numberOfCreatedNamespaces = count($this->namespaceRepository->getCreatedRepositories());

        if ($numberOfCreatedNamespaces == 0) {
            throw new \RuntimeException('No namespace were created');
        }
    }

    /**
     * @Given I have a namespace :name
     */
    public function iHaveANamespace($name)
    {
        try {
            $namespace = $this->namespaceRepository->findOneByName($name);
        } catch (NamespaceNotFound $e) {
            $namespace = $this->namespaceRepository->create(new KubernetesNamespace(new ObjectMetadata($name)));
            $this->namespaceRepository->clear();
        }

        return $namespace;
    }

    /**
     * @When I send a deployment request for the environment :environmentName
     */
    public function iSendADeploymentRequestForTheEnvironment($environmentName)
    {
        $this->environmentContext->sendDeploymentRequest('kubernetes/'.ProviderContext::DEFAULT_PROVIDER_NAME, $environmentName);
    }

    /**
     * @Then it should reuse this namespace
     */
    public function itShouldReuseThisNamespace()
    {
        $numberOfCreatedNamespaces = count($this->namespaceRepository->getCreatedRepositories());

        if ($numberOfCreatedNamespaces !== 0) {
            throw new \RuntimeException(sprintf(
                'Expected 0 namespace to be created, got %d',
                $numberOfCreatedNamespaces
            ));
        }
    }

    /**
     * @Then it should dispatch the namespace created event
     */
    public function itShouldDispatchTheNamespaceCreatedEvent()
    {
        $namespaceCreatedEvents = array_filter($this->eventBus->getMessages(), function ($message) {
            return $message instanceof NamespaceCreated;
        });

        if (count($namespaceCreatedEvents) == 0) {
            throw new \RuntimeException('Expected to found a namespace created event, found 0');
        }
    }

    /**
     * @When a namespace is created
     */
    public function aNamespaceIsCreated()
    {
        $this->eventBus->handle(new NamespaceCreated(
            new KubernetesNamespace(new ObjectMetadata('foo')),
            new DeploymentContext(
                Deployment::fromRequest(
                    new DeploymentRequest(
                        new DeploymentRequest\Target(),
                        new DeploymentRequest\Specification()
                    ),
                    new User('samuel')
                ),
                $this->providerContext->iHaveAValidKubernetesProvider(),
                $this->loggerFactory->create()->getLog(),
                new Environment('foo', 'bar')
            )
        ));
    }

    /**
     * @Then a docker registry secret should be created
     */
    public function aDockerRegistrySecretShouldBeCreated()
    {
        $matchingCreated = array_filter($this->secretRepository->getCreated(), function (Secret $secret) {
            return $this->isPrivateSecretName($secret->getMetadata()->getName());
        });

        if (count($matchingCreated) == 0) {
            throw new \RuntimeException('No docker registry secret found');
        }
    }

    /**
     * @Then the service account should be updated with a docker registry pull secret
     */
    public function theServiceAccountShouldBeUpdatedWithADockerRegistryPullSecret()
    {
        $matchingServiceAccounts = array_filter($this->serviceAccountRepository->getUpdated(), function (ServiceAccount $serviceAccount) {
            $matchingImagePulls = array_filter($serviceAccount->getImagePullSecrets(), function (LocalObjectReference $objectReference) {
                return $this->isPrivateSecretName($objectReference->getName());
            });

            return count($matchingImagePulls) > 0;
        });

        if (count($matchingServiceAccounts) == 0) {
            throw new \RuntimeException('No updated service account with docker registry pull secret found');
        }
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    private function isPrivateSecretName($name)
    {
        return substr($name, 0, strlen(SecretFactory::SECRET_PREFIX)) == SecretFactory::SECRET_PREFIX;
    }

    /**
     * @Then the secret :name should be created
     */
    public function theSecretShouldBeCreated($name)
    {
    }

    /**
     * @Then the service account should be updated with a pull secret :name
     */
    public function theServiceAccountShouldBeUpdatedWithAPullSecret($name)
    {
    }
}
