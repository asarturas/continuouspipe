<?php

namespace ContinuousPipe\River\Task;

use ContinuousPipe\River\Event\TideEvent;

class TaskList
{
    /**
     * @var Task[]
     */
    private $tasks;

    /**
     * @param Task[] $tasks
     */
    public function __construct(array $tasks)
    {
        $this->tasks = $tasks;
    }

    /**
     * @return Task[]
     */
    public function getTasks()
    {
        return $this->tasks;
    }

    /**
     * Apply an event on all the tasks.
     *
     * @param TideEvent $event
     */
    public function apply(TideEvent $event)
    {
        foreach ($this->tasks as $task) {
            if ($task->accept($event)) {
                $task->apply($event);
            }
        }
    }

    /**
     * Has a running task ?
     *
     * @return bool
     */
    public function hasRunning()
    {
        return 0 < count(array_filter($this->tasks, function (Task $task) {
            return $task->isRunning();
        }));
    }

    /**
     * Has a failed task ?
     *
     * @return bool
     */
    public function hasFailed()
    {
        return 0 < count(array_filter($this->tasks, function (Task $task) {
            return $task->isFailed();
        }));
    }

    /**
     * @return Task|null
     */
    public function next()
    {
        foreach ($this->tasks as $task) {
            if ($task->isPending() && !$task->isSkipped()) {
                return $task;
            }
        }

        return;
    }

    /**
     * @return bool
     */
    public function allSuccessful()
    {
        return array_reduce($this->tasks, function ($successful, Task $task) {
            return $successful && ($task->isSuccessful() || $task->isSkipped());
        }, true);
    }
}
