<?php

namespace Pantheon\LCMDeployCommand\Commands;

use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusProcessException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Deploy.
 */
class LCMDeployCommand extends LcmDrushCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

    /**
     * LCM Deploy script by checking configuration
     *
     * @command lcm-deploy
     * @param $site_dot_env Web site name and environment with dot, example - mywebsite.test
     * @option force-deploy Run terminus lcm-deploy <site>.<env> --force-deploy to force deployment
     * @option no-cim Run terminus lcm-deploy <site>.<env> --no-cim to deploy without configuration import
     * @option with-updates Run terminus lcm-deploy <site>.<env> --with-update to run update scripts
     * @option clear-env-caches Run terminus lcm-deploy <site>.<env> --clear-env-caches to run update scripts
     */
    public function checkAndDeploy(
        $site_dot_env,
        $options = [
                                     'force-deploy' => false,
                                     'no-cim' => false,
                                     'with-updates' => false,
                                     'clear-env-caches' => false
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
        $previus_env = $this->getPreviusEnv($environment_name);

        $this->log()->notice(
            "You are going to deploy code from {previus_env} environment to {env_name} environment.\n",
            ['env_name' => $environment_name, 'previus_env' => $previus_env ]
        );

        if (!$this->environment->hasDeployableCode()) {
            $this->log()->notice('There is nothing to deploy.');
            return;
        }

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
        if ($this->confirm(
            "Do you need to backup $environment_name environment before deployment? (It will backup files and DB only)\n"
        )
        ) {
            $this->backupEnvironment();
        }

        // TODO: Deploy code here - env:deploy

        // Running Configuration import after deployment.
        if (empty($options['no-cim'])) {
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
}
