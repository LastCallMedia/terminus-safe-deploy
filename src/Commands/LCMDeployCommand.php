<?php
/**
 * This simple command shows the basics of how to add a new top-level command to Terminus.
 *
 * To add a command simply define a class as a subclass of `Pantheon\Terminus\Commands\TerminusCommand` and place it in
 * a php file inside the 'Commands' directory inside your plugin directory. The file and command class should end with
 * 'Command' in order to be found by Terminus.
 *
 * To specify what the command name should be use the `@command` tag in the actual command function DocBlock.
 *
 * This command can be invoked by running `terminus hello`
 */

/**
 * Plugins which are to be distributed should define their own namespace in order to avoid conflicts. To do so, use
 * the PSR-4 standard and add an autoload section to your composer.json.
 *
 * Development or internal-only plugins can ommit the namespace declaration and the autoload section in composer.json.
 * The command will then use the global namespace.
 */
namespace Pantheon\LCMDeployCommand\Commands;

/**
 * It is not strictly necessary to extend the TerminusCommand class but doing so causes a number of helpful
 * objects (logger, session, etc) to be automatically provided to your class by the dependency injection container.
 */
use \Pantheon\Terminus\Commands\TerminusCommand;

/**
 * This can provide executeCommand function
 */
use \Pantheon\Terminus\Commands\Remote\SSHBaseCommand;

use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusProcessException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Say hello to the user
 */
class LCMDeployCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;


    /**
     * LCM Deploy script by checking configuration
     *
     * @command lcm-deploy
     * @param $site_dot_env Web site name and environment with dot, example - mywebsite.test .
     */
    public function checkAndDeploy($site_dot_env = '', $options = ['force-deploy' => false])
    {
        if (empty($site_dot_env)) {
          throw new TerminusProcessException(
            "Website and Environment field is empty.\n type 'terminus lcm-deploy --help' for help"
          );
        }

        // Check environment is frozen or not.
        $this->requireSiteIsNotFrozen($site_dot_env);

        // Getting environment object.
        $environment = $this->getEnv($site_dot_env);
        $site = $this->getSite($site_dot_env);

        // TODO: uncomment
//        if (!$environment->hasDeployableCode()) {
//          $this->log()->notice('There is nothing to deploy.');
//          return;
//        }

        [$site, $env_name] = explode('.', $site_dot_env);
        if(!$this->getNextEnv($env_name)) {
          throw new TerminusProcessException("Website with $env_name Environment is not correct.\n");
        }
        $next_env = $this->getNextEnv($env_name);
        $this->log()->notice(
          "You are going to deploy code from {env_name} environment to {next_env} environment.\n",
          ['env_name' => $environment->getName(), 'next_env' => $next_env ]
        );

        $this->executeCommand('drush updb'));


        //$this->processWorkflow



          if (!$this->confirm(
            "You are going to deploy code from $env_name Environment to $next_env,\n Are you sure you want to continue?")
          ) {
            return;
          }

      /*
       * Clear environment caches
       */
    //  $this->processWorkflow($env->clearCache());
        $this->log()->notice(
            'Caches cleared on {env}.',
            ['env' => $environment->getName()]
        );
    }

  /**
   * Get Next environment.
   *
   * @param String $current_env
   * @return String
   */
    private function getNextEnv($current_env) {
      // TODO: make it more generic.
      $env = [
      'dev' => 'test',
      'test' => 'live',
      ];

      if (array_key_exists($current_env, $env)) {
          return $env[$current_env];
      }
      return false;
    }
}
