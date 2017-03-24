<?php

use Behat\Behat\Context\Context;
use ContinuousPipe\Adapter\Kubernetes\KubernetesProvider;
use ContinuousPipe\Adapter\Kubernetes\Tests\Repository\HookableNamespaceRepository;
use ContinuousPipe\Model\Environment;
use ContinuousPipe\Pipe\Tests\Adapter\Fake\FakeEnvironmentClient;
use ContinuousPipe\Pipe\Tests\Adapter\Fake\FakeProvider;
use ContinuousPipe\Pipe\Uuid\UuidTransformer;
use ContinuousPipe\Pipe\View\DeploymentRepository;
use ContinuousPipe\Security\Credentials\Bucket;
use ContinuousPipe\Security\Tests\Authenticator\InMemoryAuthenticatorClient;
use Kubernetes\Client\Exception\ServerError;
use Kubernetes\Client\Model\Status;
use SimpleBus\Message\Bus\MessageBus;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use ContinuousPipe\Pipe\EventBus\EventStore;
use Ramsey\Uuid\Uuid;

class EnvironmentContext implements Context
{
    /**
     * @var Kernel
     */
    private $kernel;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var EventStore
     */
    private $eventStore;

    /**
     * @var Uuid
     */
    private $lastDeploymentUuid;

    /**
     * @var string
     */
    private $deploymentEnvironmentName;

    /**
     * @var MessageBus
     */
    private $eventBus;

    /**
     * @var DeploymentRepository
     */
    private $deploymentRepository;

    /**
     * @var FakeEnvironmentClient
     */
    private $fakeEnvironmentClient;
    /**
     * @var InMemoryAuthenticatorClient
     */
    private $inMemoryAuthenticatorClient;

    /**
     * @var HookableNamespaceRepository
     */
    private $hookableNamespaceRepository;

    /**
     * @param Kernel $kernel
     * @param EventStore $eventStore
     * @param DeploymentRepository $deploymentRepository
     * @param MessageBus $eventBus
     * @param FakeEnvironmentClient $fakeEnvironmentClient
     * @param InMemoryAuthenticatorClient $inMemoryAuthenticatorClient
     * @param HookableNamespaceRepository $hookableNamespaceRepository
     */
    public function __construct(
        Kernel $kernel,
        EventStore $eventStore,
        DeploymentRepository $deploymentRepository,
        MessageBus $eventBus,
        FakeEnvironmentClient $fakeEnvironmentClient,
        InMemoryAuthenticatorClient $inMemoryAuthenticatorClient,
        HookableNamespaceRepository $hookableNamespaceRepository
    ) {
        $this->kernel = $kernel;
        $this->eventStore = $eventStore;
        $this->deploymentRepository = $deploymentRepository;
        $this->eventBus = $eventBus;
        $this->fakeEnvironmentClient = $fakeEnvironmentClient;
        $this->inMemoryAuthenticatorClient = $inMemoryAuthenticatorClient;
        $this->hookableNamespaceRepository = $hookableNamespaceRepository;
    }

    /**
     * @When I request the environment list of the cluster :cluster of the team :team
     */
    public function iRequestTheEnvironmentListOfTheCluster($cluster, $team)
    {
        $this->response = $this->kernel->handle(Request::create(
            sprintf('/teams/%s/clusters/%s/environments', $team, $cluster),
            'GET'
        ));
    }

    /**
     * @When I request the environment list of the cluster :cluster of the team :team that have the labels :labels
     */
    public function iRequestTheEnvironmentListOfTheClusterOfTheTeamThatHaveTheLabels($cluster, $team, $labels)
    {
        $labelsFilters = ['labels' => []];
        foreach (explode(',', $labels) as $label) {
            list($key, $value) = explode('=', $label);

            $labelsFilters['labels'][$key] = $value;
        }

        $this->response = $this->kernel->handle(Request::create(
            sprintf('/teams/%s/clusters/%s/environments', $team, $cluster).'?'.http_build_query($labelsFilters),
            'GET'
        ));
    }

    /**
     * @When I delete the environment named :environment of the cluster :cluster of the team :team
     */
    public function iDeleteTheEnvironmentNamedOfTheClusterOfTheTeam($environment, $cluster, $team)
    {
        $this->response = $this->kernel->handle(Request::create(
            sprintf('/teams/%s/clusters/%s/environments/%s', $team, $cluster, $environment),
            'DELETE'
        ));
    }

    /**
     * @Then the namespace should be deleted successfully
     * @Then the namespace :namespace should be deleted successfully
     */
    public function theNamespaceShouldBeDeletedSuccessfully($namespace = null)
    {
        if (!in_array($this->response->getStatusCode(), [200, 204])) {
            echo $this->response->getContent();

            throw new \RuntimeException(sprintf(
                'Expected the status code 200 or 204 but got %d',
                $this->response->getStatusCode()
            ));
        }
    }

    /**
     * @param string $providerName
     * @param string $environmentName
     * @param string $template
     */
    public function sendDeploymentRequest($providerName, $environmentName, $template = 'simple-app')
    {
        $bucket = new Bucket(UuidTransformer::transform(Uuid::uuid1()));
        $this->inMemoryAuthenticatorClient->addBucket($bucket);

        $simpleAppComposeContents = json_decode(file_get_contents(__DIR__.'/../fixtures/'.$template.'.json'), true);
        $contents = json_encode([
            'target' => [
                'environmentName' => $environmentName,
                'providerName' => $providerName,
            ],
            'specification' => [
                'components' => $simpleAppComposeContents,
            ],
            'notification' => [
                'httpCallbackUrl' => 'http://example.com'
            ],
            'credentialsBucket' => (string) $bucket->getUuid()
        ]);

        $this->response = $this->kernel->handle(Request::create('/deployments', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $contents));

        if (200 !== $this->response->getStatusCode()) {
            echo $this->response->getContent();

            throw new \RuntimeException(sprintf('Expected response code 200, got %d', $this->response->getStatusCode()));
        }

        $deployment = json_decode($this->response->getContent(), true);
        $this->lastDeployment = $deployment;
        $this->lastDeploymentUuid = Uuid::fromString($deployment['uuid']);
        $this->deploymentEnvironmentName = $environmentName;
    }

    /**
     * @When I send a deployment request without a given target
     */
    public function iSendADeploymentRequestWithoutAGivenTarget()
    {
        $simpleAppComponents = json_decode(file_get_contents(__DIR__.'/../fixtures/simple-app.json'), true);
        $contents = json_encode([
            'specification' => [
                'components' => $simpleAppComponents,
            ],
        ], true);
        $this->response = $this->kernel->handle(Request::create('/deployments', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $contents));
    }

    /**
     * @Then the validation should fail
     */
    public function theValidationShouldFail()
    {
        if ($this->response->getStatusCode() !== 400) {
            echo $this->response->getContent();
            throw new \RuntimeException(sprintf(
                'Expected the response to be 400, but got %d',
                $this->response->getStatusCode()
            ));
        }
    }

    /**
     * @Given I have an environment :name
     */
    public function iHaveAnEnvironment($name)
    {
        $this->fakeEnvironmentClient->add(new Environment($name, $name));
    }

    /**
     * @Then the environment :name shouldn't exists
     */
    public function theEnvironmentShouldnTExists($name)
    {
        $matchingEnvironments = array_filter($this->fakeEnvironmentClient->findAll(), function(Environment $environment) use ($name) {
            return $environment->getName() == $name;
        });

        if (count($matchingEnvironments) != 0) {
            throw new \RuntimeException(sprintf(
                'Found an environment named "%s"',
                $name
            ));
        }
    }

    /**
     * @Then I should see the component :name
     */
    public function iShouldSeeTheComponentInEnvironment($name)
    {
        $this->getComponentFromListResponse($name);
    }

    /**
     * @Then the status of the component :name should contain the public endpoint :endpoint
     */
    public function theStatusOfTheComponentShouldContainThePublicEndpoint($name, $endpoint)
    {
        $component = $this->getComponentFromListResponse($name);

        $matchingEndpoints = $this->getMatchingEndpoints($component, $endpoint);

        if (!count($matchingEndpoints)) {
            var_dump($component['status']);

            throw new \RuntimeException('Public endpoint was not found');
        }
    }

    /**
     * @Then the status of the component :name should not contain the public endpoint :endpoint
     */
    public function theStatusOfTheComponentShouldNotContainThePublicEndpoint($name, $endpoint)
    {
        $component = $this->getComponentFromListResponse($name);

        $matchingEndpoints = $this->getMatchingEndpoints($component, $endpoint);

        if (count($matchingEndpoints) !== 0) {
            var_dump($component['status']);

            throw new \RuntimeException('Public endpoint was found');
        }
    }

    /**
     * @Then the status of the component :component should contain container :containerName
     */
    public function theStatusOfTheComponentShouldContainContainer($name, $containerName)
    {
        $component = $this->getComponentFromListResponse($name);
        $matchingContainers = array_filter($component['status']['containers'], function(array $container) use ($containerName) {
            return $container['identifier'] = $containerName;
        });

        if (count($matchingContainers) == 0) {
            var_dump($component['status']['containers']);

            throw new \RuntimeException('Found no matching container');
        }
    }

    /**
     * @Then the status of the component :name should be :status
     */
    public function theStatusOfTheComponentShouldBe($name, $status)
    {
        $component = $this->getComponentFromListResponse($name);
        $foundStatus = $component['status']['status'];

        if ($foundStatus != $status) {
            throw new \RuntimeException(sprintf(
                'Found status "%s" while expecting "%s"',
                $foundStatus,
                $status
            ));
        }
    }

    /**
     * @Then I should see the environment :identifier
     */
    public function iShouldSeeTheEnvironment($identifier)
    {
        $environments = $this->getEnvironmentsFromResponse();
        $matchingEnvironments = array_filter($environments, function(array $environment) use ($identifier) {
            return $environment['identifier'] == $identifier;
        });

        if (count($matchingEnvironments) == 0) {
            throw new \RuntimeException('No matching environments found');
        }
    }

    /**
     * @Then I should not see the environment :identifier
     */
    public function iShouldNotSeeTheEnvironment($identifier)
    {
        $environments = $this->getEnvironmentsFromResponse();
        $matchingEnvironments = array_filter($environments, function(array $environment) use ($identifier) {
            return $environment['identifier'] == $identifier;
        });

        if (count($matchingEnvironments) > 0) {
            throw new \RuntimeException(sprintf('Found %d matching environments, while expecting 0', count($matchingEnvironments)));
        }
    }

    /**
     * @Given the environment API calls to the cluster failed
     */
    public function theEnvironmentAPICallsToTheClusterFailed()
    {
        $faultGenerator = function() {
            throw new ServerError(new Status(Status::UNKNOWN, 'This error is intentional.'));
        };

        $this->hookableNamespaceRepository->addFindAllHook($faultGenerator);
        $this->hookableNamespaceRepository->addFindByLabelsHook($faultGenerator);
        $this->hookableNamespaceRepository->addFindOneByNameHook($faultGenerator);
    }

    /**
     * @Then I should receive a service unavailable error
     */
    public function iShouldReceiveAServiceUnavailableError()
    {
        if (Response::HTTP_SERVICE_UNAVAILABLE != $this->response->getStatusCode()) {
            throw new \RuntimeException(
                sprintf('Unexpected to get HTTP status code %d returned.', $this->response->getStatusCode())
            );
        }
    }

    /**
     * @param string $name
     * @return array
     */
    private function getComponentFromListResponse($name)
    {
        $environments = $this->getEnvironmentsFromResponse();

        foreach ($environments as $environment) {
            $components = $environment['components'];
            $matchingComponents = array_filter($components, function ($component) use ($name) {
                return $component['name'] == $name;
            });

            if (0 < count($matchingComponents)) {
                return current($matchingComponents);
            }
        }

        throw new \RuntimeException(sprintf('No component named "%s" found in the environment', $name));
    }

    /**
     * @return array
     */
    private function getEnvironmentsFromResponse()
    {
        if ($this->response->getStatusCode() !== 200) {
            echo $this->response->getContent();

            throw new \RuntimeException(sprintf(
                'Expected response code 200, got %d',
                $this->response->getStatusCode()
            ));
        }

        $environments = json_decode($this->response->getContent(), true);
        if (!is_array($environments)) {
            throw new \RuntimeException('Expecting an array, got something else');
        }

        return $environments;
    }

    private function getMatchingEndpoints($component, $endpoint)
    {
        return array_filter(
            $component['status']['public_endpoints'],
            function ($publicEndpoint) use ($endpoint) {
                return $publicEndpoint == $endpoint;
            }
        );

    }
}
