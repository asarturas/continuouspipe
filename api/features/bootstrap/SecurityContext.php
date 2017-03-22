<?php

use Behat\Behat\Context\Context;
use ContinuousPipe\Security\Account\BitBucketAccount;
use ContinuousPipe\Security\ApiKey\UserApiKey;
use ContinuousPipe\Security\Credentials\Bucket;
use ContinuousPipe\Security\Credentials\Cluster\Kubernetes;
use ContinuousPipe\Security\Credentials\DockerRegistry;
use ContinuousPipe\Security\Encryption\InMemory\PreviouslyKnownValuesVault;
use ContinuousPipe\Security\Team\Team;
use ContinuousPipe\Security\Team\TeamMembership;
use ContinuousPipe\Security\Team\TeamNotFound;
use ContinuousPipe\Security\Team\TeamRepository;
use ContinuousPipe\Security\Team\TeamUsageLimits;
use ContinuousPipe\Security\Tests\Authenticator\InMemoryAuthenticatorClient;
use ContinuousPipe\Security\Tests\Team\InMemoryTeamRepository;
use ContinuousPipe\Security\User\SecurityUser;
use ContinuousPipe\Security\User\User;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authentication\Token\JWTUserToken;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class SecurityContext implements Context
{
    const CACHE_SERVICE_ID = 'security.authenticator.cache';
    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;
    /**
     * @var InMemoryAuthenticatorClient
     */
    private $inMemoryAuthenticatorClient;
    /**
     * @var KernelInterface
     */
    private $kernel;
    /**
     * @var PreviouslyKnownValuesVault
     */
    private $previouslyKnownValuesVault;

    /**
     * @var Response|null
     */
    private $response;

    /**
     * @var User|null
     */
    private $currentUser;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        InMemoryAuthenticatorClient $inMemoryAuthenticatorClient,
        KernelInterface $kernel,
        PreviouslyKnownValuesVault $previouslyKnownValuesVault
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->inMemoryAuthenticatorClient = $inMemoryAuthenticatorClient;
        $this->kernel = $kernel;
        $this->previouslyKnownValuesVault = $previouslyKnownValuesVault;
    }

    /**
     * @Given the created API key for the user :username will have the key :apiKey
     */
    public function theCreatedApiKeyForTheUserWillHaveTheKey($username, $apiKey)
    {
        $this->inMemoryAuthenticatorClient->setApiKeyCreationHook(function(User $user, string $description) use ($username, $apiKey) {
            return new UserApiKey(
                Uuid::uuid4(),
                $user,
                $apiKey,
                new \DateTime(),
                $description
            );
        });
    }

    /**
     * @Given I am authenticated
     */
    public function iAmAuthenticated()
    {
        $this->iAmAuthenticatedAs('samuel.roze@gmail.com');
    }

    /**
     * @Given the user :username have the API key :apiKey
     */
    public function theUserHaveTheApiKey($username, $apiKey)
    {
        $this->inMemoryAuthenticatorClient->addApiKey(
            new UserApiKey(
                Uuid::uuid4(),
                $this->inMemoryAuthenticatorClient->getUserByUsername($username),
                $apiKey,
                new \DateTime(),
                $apiKey
            )
        );
    }

    /**
     * @Given I am authenticated as :username
     */
    public function iAmAuthenticatedAs($username)
    {
        $user = $this->thereIsAUser($username);

        $token = new JWTUserToken(['ROLE_USER']);
        $token->setUser(new SecurityUser($user));
        $this->tokenStorage->setToken($token);

        $this->currentUser = $user;
    }

    /**
     * @Given there is a user :username
     */
    public function thereIsAUser($username)
    {
        $user = new User($username, Uuid::uuid1());

        $this->inMemoryAuthenticatorClient->addUser($user);

        return $user;
    }

    /**
     * @Given the team :slug exists
     * @Given there is a team :slug
     */
    public function theTeamExists($slug)
    {
        try {
            $team = $this->inMemoryAuthenticatorClient->findTeamBySlug($slug);
        } catch (TeamNotFound $e) {
            $bucket = new Bucket(Uuid::uuid1());
            $this->inMemoryAuthenticatorClient->addBucket($bucket);

            $team = new Team($slug, $slug, $bucket->getUuid());
            $this->inMemoryAuthenticatorClient->addTeam($team);
        }

        return $team;
    }

    /**
     * @Given the team :slug has a :tidesPerHour tides per hour usage limit
     */
    public function theTeamHasATidesPerHourUsageLimit($slug, $tidesPerHour)
    {
        $team = $this->theTeamExists($slug);
        $this->inMemoryAuthenticatorClient->addTeamUsageLimit($team, new TeamUsageLimits($tidesPerHour));
    }

    /**
     * @Given the user :username is :permission of the team :team
     */
    public function theUserIsOfTheTeam($username, $permission, $team)
    {
        $team = $this->inMemoryAuthenticatorClient->findTeamBySlug($team);
        $user = $this->inMemoryAuthenticatorClient->getUserByUsername($username);

        $memberships = $team->getMemberships()->filter(function(TeamMembership $teamMembership) use ($user) {
            return $teamMembership->getUser()->getUsername() == $user->getUsername();
        });

        $memberships->add(new TeamMembership($team, $user, [$permission]));

        $team = new Team(
            $team->getSlug(),
            $team->getName(),
            $team->getBucketUuid(),
            $memberships->toArray()
        );

        $this->inMemoryAuthenticatorClient->addTeam($team);
    }

    /**
     * @Given the team :team have the credentials of a cluster :cluster
     * @Given the team :team have the credentials of a cluster :cluster with address :address
     */
    public function theTeamHaveTheCredentialsOfACluster($team, $cluster, $address = null)
    {
        $team = $this->inMemoryAuthenticatorClient->findTeamBySlug($team);
        $bucket = $this->inMemoryAuthenticatorClient->findBucketByUuid($team->getBucketUuid());
        $address = $address ?: 'https://1.2.3.4';

        $bucket->getClusters()->add(new Kubernetes($cluster, $address, 'v1', '', ''));

        $this->inMemoryAuthenticatorClient->addBucket($bucket);
    }

    /**
     * @Given the team :team have the credentials of a Docker registry :registry
     * @Given the team :team have the credentials of a Docker registry :registry with the username :username
     */
    public function theTeamHaveTheCredentialsOfADockerRegistry($team, $registry, $username = null)
    {
        $team = $this->inMemoryAuthenticatorClient->findTeamBySlug($team);
        $bucket = $this->inMemoryAuthenticatorClient->findBucketByUuid($team->getBucketUuid());

        $bucket->getDockerRegistries()->add(new DockerRegistry($username ?: 'username', 'password', 'email@example.com', $registry));

        $this->inMemoryAuthenticatorClient->addBucket($bucket);
    }

    /**
     * @Given the user :username is a ghost
     */
    public function theUserIsAGhost($username)
    {
        $user = $this->inMemoryAuthenticatorClient->getUserByUsername($username);
        $user->setRoles(array_merge($user->getRoles(), ['ROLE_GHOST']));

        $this->inMemoryAuthenticatorClient->addUser($user);
    }

    /**
     * @Given I have a BitBucket account :uuid for the user :username
     */
    public function iHaveABitbucketAccountForTheUser($uuid, $username)
    {
        $this->inMemoryAuthenticatorClient->addAccount(
            $this->currentUser,
            new BitBucketAccount(
                Uuid::fromString($uuid),
                $username,
                $username,
                $username.'@example.com',
                'refresh-token'
            )
        );
    }

    /**
     * @Given I send a :method request to the path :path
     */
    public function iSendARequestToThePath($method, $path)
    {
        $this->response = $this->kernel->handle(Request::create($path, $method));
    }

    /**
     * @Then the status code of the response should be :code
     */
    public function theStatusCodeOfTheResponseShouldBe($code)
    {
        if ($this->response->getStatusCode() != $code) {
            throw new \RuntimeException(sprintf(
                'Expected code %d but got %d',
                $code,
                $this->response->getStatusCode()
            ));
        }
    }

    /**
     * @Given the authenticator cache is on
     */
    public function securityCacheIsOn()
    {
        if (!$this->kernel->getContainer()->has(self::CACHE_SERVICE_ID)) {
            throw new \RuntimeException(
                sprintf('Authenticator cache is disabled. Undefined service "%s".', self::CACHE_SERVICE_ID)
            );
        }
    }

    /**
     * @Given the encrypted version of the value :plainValue for the flow :flowUuid will be :encryptedValue
     */
    public function theEncryptedVersionOfTheValueForTheFlowWillBe($plainValue, $flowUuid, $encryptedValue)
    {
        $this->previouslyKnownValuesVault->addEncryptionMapping(
            'flow-'.$flowUuid,
            $plainValue,
            $encryptedValue
        );
    }

    /**
     * @Given the decrypted version of the value :encryptedValue for the flow :flowUuid will be :plainValue
     */
    public function theDecryptedVersionOfTheValueForTheFlowWillBe($encryptedValue, $flowUuid, $plainValue)
    {
        $this->previouslyKnownValuesVault->addDecryptionMapping(
            'flow-'.$flowUuid,
            $encryptedValue,
            $plainValue
        );
    }
}
