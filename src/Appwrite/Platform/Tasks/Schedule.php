<?php

namespace Appwrite\Platform\Tasks;

use Cron\CronExpression;
use Swoole\Timer;
use Utopia\App;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Appwrite\Event\Func;

use function Swoole\Coroutine\run;

class Schedule extends Action
{
    public const FUNCTION_UPDATE_TIMER = 10; //seconds
    public const FUNCTION_ENQUEUE_TIMER = 60; //seconds

    public static function getName(): string
    {
        return 'schedule';
    }

    public function __construct()
    {
        $this
            ->desc('Execute functions scheduled in Appwrite')
            ->inject('pools')
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->callback(fn (Group $pools, Database $dbForConsole, callable $getProjectDB) => $this->action($pools, $dbForConsole, $getProjectDB));
    }

    /**
     * 1. Load all documents from 'schedules' collection to create local copy
     * 2. Create timer that sync all changes from 'schedules' collection to local copy. Only reading changes thanks to 'resourceUpdatedAt' attribute
     * 3. Create timer that prepares coroutines for soon-to-execute schedules. When it's ready, coroutime sleeps until exact time before sending request to worker.
    */
    public function action(Group $pools, Database $dbForConsole, callable $getProjectDB): void
    {
        Console::title('Scheduler V1');
        Console::success(APP_NAME . ' Scheduler v1 has started');

        /**
         * Extract only nessessary attributes to lower memory used.
         *
         * @var Document $schedule
         * @return  array
         */
        $getSchedule = function (Document $schedule) use ($dbForConsole, $getProjectDB): array {
            $project = $dbForConsole->getDocument('projects', $schedule->getAttribute('projectId'));

            $function = $getProjectDB($project)->getDocument('functions', $schedule->getAttribute('resourceId'));

            return [
                'resourceId' => $schedule->getAttribute('resourceId'),
                'schedule' => $schedule->getAttribute('schedule'),
                'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
                'project' => $project, // TODO: @Meldiron Send only ID to worker to reduce memory usage here
                'function' => $function, // TODO: @Meldiron Send only ID to worker to reduce memory usage here
            ];
        };

        $schedules = []; // Local copy of 'schedules' collection
        $lastSyncUpdate = DateTime::now();

        $limit = 10000;
        $sum = $limit;
        $total = 0;
        $loadStart = \microtime(true);
        $latestDocument = null;

        while ($sum === $limit) {
            $paginationQueries = [Query::limit($limit)];
            if ($latestDocument !== null) {
                $paginationQueries[] = Query::cursorAfter($latestDocument);
            }
            $results = $dbForConsole->find('schedules', \array_merge($paginationQueries, [
                Query::equal('region', [App::getEnv('_APP_REGION', 'default')]),
                Query::equal('resourceType', ['function']),
                Query::equal('active', [true]),
            ]));

            $sum = count($results);
            $total = $total + $sum;
            foreach ($results as $document) {
                try {
                    $schedules[$document['resourceId']] = $getSchedule($document);
                } catch (\Throwable $th) {
                    Console::error("Failed to load schedule for project {$document['projectId']} and function {$document['resourceId']}");
                    Console::error($th->getMessage());
                }
            }

            $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
        }

        $pools->reclaim();

        Console::success("{$total} functions were loaded in " . (microtime(true) - $loadStart) . " seconds");

        Console::success("Starting timers at " . DateTime::now());

        run(
            function () use ($dbForConsole, &$schedules, &$lastSyncUpdate, $getSchedule, $pools) {
                /**
                 * The timer synchronize $schedules copy with database collection.
                 */
                Timer::tick(self::FUNCTION_UPDATE_TIMER * 1000, function () use ($dbForConsole, &$schedules, &$lastSyncUpdate, $getSchedule, $pools) {
                    $time = DateTime::now();
                    $timerStart = \microtime(true);

                    $limit = 1000;
                    $sum = $limit;
                    $total = 0;
                    $latestDocument = null;

                    Console::log("Sync tick: Running at $time");

                    while ($sum === $limit) {
                        $paginationQueries = [Query::limit($limit)];
                        if ($latestDocument !== null) {
                            $paginationQueries[] =  Query::cursorAfter($latestDocument);
                        }
                        $results = $dbForConsole->find('schedules', \array_merge($paginationQueries, [
                            Query::equal('region', [App::getEnv('_APP_REGION', 'default')]),
                            Query::equal('resourceType', ['function']),
                            Query::greaterThanEqual('resourceUpdatedAt', $lastSyncUpdate),
                        ]));

                        $sum = count($results);
                        $total = $total + $sum;
                        foreach ($results as $document) {
                            $localDocument = $schedules[$document['resourceId']] ?? null;

                            $org = $localDocument !== null ? strtotime($localDocument['resourceUpdatedAt']) : null;
                            $new = strtotime($document['resourceUpdatedAt']);

                            if ($document['active'] === false) {
                                Console::info("Removing:  {$document['resourceId']}");
                                unset($schedules[$document['resourceId']]);
                            } elseif ($new !== $org) {
                                Console::info("Updating:  {$document['resourceId']}");
                                $schedules[$document['resourceId']] = $getSchedule($document);
                            }
                        }
                        $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
                    }

                    $lastSyncUpdate = $time;
                    $timerEnd = \microtime(true);

                    $pools->reclaim();

                    Console::log("Sync tick: {$total} schedules were updated in " . ($timerEnd - $timerStart) . " seconds");
                });

                /**
                 * The timer to prepare soon-to-execute schedules.
                 */
                $lastEnqueueUpdate = null;
                $enqueueFunctions = function () use (&$schedules, $lastEnqueueUpdate, $pools) {
                    $timerStart = \microtime(true);
                    $time = DateTime::now();

                    $enqueueDiff = $lastEnqueueUpdate === null ? 0 : $timerStart - $lastEnqueueUpdate;
                    $timeFrame = DateTime::addSeconds(new \DateTime(), self::FUNCTION_ENQUEUE_TIMER - $enqueueDiff);

                    Console::log("Enqueue tick: started at: $time (with diff $enqueueDiff)");

                    $total = 0;

                    $delayedExecutions = []; // Group executions with same delay to share one coroutine

                    foreach ($schedules as $key => $schedule) {
                        $cron = new CronExpression($schedule['schedule']);
                        $nextDate = $cron->getNextRunDate();
                        $next = DateTime::format($nextDate);

                        $currentTick = $next < $timeFrame;

                        if (!$currentTick) {
                            continue;
                        }

                        $total++;

                        $promiseStart = \time(); // in seconds
                        $executionStart = $nextDate->getTimestamp(); // in seconds
                        $delay = $executionStart - $promiseStart; // Time to wait from now until execution needs to be queued

                        if (!isset($delayedExecutions[$delay])) {
                            $delayedExecutions[$delay] = [];
                        }

                        $delayedExecutions[$delay][] = $key;
                    }

                    foreach ($delayedExecutions as $delay => $scheduleKeys) {
                        \go(function () use ($delay, $schedules, $scheduleKeys, $pools) {
                            \sleep($delay); // in seconds

                            $queue = $pools->get('queue')->pop();
                            $connection = $queue->getResource();

                            foreach ($scheduleKeys as $scheduleKey) {
                                // Ensure schedule was not deleted
                                if (!isset($schedules[$scheduleKey])) {
                                    return;
                                }

                                $schedule = $schedules[$scheduleKey];

                                $functions = new Func($connection);

                                $functions
                                    ->setType('schedule')
                                    ->setFunction($schedule['function'])
                                    ->setMethod('POST')
                                    ->setPath('/')
                                    ->setProject($schedule['project'])
                                    ->trigger();
                            }

                            $queue->reclaim();
                        });
                    }

                    $timerEnd = \microtime(true);
                    $lastEnqueueUpdate = $timerStart;
                    Console::log("Enqueue tick: {$total} executions were enqueued in " . ($timerEnd - $timerStart) . " seconds");
                };

                Timer::tick(self::FUNCTION_ENQUEUE_TIMER * 1000, fn() => $enqueueFunctions());
                $enqueueFunctions();
            }
        );
    }
}
