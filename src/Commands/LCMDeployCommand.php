<?php

namespace Pantheon\LCMDeployCommand\Commands;

use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusProcessException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Commands\StructuredListTrait;
use Pantheon\Terminus\Commands\Env\DeployCommand;

use Pantheon\LCMDeployCommand\Model\Slack;

/**
 * Class LCM Deploy Command.
 */
class LCMDeployCommand extends LcmDrushCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;
    use StructuredListTrait;

    /**
     * LCM Deploy script by checking configuration
     *
     * @command lcm-deploy
     * @aliases lcm:deploy
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
                                   ]
    ) {
        if (!$this->session()->isActive()) {
            throw new TerminusException(
                'You are not logged in. Run `auth:login` to authenticate or `help auth:login` for more info.'
            );
        }

        $this->LCMPrepareEnvironment($site_dot_env);
        $this->requireSiteIsNotFrozen($site_dot_env);

        $environment_name = $this->environment->getName();
        $previous_env = $this->getPreviusEnv($environment_name);

        $this->log()->notice(
            "You are going to deploy code from {previous_env} environment to {env_name} environment.\n",
            ['env_name' => $environment_name, 'previous_env' => $previous_env ]
        );

        if (!$this->environment->hasDeployableCode()) {
            $this->log()->notice('There is nothing to deploy.');
            return;
        }

        // After all deployment steps need to clear Drupal cache by Default.
        $this->log()->notice('Clearing Drupal Caches before deployment to avoid db and code resynchronization.');
        $this->drushCommand($site_dot_env, ['cache-rebuild']);

        // Before Deployment check are there any Configuration changes.
        $diff = $this->sendCommandViaSshAndParseJsonOutput('drush cst');

        if ($diff) {
            $this->drushCommand($site_dot_env, ['cst']);
            $this->log()->error(
                'There are configuration differences on {env_name} environment.',
                ['env_name' => $environment_name]
            );
            if (empty($options['force-deploy']) && !$this->confirm(
                "Are you sure you want to CONTINUE?\n"
            )
            ) {
                return;
            }
        } else {
            $this->log()->notice('There are no configuration differences.');
        }

        //Check if backup needed before deployment
        if (!empty($options['with-backup'])) {
            $this->log()->notice(
                'Backup {environment} environment\'s DB and source codes before deployment.',
                ['environment' => $environment_name]
            );
            $this->backupEnvironment();
        }

        // Deploy function.
        $this->deployToEnv($options['deploy-message']);

        // Running Configuration import after deployment.
        if (!empty($options['with-cim'])) {
            $this->log()->notice('Running configuration import...');
            $this->drushCommand($site_dot_env, ['config-import', '-y']);
        }

        // Running Update scripts if option argument is true.
        if (!empty($options['with-updates'])) {
            $this->log()->notice('Running Update scripts...');
            $this->drushCommand($site_dot_env, ['updb', '-y']);
        }

        // After all deployment steps need to clear Drupal cache by Default.
        $this->log()->notice('Clearing Drupal Caches...');
        $this->drushCommand($site_dot_env, ['cache-rebuild']);

        // Check if clear-env-caches option is set, then need to clear also environment caches.
        if (!empty($options['clear-env-caches'])) {
            $this->processWorkflow($this->environment->clearCache());
            $this->log()->notice(
                'Environment caches cleared on {env}.',
                ['env' => $this->environment->getName()]
            );
        }

        $this->slackNotification();
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
   * Get Previous Environment.
   *
   * @param $current_env
   * @return string
   * @throws TerminusProcessException
   */
    private function getPreviusEnv($current_env)
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
      //$this->processWorkflow($this->environment->getBackups()->create(['element' => 'code']));
        $this->log()->notice(
            'Created a backup of the {env} environment.',
            ['env' => $this->environment->getName()]
        );
    }

  /**
   * Slack notification function to send notification that code is deployed.
   *
   * @return void
   * @throws TerminusException
   * @throws TerminusProcessException
   */
    private function slackNotification()
    {
        $environment_name = $this->environment->getName();
        $fields['Terminus Deployment'] = 'Code is Deployed for "' . $this->site->getName()  . '" :yeti-peace:' .
          'Environments: ' . $this->getPreviusEnv($environment_name) . ' :fast_forward: ' . $this->environment->getName();

        $content = [
          'fields' => $fields,
          'divider' => true,
        ];

        $user = $this->session()->getUser();
        $user_model = $user->fetch();
        if (!empty($user_model)) {
            $user_array = $user->serialize();
            if (empty($user_array['firstname']) && !empty($user_array['lastname'])) {
                $content['body'] = $user_array['firstname'] . ' ' . $user_array['lastname'];
            } else {
                $profile = $user_model->getProfile()->serialize();
                if (!empty($profile['full_name'])) {
                    $content['body'] = $profile['full_name'];
                }
            }
            if (!empty($user_array['email'])) {
                $content['body'] .= ', ' . $user_array['email'];
            }
        }

        // TODO: Slack url is hard coded.
        $slack = new Slack('https://hooks.slack.com/services/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');
        $slack->build($content)->post();
    }
}
