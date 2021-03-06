<?php

namespace ContinuousPipe\River\CodeRepository\BitBucket;

use Adlogix\GuzzleAtlassianConnect\Middleware\ConnectMiddleware;
use Adlogix\GuzzleAtlassianConnect\Security\HeaderAuthentication;
use ContinuousPipe\AtlassianAddon\Installation;
use ContinuousPipe\AtlassianAddon\InstallationRepository;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;

class BitBucketClientFactory
{
    /**
     * @var InstallationRepository
     */
    private $installationRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var HandlerStack
     */
    private $handlerStack;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var callable|null
     */
    private $csaHistoryMiddleware;

    /**
     * @param InstallationRepository $installationRepository
     * @param LoggerInterface        $logger
     * @param HandlerStack           $handlerStack
     * @param SerializerInterface    $serializer
     * @param callable|null          $csaHistoryMiddleware
     */
    public function __construct(InstallationRepository $installationRepository, LoggerInterface $logger, HandlerStack $handlerStack, SerializerInterface $serializer, callable $csaHistoryMiddleware = null)
    {
        $this->installationRepository = $installationRepository;
        $this->logger = $logger;
        $this->serializer = $serializer;
        $this->handlerStack = $handlerStack;
        $this->csaHistoryMiddleware = $csaHistoryMiddleware;
    }

    public function createForCodeRepository(BitBucketCodeRepository $repository) : BitBucketClient
    {
        $installations = $this->installationRepository->findByPrincipal(
            $repository->getOwner()->getType(),
            $repository->getOwner()->getUsername()
        );

        if (count($installations) == 0) {
            throw new BitBucketClientException('BitBucket add-on installation not found for this repository');
        } elseif (count($installations) > 1) {
            $this->logger->alert('Found multiple installations for a given code repository', [
                'repository_owner' => $repository->getOwner()->getUsername(),
                'repository_name' => $repository->getName(),
                'repository_api_slug' => $repository->getApiSlug(),
            ]);
        }

        /** @var Installation $installation */
        $installation = current($installations);
        $authentication = new HeaderAuthentication($installation->getKey(), $installation->getSharedSecret());
        $authentication->getTokenInstance()->setSubject(
            $installation->getClientKey()
        );

        $middleware = new ConnectMiddleware(
            $authentication,
            $installation->getBaseUrl()
        );

        $stack = $this->handlerStack;
        $stack->push($middleware);

        if (null !== $this->csaHistoryMiddleware) {
            $stack->push($this->csaHistoryMiddleware);
        }

        return new GuzzleBitBucketClient(
            new Client([
                'base_uri' => $installation->getBaseApiUrl(),
                'handler' => $stack,
            ]),
            $this->serializer
        );
    }
}
