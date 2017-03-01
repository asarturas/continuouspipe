<?php

namespace ContinuousPipe\River\Command;

use JMS\Serializer\Annotation as JMS;
use Ramsey\Uuid\UuidInterface;

class StartTideCommand implements TideCommand
{
    /**
     * @JMS\Type("Ramsey\Uuid\Uuid")
     *
     * @var UuidInterface
     */
    private $tideUuid;

    public function __construct(UuidInterface $tideUuid)
    {
        $this->tideUuid = $tideUuid;
    }

    public function getTideUuid(): UuidInterface
    {
        return $this->tideUuid;
    }
}
