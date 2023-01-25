<?php

namespace Pantheon\LCMDeployCommand\Commands;

use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusProcessException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Commands\StructuredListTrait;

use Pantheon\LCMDeployCommand\Slack;
use SlackPhp\BlockKit\Blocks\Divider;
use SlackPhp\BlockKit\Blocks\Section;
use SlackPhp\BlockKit\Surfaces\Message;

/**
 * Class LCM Deploy Command.
 */
class LCMDeployCommand extends LcmDrushCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;
    use StructuredListTrait;

  /**
  * Determine if it is safe to deploy.
  *
  * @command lcm-deploy:check-config
  */
    public function checkConfig($site_dot_env, $throw = true)
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
     * @command lcm-deploy:deploy
     * @alias lcm-deploy
     *
     * @param $site_dot_env Web site name and environment with dot, example - mywebsite.test
     * @option string $force-deploy Run terminus lcm-deploy <site>.<env> --force-deploy to force deployment.
     * @option string $with-cim Run terminus lcm-deploy <site>.<env> --with-cim to deploy with configuration import.
     * @option string $with-updates Run terminus lcm-deploy <site>.<env> --with-update to run update scripts.
     * @option string $clear-env-caches Run terminus lcm-deploy <site>.<env> --clear-env-caches to run update scripts.
     * @option string $with-backup Add --with-backup to backup before deployment.
     * db and source codes before deployment.
     * @option string $deploy-message Add --deploy-message="YOUR MESSAGE" to add deployment note message.
     *
     *
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

        if (!$this->isDeployable()) {
            $this->fail('There is no code to deploy.', true);
        }

        try {
            $this->checkConfig($site_dot_env, !$options['force-deploy']);
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
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }

        if ($options['slack-alert']) {
            $this->succeed($options['deploy-message']);
        }
    }

  /**
   * Helper function for deployment.
   *
   * @param $deploy_message
   * @return void
   * @throws TerminusException
   */
    private function deployToEnv($deploy_message)
    {
        $annotation = $deploy_message;
        if ($this->environment->isInitialized()) {
            $params = [
              'updatedb'    => false,
              'annotation'  => $annotation,
            ];
            $workflow = $this->environment->deploy($params);
        } else {
            $workflow = $this->environment->initializeBindings(compact('annotation'));
        }
        $this->processWorkflow($workflow);
        $this->log()->notice($workflow->getMessage());
    }

  /**
   * Check that environment can be deployed to.
   */
    private function isDeployable()
    {
        return $this->environment->hasDeployableCode();
    }

  /**
   * Get Previous Environment.
   *
   * @param $current_env
   * @return string
   * @throws TerminusProcessException
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
    private function backupEnvironment()
    {
        $params = [
        'code'       => true,
        'database'   => true,
        'files'      => false,
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
                ephemeral: false,
                blocks: [
                  new Section("🚨Deployment failed: *$site_name* - $source_environment ➤ $target_environment"),
                  new Section("Reason: `$reason`"),
                  new Divider(),
                  new Section("Initiated by: {$this->getUserName()}")
                ]
            );
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
                ephemeral: false,
                blocks: [
                new Section("✅ Deployment completed: *$site_name* - $source_environment ➤ $target_environment"),
                new Section("Message: `$message`"),
                new Divider(),
                new Section("Initiated by: {$this->getUserName()}")
                ]
            );
            $this->postToSlack($msg);
        }
    }

  /**
   * Post to slack.
   */
    private function postToSlack(Message $message)
    {
        $slack = new Slack();
        $slack->post($message->toJson());
    }
}
