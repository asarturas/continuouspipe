<?php

namespace ContinuousPipe\River\EventListener\TaskFailed;

use ContinuousPipe\River\Task\TaskFailed;
use LogStream\LoggerFactory;
use LogStream\Node\Text;

class LoggingListener
{
    /**
     * @var LoggerFactory
     */
    private $loggerFactory;

    /**
     * @param LoggerFactory $loggerFactory
     */
    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->loggerFactory = $loggerFactory;
    }

    /**
     * @param TaskFailed $event
     */
    public function notify(TaskFailed $event)
    {
        $context = $event->getTask()->getContext();
        $logger = $this->loggerFactory->from($context->getLog());

        $logger->append(new Text(sprintf(
            'Unable to start task "%s": %s',
            $context->getTaskId(),
            $event->getException()->getMessage()
        )));
    }
}
