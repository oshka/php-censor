<?php

declare(strict_types = 1);

namespace PHPCensor\Controller;

use Exception;
use GuzzleHttp\Client;
use PHPCensor\Controller;
use PHPCensor\Exception\HttpException\ForbiddenException;
use PHPCensor\Exception\HttpException\NotFoundException;
use PHPCensor\Common\Exception\InvalidArgumentException;
use PHPCensor\Common\Exception\RuntimeException;
use PHPCensor\Helper\Lang;
use PHPCensor\Http\Response;
use PHPCensor\Model\Build;
use PHPCensor\Model\Build\BitbucketBuild;
use PHPCensor\Model\Build\BitbucketServerBuild;
use PHPCensor\Model\Build\GithubBuild;
use PHPCensor\Model\Project;
use PHPCensor\Service\BuildService;
use PHPCensor\Store\BuildStore;
use PHPCensor\Store\EnvironmentStore;
use PHPCensor\Store\ProjectStore;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dan Cryer <dan@block8.co.uk>
 * @author Sami Tikka <stikka@iki.fi>
 * @author Alex Russell <alex@clevercherry.com>
 * @author Guillaume Perréal <adirelle@gmail.com>
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class WebhookController extends Controller
{
    protected BuildStore $buildStore;

    protected ProjectStore $projectStore;

    protected BuildService $buildService;

    /**
     * Initialise the controller, set up stores and services.
     */
    public function init(): void
    {
        $this->buildStore   = $this->storeRegistry->get('Build');
        $this->projectStore = $this->storeRegistry->get('Project');

        $this->buildService = new BuildService(
            $this->configuration,
            $this->storeRegistry,
            $this->buildStore,
            $this->projectStore
        );
    }

    /**
     * Handle the action, Ensuring to return a JsonResponse.
     *
     * @param string $action
     * @param array  $actionParams
     *
     * @return Response
     */
    public function handleAction(string $action, array $actionParams): Response
    {
        $response = new Response\JsonResponse();
        try {
            $data = parent::handleAction($action, $actionParams);
            if (isset($data['responseCode'])) {
                $response->setResponseCode($data['responseCode']);
                unset($data['responseCode']);
            }
            $response->setContent($data);
        } catch (Exception $ex) {
            $response->setResponseCode(500);
            $response->setContent(['status' => 'failed', 'error' => $ex->getMessage()]);
        }
        return $response;
    }

    /**
     * Wrapper for creating a new build.
     *
     * @param int $source
     * @param Project $project
     * @param string $commitId
     * @param string $branch
     * @param string $tag
     * @param string $committer
     * @param string $commitMessage
     * @param array|null $extra
     * @param string|null $environment
     *
     * @return string[]
     *
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \PHPCensor\Exception\HttpException
     */
    protected function createBuild(
        int $source,
        Project $project,
        string $commitId,
        string $branch,
        ?string $tag,
        string $committer,
        string $commitMessage,
        ?array $extra = null,
        ?string $environment = null
    ): array {
        if ($project->getArchived()) {
            throw new NotFoundException(Lang::get('project_x_not_found', $project->getId()));
        }

        // Check if a build already exists for this commit ID:
        $builds = $this->buildStore->getByProjectAndCommit($project->getId(), $commitId);

        $ignoreEnvironments = [];
        $ignoreTags         = [];
        if ($builds['count']) {
            foreach ($builds['items'] as $build) {
                /** @var Build $build */
                $ignoreEnvironments[$build->getId()] = $build->getEnvironmentId();
                $ignoreTags[$build->getId()]         = $build->getTag();
            }
        }

        // Check if this branch is to be built.
        if ($project->getDefaultBranchOnly() && ($branch !== $project->getDefaultBranch())) {
            return [
                'status'  => 'ignored',
                'message' => 'The branch is not a branch by default. Build is allowed only for the branch by default.'
            ];
        }

        $environments = $project->getEnvironmentsObjects();
        if ($environments['count']) {
            $createdBuilds = [];

            /** @var EnvironmentStore $environmentStore */
            $environmentStore  = $this->storeRegistry->get('Environment');
            $environmentObject = $environmentStore->getByNameAndProjectId($environment, $project->getId());
            if ($environment && $environmentObject) {
                if (
                    !\in_array($environmentObject->getId(), $ignoreEnvironments) ||
                    ($tag && !\in_array($tag, $ignoreTags, true))
                ) {
                    // If not, create a new build job for it:
                    $build = $this->buildService->createBuild(
                        $project,
                        $environmentObject->getId(),
                        $commitId,
                        $project->getDefaultBranch(),
                        $tag,
                        $committer,
                        $commitMessage,
                        (int)$source,
                        null,
                        $extra
                    );

                    $createdBuilds[] = [
                        'id'          => $build->getID(),
                        'environment' => $environmentObject->getId(),
                    ];
                } else {
                    $duplicates[] = \array_search($environmentObject->getId(), $ignoreEnvironments);
                }

                if (!empty($createdBuilds)) {
                    if (empty($duplicates)) {
                        return ['status' => 'ok', 'builds' => $createdBuilds];
                    } else {
                        return [
                            'status'  => 'ok',
                            'builds'  => $createdBuilds,
                            'message' => \sprintf(
                                'For this commit some builds already exists (%s)',
                                \implode(', ', $duplicates)
                            )
                        ];
                    }
                } else {
                    return [
                        'status'  => 'ignored',
                        'message' => \sprintf(
                            'For this commit already created builds (%s)',
                            \implode(', ', $duplicates)
                        )
                    ];
                }
            } else {
                $environmentIds = $project->getEnvironmentsNamesByBranch($branch);
                // use base branch from project
                if (!empty($environmentIds)) {
                    $duplicates = [];
                    foreach ($environmentIds as $environmentId) {
                        if (
                            !\in_array($environmentId, $ignoreEnvironments) ||
                            ($tag && !\in_array($tag, $ignoreTags, true))
                        ) {
                            // If not, create a new build job for it:
                            $build = $this->buildService->createBuild(
                                $project,
                                $environmentId,
                                $commitId,
                                $project->getDefaultBranch(),
                                $tag,
                                $committer,
                                $commitMessage,
                                (int)$source,
                                null,
                                $extra
                            );

                            $createdBuilds[] = [
                                'id'          => $build->getID(),
                                'environment' => $environmentId,
                            ];
                        } else {
                            $duplicates[] = \array_search($environmentId, $ignoreEnvironments);
                        }
                    }

                    if (!empty($createdBuilds)) {
                        if (empty($duplicates)) {
                            return ['status' => 'ok', 'builds' => $createdBuilds];
                        } else {
                            return [
                                'status'  => 'ok',
                                'builds'  => $createdBuilds,
                                'message' => \sprintf(
                                    'For this commit some builds already exists (%s)',
                                    \implode(', ', $duplicates)
                                )
                            ];
                        }
                    } else {
                        return [
                            'status'  => 'ignored',
                            'message' => \sprintf(
                                'For this commit already created builds (%s)',
                                \implode(', ', $duplicates)
                            )
                        ];
                    }
                } else {
                    return ['status' => 'ignored', 'message' => 'Branch not assigned to any environment'];
                }
            }
        } else {
            $environmentId = null;
            if (!\in_array($environmentId, $ignoreEnvironments, true) ||
                ($tag && !\in_array($tag, $ignoreTags, true))) {
                $build = $this->buildService->createBuild(
                    $project,
                    null,
                    $commitId,
                    $branch,
                    $tag,
                    $committer,
                    $commitMessage,
                    (int)$source,
                    null,
                    $extra
                );

                return ['status' => 'ok', 'buildID' => $build->getID()];
            } else {
                return [
                    'status'  => 'ignored',
                    'message' => \sprintf(
                        'Duplicate of build #%d',
                        \array_search($environmentId, $ignoreEnvironments)
                    ),
                ];
            }
        }
    }

    /**
     * Fetch a project and check its type.
     *
     * @param int    $projectId    id or title of project
     * @param array  $expectedType
     *
     * @return Project
     *
     * @throws Exception If the project does not exist or is not of the expected type.
     */
    protected function fetchProject(int $projectId, array $expectedType): Project
    {
        if (empty($projectId)) {
            throw new NotFoundException('Project does not exist: ' . $projectId);
        }

        if (\is_numeric($projectId)) {
            $project = $this->projectStore->getById((int)$projectId);
        } else {
            $projects = $this->projectStore->getByTitle($projectId, 2);
            if ($projects['count'] < 1) {
                throw new NotFoundException('Project does not found: ' . $projectId);
            }
            if ($projects['count'] > 1) {
                throw new NotFoundException('Project id is ambiguous: ' . $projectId);
            }
            $project = \reset($projects['items']);
        }

        if (!\in_array($project->getType(), $expectedType, true)) {
            throw new NotFoundException('Wrong project type: ' . $project->getType());
        }

        return $project;
    }

    /**
     * Called by POSTing to /webhook/git/<project_id>?branch=<branch>&commit=<commit>
     *
     * @param int $projectId
     *
     * @return array
     *
     * @throws Exception
     */
    public function git(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_LOCAL,
            Project::TYPE_GIT,
        ]);
        $branch        = $this->getParam('branch', $project->getDefaultBranch());
        $environment   = $this->getParam('environment');
        $commit        = $this->getParam('commit');
        $commitMessage = $this->getParam('message');
        $committer     = $this->getParam('committer');

        return $this->createBuild(
            Build::SOURCE_WEBHOOK_PUSH,
            $project,
            $commit,
            $branch,
            null,
            $committer,
            $commitMessage,
            null,
            $environment
        );
    }

    /**
     * Called by POSTing to /webhook/hg/<project_id>?branch=<branch>&commit=<commit>
     *
     * @param int $projectId
     *
     * @return array
     *
     * @throws Exception
     */
    public function hg(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_LOCAL,
            Project::TYPE_HG,
        ]);
        $branch        = $this->getParam('branch', $project->getDefaultBranch());
        $commit        = $this->getParam('commit');
        $commitMessage = $this->getParam('message');
        $committer     = $this->getParam('committer');

        return $this->createBuild(
            Build::SOURCE_WEBHOOK_PUSH,
            $project,
            $commit,
            $branch,
            null,
            $committer,
            $commitMessage
        );
    }

    /**
     * Called by POSTing to /webhook/svn/<project_id>?branch=<branch>&commit=<commit>
     *
     * @author Sylvain Lévesque <slevesque@gezere.com>
     *
     * @param int $projectId
     *
     * @return array
     *
     * @throws Exception
     */
    public function svn(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_SVN
        ]);
        $branch        = $this->getParam('branch', $project->getDefaultBranch());
        $commit        = $this->getParam('commit');
        $commitMessage = $this->getParam('message');
        $committer     = $this->getParam('committer');

        return $this->createBuild(
            Build::SOURCE_WEBHOOK_PUSH,
            $project,
            $commit,
            $branch,
            null,
            $committer,
            $commitMessage
        );
    }

    /**
     * Called by Bitbucket.
     *
     * @param int $projectId
     *
     * @return array
     *
     * @throws Exception
     */
    public function bitbucket(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_BITBUCKET,
            Project::TYPE_BITBUCKET_HG,
            Project::TYPE_BITBUCKET_SERVER,
            Project::TYPE_GIT,
            Project::TYPE_HG,
        ]);

        // Support both old services and new webhooks
        if ($payload = $this->getParam('payload')) {
            return $this->bitbucketService(\json_decode($payload, true), $project);
        }

        $payload = \json_decode(\file_get_contents("php://input"), true);

        // Handle Pull Request webhooks:
        if (!empty($payload['pullrequest'])) {
            return $this->bitbucketPullRequest($project, $payload);
        }

        // Handle Pull Request webhook for BB server:
        if (!empty($payload['pullRequest'])) {
            return $this->bitbucketSvrPullRequest($project, $payload);
        }

        // Handle Push (and Tag) webhooks:
        if (!empty($payload['push']['changes'])) {
            return $this->bitbucketCommitRequest($project, $payload);
        }

        // Invalid event from bitbucket
        return [
            'status' => 'failed',
            'commits' => []
        ];
    }

    /**
     * Handle the payload when Bitbucket sends a commit webhook.
     *
     * @param Project $project
     * @param array   $payload
     *
     * @return array
     */
    protected function bitbucketCommitRequest(Project $project, array $payload): array
    {
        $results = [];
        $status  = 'failed';
        foreach ($payload['push']['changes'] as $commit) {
            if (!empty($commit['new'])) {
                try {
                    $email = $commit['new']['target']['author']['raw'];
                    if (\strpos($email, '>') !== false) {
                        // In order not to loose email if it is RAW, w/o "<>" symbols
                        $email = \substr($email, 0, \strpos($email, '>'));
                        $email = \substr($email, \strpos($email, '<') + 1);
                    }

                    $results[$commit['new']['target']['hash']] = $this->createBuild(
                        Build::SOURCE_WEBHOOK_PUSH,
                        $project,
                        $commit['new']['target']['hash'],
                        $commit['new']['name'],
                        null,
                        $email,
                        $commit['new']['target']['message']
                    );
                    $status = 'ok';
                } catch (Exception $ex) {
                    $results[$commit['new']['target']['hash']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Handle the payload when Bitbucket sends a Pull Request webhook.
     *
     * @param Project $project
     * @param array   $payload
     *
     * @return array
     *
     * @throws Exception
     */
    protected function bitbucketPullRequest(Project $project, array $payload): array
    {
        $triggerType = \trim($_SERVER['HTTP_X_EVENT_KEY']);

        if (!\array_key_exists(
            $triggerType,
            BitbucketBuild::$pullrequestTriggersToSources
        )) {
            return [
                'status'  => 'ignored',
                'message' => 'Trigger type "' . $triggerType . '" is not supported.'
            ];
        }

        $username    = $this->configuration->get('php-censor.bitbucket.username');
        $appPassword = $this->configuration->get('php-censor.bitbucket.app_password');

        if (empty($username) || empty($appPassword)) {
            throw new ForbiddenException('Please provide Username and App Password of your Bitbucket account.');
        }

        $commitsUrl = $payload['pullrequest']['links']['commits']['href'];

        $client = new Client();
        $commitsResponse = $client->get($commitsUrl, [
            'auth' => [$username, $appPassword],
        ]);
        $httpStatus = (int)$commitsResponse->getStatusCode();

        // Check we got a success response:
        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('Could not get commits, failed API request.');
        }

        $results = [];
        $status  = 'failed';
        $commits = \json_decode((string)$commitsResponse->getBody(), true)['values'];
        foreach ($commits as $commit) {
            // Skip all but the current HEAD commit ID:
            $id = $commit['hash'];
            if (\strpos($id, $payload['pullrequest']['source']['commit']['hash']) !== 0) {
                $results[$id] = ['status' => 'ignored', 'message' => 'not branch head'];

                continue;
            }

            try {
                $branch    = $payload['pullrequest']['destination']['branch']['name'];
                $committer = $commit['author']['raw'];
                if (\strpos($committer, '>') !== false) {
                    // In order not to loose email if it is RAW, w/o "<>" symbols
                    $committer = \substr($committer, 0, \strpos($committer, '>'));
                    $committer = \substr($committer, \strpos($committer, '<') + 1);
                }
                $message   = $commit['message'];

                $extra = [
                    'pull_request_number' => $payload['pullrequest']['id'],
                    'remote_branch'       => $payload['pullrequest']['source']['branch']['name'],
                    'remote_reference'    => $payload['pullrequest']['source']['repository']['full_name'],
                ];

                $results[$id] = $this->createBuild(
                    BitbucketBuild::$pullrequestTriggersToSources[$triggerType],
                    $project,
                    $id,
                    $branch,
                    null,
                    $committer,
                    $message,
                    $extra
                );
                $status = 'ok';
            } catch (Exception $ex) {
                $results[$id] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Handle the payload when Bitbucket Server sends a Pull Request webhook.
     *
     * @param Project $project
     * @param array   $payload
     *
     * @return array
     *
     * @throws Exception
     */
    protected function bitbucketSvrPullRequest(Project $project, array $payload): array
    {
        $triggerType = \trim($_SERVER['HTTP_X_EVENT_KEY']);

        if (!\array_key_exists(
            $triggerType,
            BitbucketServerBuild::$pullrequestTriggersToSources
        )) {
            return [
                'status'  => 'ignored',
                'message' => 'Trigger type "' . $triggerType . '" is not supported.'
            ];
        }

        try {
            $branch    = $payload['pullRequest']['toRef']['displayId'];
            $committer = $payload['pullRequest']['author']['user']['emailAddress'];
            $message   = $payload['pullRequest']['description'];
            $id        = $payload['pullRequest']['fromRef']['latestCommit'];

            $extra = [
                'pull_request_number' => $payload['pullRequest']['id'],
                'remote_branch'       => $payload['pullrequest']['fromRef']['displayId'],
                'remote_reference'    => $payload['pullrequest']['fromRef']['repository']['project']['name'],
            ];

            $results = [];

            $results[$id] = $this->createBuild(
                BitbucketServerBuild::$pullrequestTriggersToSources[$triggerType],
                $project,
                $id,
                $branch,
                null,
                $committer,
                $message,
                $extra
            );
            $status = 'ok';
        } catch (Exception $ex) {
            $results[$id] = ['status' => 'failed', 'error' => $ex->getMessage()];
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Bitbucket POST service.
     *
     * @param array   $payload
     * @param Project $project
     *
     * @return array
     */
    protected function bitbucketService(array $payload, Project $project): array
    {
        $results = [];
        $status  = 'failed';
        foreach ($payload['commits'] as $commit) {
            try {
                $email = $commit['raw_author'];
                $email = \substr($email, 0, \strpos($email, '>'));
                $email = \substr($email, \strpos($email, '<') + 1);

                $results[$commit['raw_node']] = $this->createBuild(
                    Build::SOURCE_WEBHOOK_PUSH,
                    $project,
                    $commit['raw_node'],
                    $commit['branch'],
                    null,
                    $email,
                    $commit['message']
                );
                $status = 'ok';
            } catch (Exception $ex) {
                $results[$commit['raw_node']] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * @param int $projectId
     *
     * @return array
     *
     * @throws Exception
     */
    public function github(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_GITHUB,
            Project::TYPE_GIT,
        ]);

        switch ($_SERVER['CONTENT_TYPE']) {
            case 'application/json':
                $payload = \json_decode(\file_get_contents('php://input'), true);
                break;
            case 'application/x-www-form-urlencoded':
                $payload = \json_decode($this->getParam('payload'), true);
                break;
            default:
                return [
                    'status'       => 'failed',
                    'error'        => 'Content type not supported.',
                    'responseCode' => 401
                ];
        }

        // Handle Pull Request webhooks:
        if (\array_key_exists('pull_request', $payload)) {
            return $this->githubPullRequest($project, $payload);
        }

        // Handle Push (and Tag) webhooks:
        if (\array_key_exists('head_commit', $payload)) {
            return $this->githubCommitRequest($project, $payload);
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Github sends a commit webhook.
     *
     * @param Project $project
     * @param array   $payload
     *
     * @return array
     */
    protected function githubCommitRequest(Project $project, array $payload): array
    {
        // Github sends a payload when you close a pull request with a non-existent commit. We don't want this.
        if (\array_key_exists('after', $payload) &&
            $payload['after'] === '0000000000000000000000000000000000000000') {
            return ['status' => 'ignored'];
        }

        if (isset($payload['head_commit']) && $payload['head_commit']) {
            $isTag   = (\substr($payload['ref'], 0, 10) == 'refs/tags/') ? true : false;
            $commit  = $payload['head_commit'];
            $results = [];
            $status  = 'failed';

            if (!$commit['distinct']) {
                $results[$commit['id']] = ['status' => 'ignored'];
            } else {
                try {
                    $tag = null;
                    if ($isTag) {
                        $tag       = \str_replace('refs/tags/', '', $payload['ref']);
                        $branch    = \str_replace('refs/heads/', '', $payload['base_ref']);
                        $committer = $payload['pusher']['email'];
                    } else {
                        $branch    = \str_replace('refs/heads/', '', $payload['ref']);
                        $committer = $commit['committer']['email'];
                    }

                    $results[$commit['id']] = $this->createBuild(
                        Build::SOURCE_WEBHOOK_PUSH,
                        $project,
                        $commit['id'],
                        $branch,
                        $tag,
                        $committer,
                        $commit['message']
                    );

                    $status = 'ok';
                } catch (Exception $ex) {
                    $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }

            return ['status' => $status, 'commits' => $results];
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Github sends a Pull Request webhook.
     *
     * @param Project $project
     * @param array   $payload
     *
     * @return array
     *
     * @throws Exception
     */
    protected function githubPullRequest(Project $project, array $payload): array
    {
        $triggerType = \trim($payload['action']);

        if (!\array_key_exists(
            $triggerType,
            GithubBuild::$pullrequestTriggersToSources
        )) {
            return [
                'status'  => 'ignored',
                'message' => 'Trigger type "' . $triggerType . '" is not supported.'
            ];
        }

        $headers = [];
        $token   = $this->configuration->get('php-censor.github.token');

        if (!empty($token)) {
            $headers['Authorization'] = 'token ' . $token;
        }

        $url = $payload['pull_request']['commits_url'];

        //for large pull requests, allow grabbing more then the default number of commits
        $customPerPage = $this->configuration->get('php-censor.github.per_page');
        $params        = [];
        if ($customPerPage) {
            $params['per_page'] = $customPerPage;
        }

        $client   = new Client();
        $response = $client->get($url, [
            'headers' => $headers,
            'query'   => $params,
        ]);
        $status = (int)$response->getStatusCode();

        // Check we got a success response:
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Could not get commits, failed API request.');
        }

        $results = [];
        $status  = 'failed';
        $commits = \json_decode((string)$response->getBody(), true);
        foreach ($commits as $commit) {
            // Skip all but the current HEAD commit ID:
            $id = $commit['sha'];
            if ($id !== $payload['pull_request']['head']['sha']) {
                $results[$id] = ['status' => 'ignored', 'message' => 'not branch head'];

                continue;
            }

            try {
                $branch    = \str_replace('refs/heads/', '', $payload['pull_request']['base']['ref']);
                $committer = $commit['commit']['author']['email'];
                $message   = $commit['commit']['message'];

                $extra = [
                    'pull_request_number' => $payload['number'],
                    'remote_branch'       => $payload['pull_request']['head']['ref'],
                    'remote_reference'    => $payload['pull_request']['head']['repo']['full_name'],
                ];

                $results[$id] = $this->createBuild(
                    GithubBuild::$pullrequestTriggersToSources[$triggerType],
                    $project,
                    $id,
                    $branch,
                    null,
                    $committer,
                    $message,
                    $extra
                );
                $status = 'ok';
            } catch (Exception $ex) {
                $results[$id] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Called by Gitlab Webhooks:
     *
     * @param int $projectId
     *
     * @return array
     *
     * @throws Exception
     */
    public function gitlab(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_GITLAB,
            Project::TYPE_GIT,
        ]);

        $payloadString = \file_get_contents("php://input");
        $payload       = \json_decode($payloadString, true);

        // build on merge request events
        if (isset($payload['object_kind']) && $payload['object_kind'] == 'merge_request') {
            $attributes = $payload['object_attributes'];
            if ($attributes['state'] === 'opened' || $attributes['state'] === 'reopened') {
                $branch    = $attributes['source_branch'];
                $commit    = $attributes['last_commit'];
                $committer = $commit['author']['email'];

                return $this->createBuild(
                    Build::SOURCE_WEBHOOK_PULL_REQUEST_CREATED,
                    $project,
                    $commit['id'],
                    $branch,
                    null,
                    $committer,
                    $commit['message']
                );
            }
        }

        // build on push events
        if (isset($payload['commits']) && \is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:

            $results = [];
            $status  = 'failed';

            $commit = \end($payload['commits']);
            try {
                $branch                 = \str_replace('refs/heads/', '', $payload['ref']);
                $committer              = $commit['author']['email'];
                $results[$commit['id']] = $this->createBuild(
                    Build::SOURCE_WEBHOOK_PUSH,
                    $project,
                    $commit['id'],
                    $branch,
                    null,
                    $committer,
                    $commit['message']
                );
                $status = 'ok';
            } catch (Exception $ex) {
                $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }

            return ['status' => $status, 'commits' => $results];
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * @param string $projectId
     *
     * @return array
     *
     * @throws Exception
     */
    public function gogs(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_GOGS,
            Project::TYPE_GIT,
        ]);

        $contentType = !empty($_SERVER['CONTENT_TYPE'])
            ? $_SERVER['CONTENT_TYPE']
            : null;

        switch ($contentType) {
            case 'application/x-www-form-urlencoded':
                $payload = \json_decode($this->getParam('payload'), true);
                break;
            case 'application/json':
            default:
                $payload = \json_decode(\file_get_contents('php://input'), true);
        }

        // Handle Push web hooks:
        if (\array_key_exists('commits', $payload)) {
            return $this->gogsCommitRequest($project, $payload);
        }

        if (\array_key_exists('pull_request', $payload)) {
            return $this->gogsPullRequest($project, $payload);
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Gogs sends a commit webhook.
     *
     * @param Project $project
     * @param array   $payload
     *
     * @return array
     */
    protected function gogsCommitRequest(Project $project, array $payload): array
    {
        if (isset($payload['commits']) && \is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:
            $results = [];
            $status  = 'failed';
            foreach ($payload['commits'] as $commit) {
                try {
                    $branch = \str_replace('refs/heads/', '', $payload['ref']);
                    $committer = $commit['author']['email'];
                    $results[$commit['id']] = $this->createBuild(
                        Build::SOURCE_WEBHOOK_PUSH,
                        $project,
                        $commit['id'],
                        $branch,
                        null,
                        $committer,
                        $commit['message']
                    );
                    $status = 'ok';
                } catch (Exception $ex) {
                    $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }

            return ['status' => $status, 'commits' => $results];
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Gogs sends a pull request webhook.
     *
     * @param Project $project
     * @param array   $payload
     *
     * @return array
     *
     * @throws InvalidArgumentException
     */
    protected function gogsPullRequest(Project $project, array $payload): array
    {
        $pullRequest = $payload['pull_request'];
        $headBranch  = $pullRequest['head_branch'];

        $action          = $payload['action'];
        $activeActions   = ['opened', 'reopened', 'label_updated', 'label_cleared'];
        $inactiveActions = ['closed'];

        $state          = $pullRequest['state'];
        $activeStates   = ['open'];
        $inactiveStates = ['closed'];

        if (!\in_array($action, $activeActions) && !\in_array($action, $inactiveActions)) {
            return ['status' => 'ignored', 'message' => 'Action ' . $action . ' ignored'];
        }
        if (!\in_array($state, $activeStates) && !\in_array($state, $inactiveStates)) {
            return ['status' => 'ignored', 'message' => 'State ' . $state . ' ignored'];
        }

        $envs = [];

        // Get environment form labels
        if (\in_array($action, $activeActions) && \in_array($state, $activeStates)) {
            if (isset($pullRequest['labels']) && \is_array($pullRequest['labels'])) {
                foreach ($pullRequest['labels'] as $label) {
                    if (\strpos($label['name'], 'env:') === 0) {
                        $envs[] = \substr($label['name'], 4);
                    }
                }
            }
        }

        $envsUpdated = [];
        $envObjects  = $project->getEnvironmentsObjects();
        $store       = $this->storeRegistry->get('Environment');
        foreach ($envObjects['items'] as $environment) {
            $branches = $environment->getBranches();
            if (\in_array($environment->getName(), $envs)) {
                if (!\in_array($headBranch, $branches)) {
                    // Add branch to environment
                    $branches[] = $headBranch;
                    $environment->setBranches($branches);
                    $store->save($environment);
                    $envsUpdated[] = $environment->getId();
                }
            } else {
                if (\in_array($headBranch, $branches)) {
                    // Remove branch from environment
                    $branches = \array_diff($branches, [$headBranch]);
                    $environment->setBranches($branches);
                    $store->save($environment);
                    $envsUpdated[] = $environment->getId();
                }
            }
        }

        if ('closed' === $state && $pullRequest['merged']) {
            // update base branch environments
            $environmentIds = $project->getEnvironmentsNamesByBranch($pullRequest['base_branch']);
            $envsUpdated    = \array_merge($envsUpdated, $environmentIds);
        }

        $envsUpdated = \array_unique($envsUpdated);
        if (!empty($envsUpdated)) {
            foreach ($envsUpdated as $environmentId) {
                $this->buildService->createBuild(
                    $project,
                    $environmentId,
                    '',
                    $project->getDefaultBranch(),
                    null,
                    null,
                    null,
                    Build::SOURCE_WEBHOOK_PUSH,
                    null,
                    null
                );
            }

            return ['status' => 'ok', 'message' => 'Branch environments updated ' . \implode(', ', $envsUpdated)];
        }

        return ['status' => 'ignored', 'message' => 'Branch environments not changed'];
    }
}
