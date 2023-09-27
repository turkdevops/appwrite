<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use League\Csv\CannotInsertRecord;
use Utopia\App;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use League\Csv\Writer;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

class CalcTierStats extends Action
{
    /*
     * Csv cols headers
     */
    private array $columns = [
        'Project ID',
        'Organization ID',
        'Organization Members',
        'Teams',
        'Users',
        'Requests',
        'Bandwidth',
        'Domains',
        'Api keys',
        'Webhooks',
        'Platforms',
        'Buckets',
        'Files',
        'Storage (bytes)',
        'Max File Size (bytes)',
        'Databases',
        'Functions',
        'Deployments',
        'Executions',
    ];

    protected string $directory = '/usr/local';
    protected string $path;
    protected string $date;

    private array $usageStats = [
        'project.$all.network.requests'  => 'Requests',
        'project.$all.network.bandwidth' => 'Bandwidth',

    ];

    public static function getName(): string
    {
        return 'calc-tier-stats';
    }

    public function __construct()
    {

        $this
            ->desc('Get stats for projects')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('register')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole, Registry $register) {
                $this->action($pools, $cache, $dbForConsole, $register);
            });
    }

    /**
     * @throws \Utopia\Exception
     * @throws CannotInsertRecord
     */
    public function action(Group $pools, Cache $cache, Database $dbForConsole, Registry $register): void
    {
        //docker compose exec -t appwrite calc-tier-stats

        Console::title('Cloud free tier  stats calculation V1');
        Console::success(APP_NAME . ' cloud free tier  stats calculation has started');

        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');

        /** CSV stuff */
        $this->date = date('Y-m-d');
        $this->path = "{$this->directory}/tier_stats_{$this->date}.csv";
        $csv = Writer::createFromPath($this->path, 'w');
        $csv->insertOne($this->columns);

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
        Console::success("Found a total of: {$totalProjects} projects");

        $projects = [$console];
        $count = 0;
        $limit = 30;
        $sum = 30;
        $offset = 0;
        while (!empty($projects)) {
            foreach ($projects as $project) {

                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console') {
                    continue;
                }

                Console::info("Getting stats for {$project->getId()}");

                try {
                    $db = $project->getAttribute('database');
                    $adapter = $pools
                        ->get($db)
                        ->pop()
                        ->getResource();

                    $dbForProject = new Database($adapter, $cache);
                    $dbForProject->setDefaultDatabase('appwrite');
                    $dbForProject->setNamespace('_' . $project->getInternalId());

                    /** Get Project ID */
                    $stats['Project ID'] = $project->getId();

                    $stats['Organization ID']   = $project->getAttribute('teamId', null);

                    /** Get Total Members */
                    $teamInternalId = $project->getAttribute('teamInternalId', null);
                    if ($teamInternalId) {
                        $stats['Organization Members'] = $dbForConsole->count('memberships', [
                            Query::equal('teamInternalId', [$teamInternalId])
                        ]);
                    } else {
                        $stats['Organization Members'] = 0;
                    }

                    /** Get Total internal Teams */
                    try {
                        $stats['Teams'] = $dbForProject->count('teams', []);
                    } catch (\Throwable) {
                        $stats['Teams'] = 0;
                    }

                    /** Get Total users */
                    try {
                        $stats['Users'] = $dbForProject->count('users', []);
                    } catch (\Throwable) {
                        $stats['Users'] = 0;
                    }

                    /** Get Usage stats */
                    $range = '90d';
                    $periods = [
                        '90d' => [
                            'period' => '1d',
                            'limit' => 90,
                        ],
                    ];

                    $tmp = [];
                    $metrics = $this->usageStats;
                    Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$tmp) {
                        foreach ($metrics as $metric => $name) {
                            $limit = $periods[$range]['limit'];
                            $period = $periods[$range]['period'];

                            $requestDocs = $dbForProject->find('stats', [
                                Query::equal('period', [$period]),
                                Query::equal('metric', [$metric]),
                                Query::limit($limit),
                                Query::orderDesc('time'),
                            ]);

                            $tmp[$metric] = [];
                            foreach ($requestDocs as $requestDoc) {
                                if (empty($requestDoc)) {
                                    continue;
                                }

                                $tmp[$metric][] = [
                                    'value' => $requestDoc->getAttribute('value'),
                                    'date' => $requestDoc->getAttribute('time'),
                                ];
                            }

                            $tmp[$metric] = array_reverse($tmp[$metric]);
                            $tmp[$metric] = array_sum(array_column($tmp[$metric], 'value'));
                        }
                    });

                    foreach ($tmp as $key => $value) {
                        $stats[$metrics[$key]]  = $value;
                    }

                    try {
                        /** Get Domains */
                        $stats['Domains'] = $dbForConsole->count('domains', [
                            Query::equal('projectInternalId', [$project->getInternalId()]),
                        ]);
                    } catch (\Throwable) {
                        $stats['Domains'] = 0;
                    }

                    try {
                    /** Get Api keys */
                        $stats['Api keys'] = $dbForConsole->count('keys', [
                        Query::equal('projectInternalId', [$project->getInternalId()]),
                        ]);
                    } catch (\Throwable) {
                        $stats['Api keys'] = 0;
                    }

                    try {
                    /** Get Webhooks */
                        $stats['Webhooks'] = $dbForConsole->count('webhooks', [
                        Query::equal('projectInternalId', [$project->getInternalId()]),
                        ]);
                    } catch (\Throwable) {
                        $stats['Webhooks'] = 0;
                    }

                    try {
                    /** Get Platforms */
                        $stats['Platforms'] = $dbForConsole->count('platforms', [
                        Query::equal('projectInternalId', [$project->getInternalId()]),
                        ]);
                    } catch (\Throwable) {
                        $stats['Platforms'] = 0;
                    }

                    /** Get Files & Buckets */
                    $filesCount = 0;
                    $filesSum = 0;
                    $maxFileSize = 0;
                    $counter = 0;
                    try {
                        $buckets = $dbForProject->find('buckets', []);
                        foreach ($buckets as $bucket) {
                            $file = $dbForProject->findOne('bucket_' . $bucket->getInternalId(), [Query::orderDesc('sizeOriginal'),]);
                            if (empty($file)) {
                                continue;
                            }
                            $filesSum   += $dbForProject->sum('bucket_' . $bucket->getInternalId(), 'sizeOriginal', [], 0);
                            $filesCount += $dbForProject->count('bucket_' . $bucket->getInternalId(), []);
                            if ($file->getAttribute('sizeOriginal') > $maxFileSize) {
                                $maxFileSize = $file->getAttribute('sizeOriginal');
                            }
                            $counter++;
                        }
                    } catch (\Throwable) {
                        ;
                    }
                    $stats['Buckets'] = $counter;
                    $stats['Files'] = $filesCount;
                    $stats['Storage (bytes)'] = $filesSum;
                    $stats['Max File Size (bytes)'] = $maxFileSize;


                    try {
                    /** Get Total Functions */
                        $stats['Databases'] = $dbForProject->count('databases', []);
                    } catch (\Throwable) {
                        $stats['Databases'] = 0;
                    }

                    /** Get Total Functions */
                    try {
                        $stats['Functions'] = $dbForProject->count('functions', []);
                    } catch (\Throwable) {
                        $stats['Functions'] = 0;
                    }

                    /** Get Total Deployments */
                    try {
                        $stats['Deployments'] = $dbForProject->count('deployments', []);
                    } catch (\Throwable) {
                        $stats['Deployments'] = 0;
                    }

                    /** Get Total Executions */
                    try {
                        $stats['Executions'] = $dbForProject->count('executions', []);
                    } catch (\Throwable) {
                        $stats['Executions'] = 0;
                    }

                    $csv->insertOne(array_values($stats));
                } catch (\Throwable $th) {
                    Console::error('Failed on project ("' . $project->getId() . '") version with error on File: ' . $th->getFile() . '  line no: ' . $th->getline() . ' with message: ' . $th->getMessage());
                } finally {
                    $pools
                        ->get($db)
                        ->reclaim();
                }
            }

            $sum = \count($projects);

            $projects = $dbForConsole->find('projects', [
                Query::limit($limit),
                Query::offset($offset),
            ]);

            $offset = $offset + $limit;
            $count = $count + $sum;
        }

        Console::log('Iterated through ' . $count - 1 . '/' . $totalProjects . ' projects...');

        $pools
            ->get('console')
            ->reclaim();

        /** @var PHPMailer $mail */
        $mail = $register->get('smtp');

        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();

        try {
            /** Addresses */
            $mail->setFrom(App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), 'Appwrite Cloud Hamster');
            $recipients = explode(',', App::getEnv('_APP_USERS_STATS_RECIPIENTS', ''));

            foreach ($recipients as $recipient) {
                $mail->addAddress($recipient);
            }

            /** Attachments */
            $mail->addAttachment($this->path);

            /** Content */
            $mail->Subject = "Cloud Report for {$this->date}";
            $mail->Body = "Please find the daily cloud report atttached";
            $mail->send();
            Console::success('Email has been sent!');
        } catch (Exception $e) {
            Console::error("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        }
    }
}
