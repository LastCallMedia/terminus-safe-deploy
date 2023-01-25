<?php

namespace LastCall\TerminusSafeDeploy\Commands;

use LastCall\TerminusSafeDeploy\Slack;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusProcessException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Commands\StructuredListTrait;
use SlackPhp\BlockKit\Blocks\Context;
use SlackPhp\BlockKit\Blocks\Section;
use SlackPhp\BlockKit\Surfaces\Message;

/**
 * Creates command for deploying Pantheon sites safely.
 */
class SafeDeployCommand extends ProtectedDrushCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;
    use StructuredListTrait;

    /**
     * Determine if it is safe to deploy.
     *
     * @command safe-deploy:check-config
     */
    public function doCheckConfig($site_dot_env, $throw = true)
    {
        // Before Deployment check are there any Configuration changes.
        $this->LCMPrepareEnvironment($site_dot_env);
        $this->requireSiteIsNotFrozen($site_dot_env);

        // If there is a config override, then rerun the command to output the
        // status.
        if ($this->sendCommandViaSshAndParseJsonOutput('drush cst')) {
            $this->drushCommand($site_dot_env, ['cst']);
            if ($throw) {
                throw new TerminusProcessException("There is overridden configuration on the target environment. Deploying is not automatically considered safe.");
            } else {
                $this->log()->warning('Flagging as safe, even through there is overridden configuration.');
            }
        } else {
            $this->log()->notice('Configuration is in sync on target environment.');
        }
    }

    /**
     * LCM Deploy script by checking configuration
     *
     * @command safe-deploy:deploy
     *
     * @param $site_dot_env Web site name and environment with dot, example - mywebsite.test
     * @option string $force-deploy Run terminus lcm-deploy <site>.<env> --force-deploy to force deployment.
     * @option string $with-cim Run terminus lcm-deploy <site>.<env> --with-cim to deploy with configuration import.
     * @option string $with-updates Run terminus lcm-deploy <site>.<env> --with-update to run update scripts.
     * @option string $clear-env-caches Run terminus lcm-deploy <site>.<env> --clear-env-caches to run update scripts.
     * @option string $with-backup Add --with-backup to back up before deployment.
     * db and source codes before deployment.
     * @option string $deploy-message Add --deploy-message="YOUR MESSAGE" to add deployment note message.
     * @option string $slack-alert Add --slack-alert to notify slack about pass/failure.
     *
     */
    public function doCheckAndDeploy(
        $site_dot_env,
        $options = [
            'force-deploy' => false,
            'with-cim' => false,
            'with-updates' => false,
            'clear-env-caches' => false,
            'with-backup' => false,
            'deploy-message' => 'Deploy from Terminus by lcm-deploy',
            'slack-alert' => false,
        ]
    ) {
        try {
            $this->checkAndDeploy($site_dot_env, $options);
        } catch (\Exception $e) {
            $this->fail($e->getMessage(), $options['slack-alert']);
        }
        $this->succeed($options['deploy-message'], $options['slack-alert']);
    }

    /**
     * Deploy on Pantheon with optional steps.
     */
    public function checkAndDeploy(
        $site_dot_env,
        $options = [
            'force-deploy' => false,
            'with-cim' => false,
            'with-updates' => false,
            'clear-env-caches' => false,
            'with-backup' => false,
            'deploy-message' => 'Deploy from Terminus by lcm-deploy',
            'slack-alert' => false,
        ]
    ) {
        $this->LCMPrepareEnvironment($site_dot_env);
        $this->requireSiteIsNotFrozen($site_dot_env);

        $environment_name = $this->environment->getName();
        $previous_env = $this->getPreviousEnv($environment_name);

        $this->log()->notice(
            "Deploying {site_name} from {previous_env} to {env_name}.",
            [
                'site_name' => $this->environment->getSite()->getName(),
                'previous_env' => $previous_env,
                'env_name' => $environment_name
            ]
        );

        if (!$this->environment->hasDeployableCode()) {
            throw new TerminusProcessException('There is no code to deploy.');
        }

        // Check configuration and optionally continue if command is forcing despite overridden configuration.
        $this->doCheckConfig($site_dot_env, !$options['force-deploy']);

        // Optionally create backup prior to deployment.
        if ($options['with-backup']) {
            $this->backupEnvironment();
        }

        // Do the actual deployment.
        $this->deployToEnv($options['deploy-message']);

        // Optionally run Configuration import.
        if ($options['with-cim']) {
            $this->log()->notice('Clearing Drupal cache on target environment.');
            $this->drushCommand($site_dot_env, ['cache-rebuild']);
            $this->log()->notice('Importing configuration on target environment.');
            $this->drushCommand($site_dot_env, ['config-import', '-y']);
        }

        // Optionally run DB updates.
        if ($options['with-updates']) {
            $this->log()->notice('Running database updates.');
            $this->drushCommand($site_dot_env, ['updb', '-y']);
        }

        // Clear cache.
        $this->log()->notice('Clearing Drupal caches.');
        $this->drushCommand($site_dot_env, ['cache-rebuild']);

        // Optionally clear environment caches.
        if ($options['clear-env-caches']) {
            $this->processWorkflow($this->environment->clearCache());
            $this->log()->notice(
                'Environment caches cleared on {env}.',
                ['env' => $this->environment->getName()]
            );
        }
    }

    /**
     * Run deployment.
     */
    private function deployToEnv($deploy_message)
    {
        if ($this->environment->isInitialized()) {
            $params = [
                'updatedb'    => false,
                'annotation'  => $deploy_message,
            ];
            $workflow = $this->environment->deploy($params);
        } else {
            $workflow = $this->environment->initializeBindings(['annotation' => $deploy_message]);
        }
        $this->processWorkflow($workflow);
        $this->log()->notice($workflow->getMessage());
    }

    /**
     * Get Previous Environment.
     */
    private function getPreviousEnv($current_env)
    {
        // TODO: make it more generic.
        $env = [
            'test' => 'dev',
            'live' => 'test',
        ];

        if (array_key_exists($current_env, $env)) {
            return $env[$current_env];
        }
        throw new TerminusProcessException("Website with $current_env Environment is not correct.\n");
    }

    /**
     * Backup Environment.
     * @return void
     * @throws TerminusException
     */
    private function backupEnvironment($code = true, $database = true, $files = false)
    {
        $params = [
            'code'       => $code,
            'database'   => $database,
            'files'      => $files,
            'entry_type' => 'backup',
        ];
        $this->processWorkflow(
            $this->environment->getWorkflows()->create('do_export', compact('params'))
        );
        $this->processWorkflow($this->environment->getBackups()->create(['element' => 'code']));
        $this->log()->notice(
            'Created a backup of the {env} environment.',
            ['env' => $this->environment->getName()]
        );
    }

    /**
     * Get username.
     */
    private function getUserName()
    {
        $user = $this->session()->getUser()->fetch();
        return $user->getName();
    }

    /**
     * Fail with option to notify to slack.
     */
    private function fail($reason, $notify = false)
    {
        if ($notify) {
            $site_name = $this->environment->getSite()->getName();
            $target_environment = $this->environment->getName();
            $source_environment = $this->getPreviousEnv($target_environment);
            $msg = new Message(
                blocks: [
                    new Context(['text' => "🚨Deployment failed: *$site_name* - $source_environment ➤ $target_environment"]),
                    new Section("Reason: `$reason`"),
                    new Context(['text' => "Initiated by: {$this->getUserName()}"]),
                ],
                ephemeral: false,
            );
            if ($link = getenv('SLACK_MESSAGE_CONTEXT_LINK')) {
                $msg->blocks->append(new Context(['text' => $link]));
            }
            $this->postToSlack($msg);
        }
        throw new TerminusProcessException($reason);
    }

    /**
     * Success with option to notify slack.
     */
    private function succeed($message, $notify = false)
    {
        if ($notify) {
            $site_name = $this->environment->getSite()->getName();
            $target_environment = $this->environment->getName();
            $source_environment = $this->getPreviousEnv($target_environment);

            $msg = new Message(
                blocks: [
                    new Context(['text' => "✅ Deployment completed: *$site_name* - $source_environment ➤ $target_environment"]),
                    new Section("Message: `$message`"),
                    new Context(['text' => "Initiated by: {$this->getUserName()}"])
                ],
                ephemeral: false,
            );
            if ($link = getenv('SLACK_MESSAGE_CONTEXT_LINK')) {
                $msg->blocks->append(new Context(['text' => $link]));
            }

            $this->postToSlack($msg);
        }
    }

    /**
     * Post to slack.
     */
    private function postToSlack(Message $message)
    {
        $url = getenv('SLACK_URL');
        Slack::send($message, $url);
    }
}
