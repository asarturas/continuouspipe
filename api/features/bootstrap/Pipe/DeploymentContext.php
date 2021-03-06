<?php

namespace Pipe;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use ContinuousPipe\Model\Environment;
use ContinuousPipe\Pipe\Client\Client;
use ContinuousPipe\Pipe\Client\PipeClientException;
use ContinuousPipe\Pipe\DeploymentRequest;
use ContinuousPipe\Pipe\Environment\PublicEndpoint;
use ContinuousPipe\Pipe\Environment\PublicEndpointPort;
use ContinuousPipe\Pipe\EventBus\EventStore;
use ContinuousPipe\Pipe\Event\DeploymentEvent;
use ContinuousPipe\Pipe\Event\DeploymentFailed;
use ContinuousPipe\Pipe\Event\DeploymentStarted;
use ContinuousPipe\Pipe\Event\DeploymentSuccessful;
use ContinuousPipe\Pipe\Tests\Cluster\TestCluster;
use ContinuousPipe\Pipe\Uuid\UuidTransformer;
use ContinuousPipe\Pipe\View\Deployment;
use ContinuousPipe\Pipe\View\DeploymentRepository;
use ContinuousPipe\Security\User\User;
use GuzzleHttp\HandlerStack;
use JMS\Serializer\SerializerInterface;
use Ramsey\Uuid\Uuid;
use SimpleBus\Message\Bus\MessageBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;

class DeploymentContext implements Context
{
    /**
     * @var Uuid|null
     */
    public static $deploymentUuid;

    /**
     * @var EventStore
     */
    private $eventStore;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var array
     */
    private $deploymentRequest;
    /**
     * @var MessageBus
     */
    private $eventBus;

    /**
     * @var DeploymentRepository
     */
    private $deploymentRepository;
    /**
     * @var SerializerInterface
     */
    private $serializer;
    /**
     * @var Client
     */
    private $pipeClient;

    public function __construct(
        DeploymentRepository $deploymentRepository,
        EventStore $eventStore,
        MessageBus $eventBus,
        SerializerInterface $serializer,
        Client $pipeClient
    ) {
        $this->deploymentRepository = $deploymentRepository;
        $this->eventStore = $eventStore;
        $this->eventBus = $eventBus;
        $this->serializer = $serializer;
        $this->pipeClient = $pipeClient;
    }

    /**
     * @Given I am building a deployment request
     */
    public function iAmBuildingADeploymentRequest()
    {
        $this->deploymentRequest = [];
    }

    /**
     * @Given the target environment name is :name
     */
    public function theTargetEnvironmentNameIs($name)
    {
        if (!array_key_exists('target', $this->deploymentRequest)) {
            $this->deploymentRequest['target'] = [];
        }

        $this->deploymentRequest['target']['environmentName'] = $name;
    }

    /**
     * @Given the target cluster identifier is :name
     */
    public function theTargetClusterIdentifierIs($name)
    {
        if (!array_key_exists('target', $this->deploymentRequest)) {
            $this->deploymentRequest['target'] = [];
        }

        $this->deploymentRequest['target']['clusterIdentifier'] = $name;
    }

    /**
     * @Given the environment label :key contains :value
     */
    public function theEnvironmentLabelContains($key, $value)
    {
        if (!array_key_exists('environmentLabels', $this->deploymentRequest['target'])) {
            $this->deploymentRequest['target']['environmentLabels'] = [];
        }

        $this->deploymentRequest['target']['environmentLabels'][$key] = $value;
    }

    /**
     * @Given the specification come from the template :template
     */
    public function theSpecificationComeFromTheTemplate($template)
    {
        $this->deploymentRequest['specification'] = [
            'components' => \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../../pipe/fixtures/'.$template.'.json'), true),
        ];
    }

    /**
     * @Given the components specification are:
     */
    public function theComponentsSpecificationAre(PyStringNode $string)
    {
        $this->deploymentRequest['specification'] = [
            'components' => \GuzzleHttp\json_decode($string->getRaw(), true),
        ];
    }

    /**
     * @Given the credentials bucket is :uuid
     */
    public function theCredentialsBucketIs($uuid)
    {
        $this->deploymentRequest['credentialsBucket'] = $uuid;
    }

    /**
     * @Given the notification callback address is :address
     */
    public function theNotificationCallbackAddressIs($address)
    {
        if (!array_key_exists('notification', $this->deploymentRequest)) {
            $this->deploymentRequest['notification'] = [];
        }

        $this->deploymentRequest['notification']['httpCallbackUrl'] = $address;
    }



    /**
     * @Given I have a running deployment
     */
    public function iHaveARunningDeployment()
    {
        $deployment = $this->deploymentRepository->save(
            Deployment::fromRequest(
                new DeploymentRequest(
                    new DeploymentRequest\Target('', ''),
                    new DeploymentRequest\Specification([]),
                    Uuid::uuid1(),
                    new DeploymentRequest\Notification(
                        'http://foo/bar'
                    )
                ),
                new User('sroze@inviqa.com', UuidTransformer::transform(Uuid::uuid1()))
            )
        );

        $this->eventStore->add(new DeploymentStarted(
            new \ContinuousPipe\Pipe\DeploymentContext(
                $deployment,
                new TestCluster('foo'),
                null,
                new Environment('', '')
            )
        ));

        self::$deploymentUuid = $deployment->getUuid();
    }

    /**
     * @When I send a deployment request with the following components specification:
     */
    public function iSendADeploymentRequestWithTheFollowingComponentsSpecification(PyStringNode $string)
    {
        $this->deploymentRequest['specification'] = [
            'components' => \GuzzleHttp\json_decode($string->getRaw(), true),
        ];

        $this->iSendTheBuiltDeploymentRequest();
    }

    /**
     * @When I send the built deployment request
     */
    public function iSendTheBuiltDeploymentRequest()
    {
        $request = $this->serializer->deserialize(\GuzzleHttp\json_encode($this->deploymentRequest), DeploymentRequest::class, 'json');

        try {
            $deployment = $this->pipeClient->start($request, new User('username', Uuid::uuid4()));
            $this->response = Response::create($this->serializer->serialize($deployment, 'json'));
        } catch (PipeClientException $e) {
            $this->response = Response::create(json_encode([
                'message' => $e->getMessage(),
            ]), 400);
        }

        if (in_array($this->response->getStatusCode(), [200, 201])) {
            $json = json_decode($this->response->getContent(), true);

            self::$deploymentUuid = Uuid::fromString($json['uuid']);
        } else {
            echo $this->response->getContent();
        }
    }

    /**
     * @When the deployment is successful
     */
    public function theDeploymentIsSuccessful()
    {
        $this->eventBus->handle(new DeploymentSuccessful(self::$deploymentUuid));
    }

    /**
     * @When the deployment is failed
     */
    public function theDeploymentIsFailed()
    {
        $lastEvents = $this->eventStore->findByDeploymentUuid(self::$deploymentUuid);
        $deploymentStartedEvents = array_filter($lastEvents, function(DeploymentEvent $event) {
            return $event instanceof DeploymentStarted;
        });

        if (0 === count($deploymentStartedEvents)) {
            throw new \RuntimeException('Deployment not even started');
        }

        /** @var DeploymentStarted $deploymentStartedEvent */
        $deploymentStartedEvent = $deploymentStartedEvents[0];
        $this->eventBus->handle(new DeploymentFailed($deploymentStartedEvent->getDeploymentContext()));
    }

    /**
     * @Then the deployment request should be invalid
     */
    public function theDeploymentRequestShouldBeInvalid()
    {
        $this->assertResponseCodeIs(400);
    }

    /**
     * @Then the deployment request should be successfully created
     */
    public function theDeploymentRequestShouldBeSuccessfullyCreated()
    {
        $this->assertResponseCodeIs([200, 201]);
    }

    /**
     * @Then the deployment should be successful
     */
    public function theDeploymentShouldBeSuccessful()
    {
        $events = $this->eventStore->findByDeploymentUuid(self::$deploymentUuid);

        $deploymentSuccessfulEvents = array_filter($events, function ($event) {
            return $event instanceof DeploymentSuccessful;
        });

        if (count($deploymentSuccessfulEvents) == 0) {
            throw new \RuntimeException('No event successful deployment found');
        }
    }

    /**
     * @Then the deployment should contain the endpoint :endpoint
     *
     * @return PublicEndpoint
     */
    public function theDeploymentShouldContainTheEndpoint($endpoint)
    {
        $deployment = $this->deploymentRepository->find(self::$deploymentUuid);
        $found = [];
        $matchingEndpoints = array_filter($deployment->getPublicEndpoints(), function(PublicEndpoint $publicEndpoint) use ($endpoint, &$found) {
            $found[] = $publicEndpoint->getAddress();

            return $publicEndpoint->getAddress() == $endpoint;
        });

        if (0 == count($matchingEndpoints)) {
            throw new \RuntimeException(sprintf(
                'Endpoint "%s" not found (found %s)',
                $endpoint,
                implode(',', $found)
            ));
        }

        return current($matchingEndpoints);
    }

    /**
     * @Then the deployment endpoint :endpoint should have the port :port
     */
    public function theDeploymentEndpointShouldHaveThePort($endpoint, $port)
    {
        $endpoint = $this->theDeploymentShouldContainTheEndpoint($endpoint);

        $ports = array_map(function(PublicEndpointPort $port) {
            return $port->getNumber();
        }, $endpoint->getPorts());

        if (!in_array($port, $ports)) {
            throw new \RuntimeException(sprintf('Port not found (found %s)', implode(',', $ports)));
        }
    }

    /**
     * @Then the deployment should be failed
     */
    public function theDeploymentShouldBeFailed()
    {
        $events = $this->eventStore->findByDeploymentUuid(self::$deploymentUuid);

        $deploymentFailedEvents = array_filter($events, function ($event) {
            return $event instanceof DeploymentFailed;
        });

        if (count($deploymentFailedEvents) == 0) {
            throw new \RuntimeException('No event failed deployment found');
        }
    }

    /**
     * @param int|array $statusCode
     */
    private function assertResponseCodeIs($statusCode)
    {
        if (!is_array($statusCode)) {
            $statusCode = [$statusCode];
        }

        if (!in_array($this->response->getStatusCode(), $statusCode)) {
            echo $this->response->getContent();

            throw new \RuntimeException(sprintf(
                'Expected statuses %d but got %d',
                implode(',', $statusCode),
                $this->response->getStatusCode()
            ));
        }
    }
}
