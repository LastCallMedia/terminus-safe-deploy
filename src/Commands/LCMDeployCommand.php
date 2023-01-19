<?php

namespace Pantheon\LCMDeployCommand\Commands;

use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
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
     * @param $site_dot_env Web site name and environment with dot, example - mywebsite.test .
     */
    public function checkAndDeploy($site_dot_env, $options = ['force-deploy' => false])
    {

      $this->prepareEnvironment($site_dot_env);
      $this->requireSiteIsNotFrozen($site_dot_env);
      $environment_name = $this->environment->getName();

      $next_env = $this->getNextEnv($environment_name);

      $this->log()->notice(
        "You are going to deploy code from {env_name} environment to {next_env} environment.\n",
        ['env_name' => $environment_name, 'next_env' => $next_env ]
      );

      $diff = $this->sendCommandViaSshAndParseJsonOutput('drush cst --format=json');
      if ($diff) {
        $this->log()->error('There are configuration differences.');
      }
      else {
        $this->log()->notice('There are no configuration differences.');
      }
    }

  private function getNextEnv($current_env) {
      // TODO: make it more generic.
      $env = [
      'dev' => 'test',
      'test' => 'live',
      ];

      if (array_key_exists($current_env, $env)) {
          return $env[$current_env];
      }
      throw new TerminusProcessException("Website with $current_env Environment is not correct.\n");
    }
}
