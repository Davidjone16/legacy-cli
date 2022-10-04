<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Integration;
use Platformsh\Client\Model\Project;
use Platformsh\ConsoleForm\Exception\ConditionalFieldException;
use Platformsh\ConsoleForm\Field\ArrayField;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\EmailAddressField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\FileField;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Field\UrlField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Output\OutputInterface;

abstract class IntegrationCommandBase extends CommandBase
{
    protected $activityService;
    protected $api;
    protected $config;
    protected $formatter;
    protected $loader;
    protected $localProject;
    protected $selector;
    protected $questionHelper;
    protected $table;

    /** @var Form */
    private $form;

    /** @var array */
    private $bitbucketAccessTokens = [];

    public function __construct(
        ActivityService $activityService,
        ActivityLoader $activityLoader,
        Api $api,
        Config $config,
        LocalProject $localProject,
        PropertyFormatter $formatter,
        Selector $selector,
        QuestionHelper $questionHelper,
        Table $table)
    {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->loader = $activityLoader;
        $this->localProject = $localProject;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * @param Project $project
     * @param string|null $id
     * @param bool $interactive
     *
     * @return Integration|false
     */
    protected function selectIntegration(Project $project, $id, $interactive) {
        if (!$id && !$interactive) {
            $this->stdErr->writeln('An integration ID is required.');

            return false;
        } elseif (!$id) {
            $integrations = $project->getIntegrations();
            if (empty($integrations)) {
                $this->stdErr->writeln('No integrations found.');

                return false;
            }
            $choices = [];
            foreach ($integrations as $integration) {
                $choices[$integration->id] = sprintf('%s (%s)', $integration->id, $integration->type);
            }
            $id = $this->questionHelper->choose($choices, 'Enter a number to choose an integration:');
        }

        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return false;
            }
        }

        return $integration;
    }

    /**
     * @return Form
     */
    protected function getForm()
    {
        if (!isset($this->form)) {
            $this->form = Form::fromArray($this->getFields());
        }

        return $this->form;
    }

    /**
     * @param ConditionalFieldException $e
     *
     * @return int
     */
    protected function handleConditionalFieldException(ConditionalFieldException $e)
    {
        $previousValues = $e->getPreviousValues();
        $field = $e->getField();
        $conditions = $field->getConditions();
        if (isset($previousValues['type']) && isset($conditions['type']) && !in_array($previousValues['type'], (array) $conditions['type'])) {
            $this->stdErr->writeln(\sprintf(
                'The option <error>--%s</error> cannot be used with the integration type <comment>%s</comment>.',
                $field->getOptionName(),
                $previousValues['type']
            ));
            return 1;
        }
        throw $e;
    }

    /**
     * Performs extra logic on values after the form is complete.
     *
     * @param array            $values
     * @param Integration|null $integration
     *
     * @return array
     */
    protected function postProcessValues(array $values, Integration $integration = null)
    {
        // Find the integration type.
        $type = isset($values['type'])
            ? $values['type']
            : ($integration !== null ? $integration->type : null);

        // Process Bitbucket Server values.
        if ($type === 'bitbucket_server') {
            // Translate base_url into url.
            if (isset($values['base_url'])) {
                $values['url'] = $values['base_url'];
                unset($values['base_url']);
            }
            // Split bitbucket_server "repository" into project/repository.
            if (isset($values['repository']) && strpos($values['repository'], '/', 1) !== false) {
                [$values['project'], $values['repository']] = explode('/', $values['repository'], 2);
            }
        }

        return $values;
    }

    /**
     * @return Field[]
     */
    private function getFields()
    {
        return [
            'type' => new OptionsField('Integration type', [
                'optionName' => 'type',
                'description' => 'The integration type',
                'questionLine' => '',
                'options' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                    'gitlab',
                    'webhook',
                    'health.email',
                    'health.pagerduty',
                    'health.slack',
                    'health.webhook',
                    'script',
                ],
            ]),
            'base_url' => new UrlField('Base URL', [
                'conditions' => ['type' => [
                    'gitlab',
                    'bitbucket_server',
                ]],
                'description' => 'The base URL of the server installation',
            ]),
            'username' => new Field('Username', [
                'conditions' =>  ['type' => [
                    'bitbucket_server',
                ]],
                'description' => 'The Bitbucket Server username',
            ]),
            'token' => new Field('Token', [
                'conditions' => ['type' => [
                    'github',
                    'gitlab',
                    'health.slack',
                    'bitbucket_server',
                ]],
                'description' => 'An access token for the integration',
            ]),
            'key' => new Field('OAuth consumer key', [
                'optionName' => 'key',
                'conditions' => ['type' => [
                    'bitbucket',
                ]],
                'description' => 'A Bitbucket OAuth consumer key',
                'valueKeys' => ['app_credentials', 'key'],
            ]),
            'secret' => new Field('OAuth consumer secret', [
                'optionName' => 'secret',
                'conditions' => ['type' => [
                    'bitbucket',
                ]],
                'description' => 'A Bitbucket OAuth consumer secret',
                'valueKeys' => ['app_credentials', 'secret'],
            ]),
            'project' => new Field('Project', [
                'optionName' => 'server-project',
                'conditions' => ['type' => [
                    'gitlab',
                ]],
                'description' => 'The project (e.g. \'namespace/repo\')',
                'validator' => function ($string) {
                    return strpos($string, '/', 1) !== false;
                },
            ]),
            'repository' => new Field('Repository', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                ]],
                'description' => 'The repository to track (e.g. \'owner/repository\')',
                'questionLine' => 'The repository (e.g. \'owner/repository\')',
                'validator' => function ($string) {
                    return substr_count($string, '/', 1) === 1;
                },
                'normalizer' => function ($string) {
                    if (preg_match('#^https?://#', $string)) {
                        return parse_url($string, PHP_URL_PATH);
                    }

                    return $string;
                },
            ]),
            'build_merge_requests' => new BooleanField('Build merge requests', [
                'conditions' => ['type' => [
                    'gitlab',
                ]],
                'description' => 'GitLab: build merge requests as environments',
                'questionLine' => 'Build every merge request as an environment',
            ]),
            'build_pull_requests' => new BooleanField('Build pull requests', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                ]],
                'description' => 'Build every pull request as an environment',
            ]),
            'build_draft_pull_requests' => new BooleanField('Build draft pull requests', [
                'conditions' => [
                    'type' => [
                        'github',
                    ],
                    'build_pull_requests' => true,
                ],
            ]),
            'build_pull_requests_post_merge' => new BooleanField('Build pull requests post-merge', [
              'conditions' => [
                'type' => [
                  'github',
                ],
                'build_pull_requests' => true,
              ],
              'default' => false,
              'description' => 'Build pull requests based on their post-merge state',
            ]),
            'build_wip_merge_requests' => new BooleanField('Build WIP merge requests', [
                'conditions' => [
                    'type' => [
                        'gitlab',
                    ],
                    'build_merge_requests' => true,
                ],
                'description' => 'GitLab: build WIP merge requests',
                'questionLine' => 'Build WIP (work in progress) merge requests',
            ]),
            'merge_requests_clone_parent_data' => new BooleanField('Clone data for merge requests', [
                'optionName' => 'merge-requests-clone-parent-data',
                'conditions' => [
                    'type' => [
                        'gitlab',
                    ],
                    'build_merge_requests' => true,
                ],
                'description' => 'GitLab: clone data for merge requests',
                'questionLine' => "Clone the parent environment's data for merge requests",
            ]),
            'pull_requests_clone_parent_data' => new BooleanField('Clone data for pull requests', [
                'optionName' => 'pull-requests-clone-parent-data',
                'conditions' => [
                    'type' => [
                        'github',
                        'bitbucket_server',
                    ],
                    'build_pull_requests' => true,
                ],
                'description' => "Clone the parent environment's data for pull requests",
            ]),
            'resync_pull_requests' => new BooleanField('Re-sync pull requests', [
                'optionName' => 'resync-pull-requests',
                'conditions' => [
                    'type' => [
                        'bitbucket',
                    ],
                    'build_pull_requests' => true,
                ],
                'default' => false,
                'description' => "Re-sync pull request environment data on every build",
            ]),
            'fetch_branches' => new BooleanField('Fetch branches', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                    'gitlab',
                ]],
                'description' => 'Fetch all branches from the remote (as inactive environments)',
            ]),
            'prune_branches' => new BooleanField('Prune branches', [
                'conditions' => [
                    'type' => [
                        'bitbucket',
                        'bitbucket_server',
                        'github',
                        'gitlab',
                    ],
                    'fetch_branches' => true,
                ],
                'description' => 'Delete branches that do not exist on the remote',
            ]),
            'url' => new UrlField('URL', [
                'conditions' => ['type' => [
                    'health.webhook',
                    'webhook',
                ]],
                'description' => 'Webhook: a URL to receive JSON data',
                'questionLine' => 'What is the webhook URL (to which JSON data will be posted)?',
            ]),
            'shared_key' => new Field('Shared key', [
                'conditions' => ['type' => [
                    'health.webhook',
                    'webhook',
                ]],
                'description' => 'Webhook: the JWS shared secret key',
                'questionLine' => 'Optionally, enter a JWS shared secret key, for validating webhook requests',
                'required' => false,
            ]),
            'script' => new FileField('Script file', [
                'conditions' => ['type' => [
                    'script',
                ]],
                'optionName' => 'file',
                'allowedExtensions' => ['.js', ''],
                'contentsAsValue' => true,
                'description' => 'The name of a local file that contains the script to upload',
                'normalizer' => function ($value) {
                    if (getenv('HOME') && strpos($value, '~/') === 0) {
                        return getenv('HOME') . '/' . substr($value, 2);
                    }

                    return $value;
                },
            ]),
            'events' => new ArrayField('Events', [
                'conditions' => ['type' => [
                    'webhook',
                    'script',
                ]],
                'default' => ['*'],
                'description' => 'A list of events to act on, e.g. environment.push',
                'optionName' => 'events',
            ]),
            'states' => new ArrayField('States', [
                'conditions' => ['type' => [
                    'webhook',
                    'script',
                ]],
                'default' => ['complete'],
                'description' => 'A list of states to act on, e.g. pending, in_progress, complete',
                'optionName' => 'states',
            ]),
            'environments' => new ArrayField('Included environments', [
                'optionName' => 'environments',
                'conditions' => ['type' => [
                    'webhook',
                    'script',
                ]],
                'default' => ['*'],
                'description' => 'The environment IDs to include',
            ]),
            'excluded_environments' => new ArrayField('Excluded environments', [
                'conditions' => ['type' => [
                    'webhook',
                ]],
                'default' => [],
                'description' => 'The environment IDs to exclude',
                'required' => false,
            ]),
            'from_address' => new EmailAddressField('From address', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => '[Optional] Custom From address for alert emails',
                'default' => $this->config->getWithDefault('service.default_from_address', null),
                'required' => false,
            ]),
            'recipients' => new ArrayField('Recipients', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => 'The recipient email address(es)',
                'validator' => function ($emails) {
                    $invalid = array_filter($emails, function ($email) {
                        // The special placeholders #viewers and #admins are
                        // valid recipients.
                        if (in_array($email, ['#viewers', '#admins'])) {
                            return false;
                        }

                        return !filter_var($email, FILTER_VALIDATE_EMAIL);
                    });
                    if (count($invalid)) {
                        return sprintf('Invalid email address(es): %s', implode(', ', $invalid));
                    }

                    return true;
                },
            ]),
            'channel' => new Field('Channel', [
                'conditions' => ['type' => [
                    'health.slack',
                ]],
                'description' => 'The Slack channel',
            ]),
            'routing_key' => new Field('Routing key', [
                'conditions' => ['type' => [
                    'health.pagerduty',
                ]],
                'description' => 'The PagerDuty routing key',
            ]),
        ];
    }

    /**
     * @param Integration     $integration
     */
    protected function displayIntegration(Integration $integration)
    {
        $info = [];
        foreach ($integration->getProperties() as $property => $value) {
            $info[$property] = $this->formatter->format($value, $property);
        }
        if ($integration->hasLink('#hook')) {
            $info['hook_url'] = $this->formatter->format($integration->getLink('#hook'));
        }

        $this->table->renderSimple(array_values($info), array_keys($info));
    }

    /**
     * Obtain an OAuth2 token for Bitbucket from the given app credentials.
     *
     * @param array $credentials
     *
     * @return string
     */
    protected function getBitbucketAccessToken(array $credentials)
    {
        if (isset($this->bitbucketAccessTokens[$credentials['key']])) {
            return $this->bitbucketAccessTokens[$credentials['key']];
        }
        $result = $this->api
            ->getExternalHttpClient()
            ->post('https://bitbucket.org/site/oauth2/access_token', [
                'auth' => [$credentials['key'], $credentials['secret']],
                'body' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

        $data = \json_decode($result->getBody()->__toString(), true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Access token not found in Bitbucket response');
        }

        $this->bitbucketAccessTokens[$credentials['key']] = $data['access_token'];

        return $data['access_token'];
    }

    /**
     * Validate Bitbucket credentials.
     *
     * @param array $credentials
     *
     * @return true|string
     */
    protected function validateBitbucketCredentials(array $credentials)
    {
        try {
            $this->getBitbucketAccessToken($credentials);
        } catch (\Exception $e) {
            $message = '<error>Invalid Bitbucket credentials</error>';
            if ($e instanceof BadResponseException && $e->getResponse() && $e->getResponse()->getStatusCode() === 400) {
                $message .= "\n" . 'Ensure that the OAuth consumer key and secret are valid.'
                    . "\n" . 'Additionally, ensure that the OAuth consumer has a callback URL set (even just to <comment>http://localhost</comment>).';
            }

            return $message;
        }

        return TRUE;
    }

    /**
     * Lists validation errors found in an integration.
     *
     * @param array                                             $errors
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function listValidationErrors(array $errors, OutputInterface $output)
    {
        if (count($errors) === 1) {
            $this->stdErr->writeln('The following error was found:');
        } else {
            $this->stdErr->writeln(sprintf(
                'The following %d errors were found:',
                count($errors)
            ));
        }

        foreach ($errors as $key => $error) {
            if (is_string($key) && strlen($key)) {
                $output->writeln("$key: $error");
            } else {
                $output->writeln($error);
            }
        }
    }

    /**
     * Updates the Git remote URL for the current project.
     *
     * @param string $oldGitUrl
     * @param Project $selectedProject
     */
    protected function updateGitUrl(string $oldGitUrl, Project $selectedProject): void
    {
        $project = $this->selector->getCurrentProject();
        $projectRoot = $this->selector->getProjectRoot();
        if (!$project || !$projectRoot || $selectedProject->id !== $project->id) {
            return;
        }
        $project->refresh();
        $newGitUrl = $project->getGitUrl();
        if ($newGitUrl === $oldGitUrl) {
            return;
        }
        $this->stdErr->writeln(sprintf('Updating Git remote URL from %s to %s', $oldGitUrl, $newGitUrl));
        $this->localProject->ensureGitRemote($projectRoot, $newGitUrl);
    }
}
