<?php

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use ContinuousPipe\Model\Environment;
use ContinuousPipe\River\Tests\Pipe\FakeClient;
use ContinuousPipe\Security\Team\Team;
use ContinuousPipe\Security\Tests\Authenticator\InMemoryAuthenticatorClient;
use ContinuousPipe\Security\User\SecurityUser;
use ContinuousPipe\Security\User\User;
use Rhumsaa\Uuid\Uuid;
use ContinuousPipe\River\Repository\FlowRepository;
use ContinuousPipe\River\FlowContext as RiverFlowContext;
use ContinuousPipe\River\CodeRepository;
use ContinuousPipe\River\Flow;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authentication\Token\JWTUserToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use ContinuousPipe\River\Tests\CodeRepository\InMemoryCodeRepositoryRepository;
use GitHub\WebHook\Model\Repository;
use Symfony\Component\Yaml\Yaml;

class FlowContext implements Context, \Behat\Behat\Context\SnippetAcceptingContext
{
    /**
     * @var string
     */
    private $flowUuid;

    /**
     * @var Flow
     */
    private $currentFlow;

    /**
     * @var FlowRepository
     */
    private $flowRepository;

    /**
     * @var Kernel
     */
    private $kernel;

    /**
     * @var InMemoryCodeRepositoryRepository
     */
    private $codeRepositoryRepository;

    /**
     * @var InMemoryAuthenticatorClient
     */
    private $authenticatorClient;

    /**
     * @var FakeClient
     */
    private $pipeClient;

    /**
     * @var Response|null
     */
    private $response;

    /**
     * @var string|null
     */
    private $lastConfiguration;

    /**
     * @param Kernel $kernel
     * @param TokenStorageInterface $tokenStorage
     * @param FlowRepository $flowRepository
     * @param InMemoryCodeRepositoryRepository $codeRepositoryRepository
     * @param InMemoryAuthenticatorClient $authenticatorClient
     * @param FakeClient $pipeClient
     */
    public function __construct(Kernel $kernel, FlowRepository $flowRepository, InMemoryCodeRepositoryRepository $codeRepositoryRepository, InMemoryAuthenticatorClient $authenticatorClient, FakeClient $pipeClient)
    {
        $this->flowRepository = $flowRepository;
        $this->kernel = $kernel;
        $this->codeRepositoryRepository = $codeRepositoryRepository;
        $this->authenticatorClient = $authenticatorClient;
        $this->pipeClient = $pipeClient;
    }

    /**
     * @return Uuid
     */
    public function getCurrentUuid()
    {
        return $this->flowUuid;
    }

    /**
     * @Then the flow UUID should be :uuid
     */
    public function theFlowUuidShouldBe($uuid)
    {
        if ($this->flowUuid != $uuid) {
            throw new \RuntimeException(sprintf(
                'Found UUID %s but expected %s',
                $this->flowUuid,
                $uuid
            ));
        }
    }

    /**
     * @Given the GitHub repository :id exists
     */
    public function theGitHubRepositoryExists($id)
    {
        $this->codeRepositoryRepository->add(new CodeRepository\GitHub\GitHubCodeRepository(
            new Repository('foo', 'bar', false, $id)
        ));
    }

    /**
     * @When I send a flow creation request with the following parameters:
     */
    public function iSendAFlowCreationRequestWithTheFollowingParameters(TableNode $parameters)
    {
        $creationRequest = json_encode($parameters->getHash()[0]);

        $this->response = $this->kernel->handle(Request::create('/flows', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $creationRequest));

        $flowView = json_decode($this->response->getContent(), true);
        if (array_key_exists('uuid', $flowView)) {
            $this->flowUuid = $flowView['uuid'];
        }
    }

    /**
     * @When I send an update request with a configuration
     */
    public function iSendAnUpdateRequestWithAValidConfiguration()
    {
        $this->lastConfiguration = <<<EOF
tasks:
    - build: ~
    - deploy:
        providerName: foo
EOF;

        $url = sprintf('/flows/%s', $this->flowUuid);
        $this->response = $this->kernel->handle(Request::create($url, 'PUT', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'yml_configuration' => $this->lastConfiguration
        ])));
    }


    /**
     * @When I retrieve the list of the flows
     */
    public function iRetrieveTheListOfTheFlows()
    {
        $this->response = $this->kernel->handle(Request::create('/flows', 'GET'));
    }

    /**
     * @Then I should see the flow :uuid
     */
    public function iShouldSeeTheFlow($uuid)
    {
        $this->assertResponseCode(200);

        $flows = json_decode($this->response->getContent(), true);
        if (!is_array($flows)) {
            throw new \RuntimeException('Expected to receive an array');
        }

        $matchingFlows = array_filter($flows, function(array $flow) use ($uuid) {
            return $flow['uuid'] = $uuid;
        });

        if (0 == count($matchingFlows)) {
            throw new \RuntimeException('No matching flow found');
        }
    }

    /**
     * @Then I should see the flow's last tide
     */
    public function iShouldSeeTheFlowSLastTide()
    {
        $this->assertResponseCode(200);

        $flows = json_decode($this->response->getContent(), true);
        if (!is_array($flows)) {
            throw new \RuntimeException('Expected to receive an array');
        }

        $matchingFlows = array_filter($flows, function(array $flow) {
            return isset($flow['tides']) && !empty($flow['tides']);
        });

        if (0 == count($matchingFlows)) {
            throw new \RuntimeException('No matching flow found');
        }
    }

    /**
     * @Then the flow is not saved
     * @Then I should be told that my flow creation request is invalid
     */
    public function theFlowIsNotSaved()
    {
        $this->assertResponseCode(400);
    }

    /**
     * @Then the flow is successfully saved
     */
    public function theFlowIsSuccessfullySaved()
    {
        $this->assertResponseCode(200);
    }

    /**
     * @Then the stored configuration is not empty
     */
    public function theStoredConfigurationIsNotEmpty()
    {
        $flow = $this->flowRepository->find(Uuid::fromString($this->flowUuid));
        $configuration = $flow->getContext()->getConfiguration();

        if (empty($configuration)) {
            throw new \RuntimeException('Found empty configuration while expecting it to be saved');
        }
    }

    /**
     * @Given I have a flow
     */
    public function iHaveAFlow()
    {
        if (null === $this->currentFlow) {
            $this->createFlow();
        }
    }

    /**
     * @Given I have a flow with UUID :uuid
     */
    public function iHaveAFlowWithUuid($uuid)
    {
        if (null === $this->currentFlow) {
            $this->createFlow(Uuid::fromString($uuid));
        }
    }

    /**
     * @Given I have a flow with the following configuration:
     */
    public function iHaveAFlowWithTheFollowingConfiguration(PyStringNode $string)
    {
        if (null === $this->currentFlow) {
            $this->createFlow(null, Yaml::parse($string->getRaw()));
        }
    }

    /**
     * @Given I have the a deployed environment named :name
     */
    public function iHaveTheADeployedEnvironmentNamed($name)
    {
        $this->pipeClient->addEnvironment(new Environment($name, $name));
    }

    /**
     * @When I request the list of deployed environments
     */
    public function iRequestTheListOfDeployedEnvironments()
    {
        $url = sprintf('/flows/%s/environments', (string) $this->flowUuid);
        $this->response = $this->kernel->handle(Request::create($url, 'GET'));

        $this->assertResponseCode(200);
    }

    /**
     * @Then I should see the environment :name
     */
    public function iShouldSeeTheEnvironment($name)
    {
        $environments = json_decode($this->response->getContent(), true);
        $matchingEnvironments = array_filter($environments, function(array $environment) use ($name) {
            return $environment['name'] == $name;
        });

        if (0 == count($matchingEnvironments)) {
            throw new \RuntimeException(sprintf(
                'No environment named "%s" found',
                $name
            ));
        }
    }

    /**
     * @Then I should receive an empty list of environments
     */
    public function iShouldReceiveAnEmptyListOfEnvironments()
    {
        $environments = json_decode($this->response->getContent(), true);

        if (!is_array($environments)) {
            throw new \RuntimeException('The response do not looks like to be a JSON array');
        }

        if (count($environments) > 0) {
            throw new \RuntimeException(sprintf(
                'Expected to have 0 environments, found %d',
                count($environments)
            ));
        }
    }

    /**
     * @Then I should not see the environment :name
     */
    public function iShouldNotSeeTheEnvironment($name)
    {
        $environments = json_decode($this->response->getContent(), true);
        $matchingEnvironments = array_filter($environments, function(array $environment) use ($name) {
            return $environment['name'] == $name;
        });

        if (0 != count($matchingEnvironments)) {
            throw new \RuntimeException(sprintf(
                'Environment named "%s" found',
                $name
            ));
        }
    }

    /**
     * @param Uuid $uuid
     * @param array $configuration
     * @return Flow
     */
    public function createFlow(Uuid $uuid = null, array $configuration = [])
    {
        $context = $this->createFlowContext($uuid, $configuration);

        $flow = new Flow($context);
        $this->flowRepository->save($flow);


        $this->currentFlow = $flow;

        return $flow;
    }

    /**
     * @param CodeRepository $codeRepository
     *
     * @param Uuid $uuid
     * @param array $configuration
     * @return RiverFlowContext
     */
    private function createFlowContextWithCodeRepository(CodeRepository $codeRepository, Uuid $uuid = null, array $configuration = [])
    {
        $this->flowUuid = (string) ($uuid ?: Uuid::uuid1());
        $user = new User('samuel.roze@gmail.com', Uuid::uuid1());
        $team = new Team('samuel', Uuid::uuid1());

        $this->codeRepositoryRepository->add($codeRepository);
        $this->authenticatorClient->addUser($user);

        return RiverFlowContext::createFlow(
            Uuid::fromString($this->flowUuid),
            $team,
            $user,
            $codeRepository,
            $configuration
        );
    }

    /**
     * @return Flow
     */
    public function getCurrentFlow()
    {
        return $this->currentFlow;
    }

    /**
     * @param Uuid $uuid
     * @param array $configuration
     * @return RiverFlowContext
     */
    private function createFlowContext(Uuid $uuid = null, array $configuration = [])
    {
        return $this->createFlowContextWithCodeRepository(new CodeRepository\GitHub\GitHubCodeRepository(
            new Repository('foo', 'bar')
        ), $uuid, $configuration);
    }

    /**
     * @param int $code
     */
    private function assertResponseCode($code)
    {
        if ($this->response->getStatusCode() != $code) {
            echo $this->response->getContent();
            throw new \RuntimeException(sprintf(
                'Expected response code %d, but got %d',
                $code,
                $this->response->getStatusCode()
            ));
        }
    }
}
