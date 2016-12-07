<?php

namespace ContinuousPipe\River\Flow;

use ContinuousPipe\Model\Environment;
use ContinuousPipe\Pipe\Client;
use ContinuousPipe\Pipe\ClusterNotFound;
use ContinuousPipe\River\Environment\DeployedEnvironment;
use ContinuousPipe\River\Environment\DeployedEnvironmentRepository;
use ContinuousPipe\River\Flow\Projections\FlatFlow;
use ContinuousPipe\River\Pipe\ClusterIdentifierResolver;
use ContinuousPipe\Security\Authenticator\UserContext;
use ContinuousPipe\Security\Credentials\BucketRepository;
use ContinuousPipe\Security\Credentials\Cluster;

/**
 * @deprecated To be moved under `ContinuousPipe\River\Environment` namespace
 */
class EnvironmentClient implements DeployedEnvironmentRepository
{
    /**
     * @var Client
     */
    private $pipeClient;

    /**
     * @var ClusterIdentifierResolver
     */
    private $clusterIdentifierResolver;

    /**
     * @var UserContext
     */
    private $userContext;

    /**
     * @var BucketRepository
     */
    private $bucketRepository;

    /**
     * @param Client                    $pipeClient
     * @param ClusterIdentifierResolver $clusterIdentifierResolver
     * @param UserContext               $userContext
     * @param BucketRepository          $bucketRepository
     */
    public function __construct(Client $pipeClient, ClusterIdentifierResolver $clusterIdentifierResolver, UserContext $userContext, BucketRepository $bucketRepository)
    {
        $this->pipeClient = $pipeClient;
        $this->clusterIdentifierResolver = $clusterIdentifierResolver;
        $this->userContext = $userContext;
        $this->bucketRepository = $bucketRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function findByFlow(FlatFlow $flow)
    {
        $environments = [];

        foreach ($this->findClusterIdentifiers($flow) as $clusterIdentifier) {
            try {
                $clusterEnvironments = $this->findEnvironmentsLabelledByFlow($flow, $clusterIdentifier);
            } catch (ClusterNotFound $e) {
                $clusterEnvironments = [];
            }

            // Convert Pipe's `Environment` objects to `DeployedEnvironment`s
            $deployedEnvironments = array_map(function (Environment $environment) use ($clusterIdentifier) {
                return new DeployedEnvironment(
                    $environment->getIdentifier(),
                    $clusterIdentifier,
                    $environment->getComponents()
                );
            }, $clusterEnvironments);

            $environments = array_merge($environments, $deployedEnvironments);
        }

        return $this->uniqueEnvironments($environments);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(FlatFlow $flow, DeployedEnvironment $environment)
    {
        $this->pipeClient->deleteEnvironment(
            new Client\DeploymentRequest\Target(
                $environment->getIdentifier(),
                $environment->getCluster()
            ),
            $flow->getTeam(),
            $this->userContext->getCurrent()
        );
    }

    /**
     * @param FlatFlow $flow
     *
     * @return string[]
     */
    private function findClusterIdentifiers(FlatFlow $flow)
    {
        $teamBucketUuid = $flow->getTeam()->getBucketUuid();
        $credentialsBucket = $this->bucketRepository->find($teamBucketUuid);

        return $credentialsBucket->getClusters()->map(function (Cluster $cluster) {
            return $cluster->getIdentifier();
        })->toArray();
    }

    /**
     * Find environments labelled by the flow UUID.
     *
     * @param FlatFlow $flow
     * @param string   $clusterIdentifier
     *
     * @return Environment[]
     */
    private function findEnvironmentsLabelledByFlow(FlatFlow $flow, $clusterIdentifier)
    {
        return $this->pipeClient->getEnvironmentsLabelled(
            $clusterIdentifier,
            $flow->getTeam(),
            $this->userContext->getCurrent(),
            [
                'flow' => (string) $flow->getUuid(),
            ]
        );
    }

    /**
     * @param DeployedEnvironment[] $environments
     *
     * @return DeployedEnvironment[]
     */
    private function uniqueEnvironments(array $environments)
    {
        $uniqueEnvironments = [];

        foreach ($environments as $environment) {
            if (!array_key_exists($environment->getIdentifier(), $uniqueEnvironments)) {
                $uniqueEnvironments[$environment->getIdentifier()] = $environment;
            }
        }

        return array_values($uniqueEnvironments);
    }
}
