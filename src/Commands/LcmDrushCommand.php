<?php

namespace Pantheon\LCMDeployCommand\Commands;

use Pantheon\Terminus\Commands\Remote\DrushCommand;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;

class LcmDrushCommand extends DrushCommand
{
    /**
     * @var Site
     */
    protected $site;
    /**
     * @var Environment
     */
    protected $environment;

    /**
     * Sends a command to an environment via SSH and returns json output.
     *
     * @param string $command The command to be run on the platform
     */
    protected function sendCommandViaSshAndParseJsonOutput($command)
    {
        if (!strpos($command, '--format=json')) {
            $command = $command . ' --format=json';
        }

        $ssh_command = $this->getConnectionString() . ' ' . escapeshellarg($command);
        $this->logger->debug('shell command: {command}', [ 'command' => $command ]);

        $a = $this->getContainer()->get(LocalMachineHelper::class)->executeUnbuffered(
            $ssh_command
        );

        return json_decode($a[0]);
    }

    /**
     * @see \Pantheon\Terminus\Commands\Remote\SSHBaseCommand::getConnectionString()
     *
     * This is an exact copy of the method above, but with protected access.
     */
    private function getConnectionString()
    {
        $sftp = $this->environment->sftpConnectionInfo();
        $command = $this->getConfig()->get('ssh_command');

        return vsprintf(
            '%s -T %s@%s -p %s -o "StrictHostKeyChecking=no" -o "AddressFamily inet"',
            [$command, $sftp['username'], $this->lookupHostViaAlternateNameserver($sftp['host']), $sftp['port'],]
        );
    }


    /**
     * @see \Pantheon\Terminus\Commands\Remote\SSHBaseCommand::lookupHostViaAlternativeNameserver()
     *
     * This is an exact copy of the method above, but with protected access.
     */
    protected function lookupHostViaAlternateNameserver(string $host): string
    {
        $alternateNameserver = $this->getConfig()->get('alternate_nameserver');
        if (!$alternateNameserver || !class_exists('\Net_DNS2_Resolver')) {
            return $host;
        }

        // Net_DNS2 requires an IP address for the nameserver; look up the IP from the name.
        $nameserver = gethostbyname($alternateNameserver);
        $r = new \Net_DNS2_Resolver(array('nameservers' => [$nameserver]));
        $result = $r->query($host, 'A');
        foreach ($result->answer as $index => $o) {
            return $o->address;
        }

        return $host;
    }

    /**
     * @see \Pantheon\Terminus\Commands\Remote\SSHBaseCommand::prepareEnvironment()
     *
     * This is an exact copy of the method above, but with protected access.
     */
    protected function LCMPrepareEnvironment($site_env)
    {
        $this->site = $this->getSite($site_env);
        $this->environment = $this->getEnv($site_env);
    }
}
