<?php

namespace ContinuousPipe\River\Tests\View;

use ContinuousPipe\River\CodeReference;
use ContinuousPipe\River\Flow;
use ContinuousPipe\River\Repository\TideNotFound;
use ContinuousPipe\River\View\Tide;
use ContinuousPipe\River\View\TideRepository;
use Rhumsaa\Uuid\Uuid;

class InMemoryTideRepository implements TideRepository
{
    private $tideByCodeReference = [];
    private $tideByFlow = [];
    private $tides = [];

    /**
     * {@inheritdoc}
     */
    public function findByFlow(Flow $flow)
    {
        $uuid = (string) $flow->getUuid();
        if (!array_key_exists($uuid, $this->tideByFlow)) {
            return [];
        }

        return $this->tideByFlow[$uuid];
    }

    /**
     * {@inheritdoc}
     */
    public function save(Tide $tide)
    {
        $tideUuid = (string) $tide->getUuid();
        $this->tides[$tideUuid] = $tide;

        // Save by flow UUID
        $flowUuid = (string) $tide->getFlow()->getUuid();
        if (!array_key_exists($flowUuid, $this->tideByFlow)) {
            $this->tideByFlow[$flowUuid] = [];
        }
        $this->tideByFlow[$flowUuid][$tideUuid] = $tide;

        // Save by code reference
        $codeReference = $tide->getCodeReference()->getCommitSha();
        if (!array_key_exists($codeReference, $this->tideByCodeReference)) {
            $this->tideByCodeReference[$codeReference] = [];
        }
        $this->tideByCodeReference[$codeReference][$tideUuid] = $tide;
    }

    /**
     * {@inheritdoc}
     */
    public function find(Uuid $uuid)
    {
        if (!array_key_exists((string) $uuid, $this->tides)) {
            throw new TideNotFound(sprintf(
                'Tide with UUID "%s" not found',
                $uuid
            ));
        }

        return $this->tides[(string) $uuid];
    }

    /**
     * {@inheritdoc}
     */
    public function findByCodeReference(CodeReference $codeReference)
    {
        $codeReferenceIdentifier = $codeReference->getCommitSha();
        if (!array_key_exists($codeReferenceIdentifier, $this->tideByCodeReference)) {
            return [];
        }

        return $this->tideByCodeReference[$codeReferenceIdentifier];
    }
}
