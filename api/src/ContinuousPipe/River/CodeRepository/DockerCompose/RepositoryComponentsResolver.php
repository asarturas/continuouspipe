<?php

namespace ContinuousPipe\River\CodeRepository\DockerCompose;

use ContinuousPipe\DockerCompose\DockerComposeException;
use ContinuousPipe\DockerCompose\Parser\ProjectParser;
use ContinuousPipe\DockerCompose\RelativeFileSystem;
use ContinuousPipe\River\CodeReference;
use ContinuousPipe\River\CodeRepository\FileSystemResolver;
use ContinuousPipe\River\View\Flow;
use ContinuousPipe\Security\Credentials\BucketContainer;

class RepositoryComponentsResolver implements ComponentsResolver
{
    /**
     * @var FileSystemResolver
     */
    private $fileSystemResolver;

    /**
     * @var ProjectParser
     */
    private $projectParser;

    /**
     * @param FileSystemResolver $fileSystemResolver
     * @param ProjectParser      $projectParser
     */
    public function __construct(FileSystemResolver $fileSystemResolver, ProjectParser $projectParser)
    {
        $this->fileSystemResolver = $fileSystemResolver;
        $this->projectParser = $projectParser;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(Flow $flow, CodeReference $codeReference)
    {
        return $this->resolveWithFilesystem(
            $this->fileSystemResolver->getFileSystem($flow, $codeReference),
            $codeReference
        );
    }

    /**
     * {@inheritdoc}
     */
    public function resolveByCodeReferenceAndBucket(CodeReference $codeReference, BucketContainer $bucketContainer)
    {
        return $this->resolveWithFilesystem(
            $this->fileSystemResolver->getFileSystemWithBucketContainer($codeReference, $bucketContainer),
            $codeReference
        );
    }

    /**
     * @param RelativeFileSystem $fileSystem
     * @param CodeReference      $codeReference
     *
     * @return array
     */
    private function resolveWithFilesystem(RelativeFileSystem $fileSystem, CodeReference $codeReference)
    {
        $dockerComposeComponents = [];

        try {
            foreach ($this->projectParser->parse($fileSystem, $codeReference->getBranch()) as $name => $raw) {
                $dockerComposeComponents[] = DockerComposeComponent::fromParsed($name, $raw);
            }
        } catch (DockerComposeException $e) {
            throw new ResolveException($e->getMessage(), $e->getCode(), $e);
        }

        return $dockerComposeComponents;
    }
}
