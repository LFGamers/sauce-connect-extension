<?php

/**
 * This file is part of sauce-connect-extension
 *
 * (c) Looking For Gamers, Inc
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE
 */

namespace LFG\Codeception\Extension;

use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Platform\Extension;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * @author Aaron Scherer <aequasi@gmail.com>
 */
class SauceConnectExtension extends Extension
{
    /**
     * @var array $events
     */
    public static $events = [
        'test.before'  => 'beforeTest'
    ];
    /**
     * @var Process $process
     */
    protected $process;

    /**
     * {@inheritDoc}
     *
     * Starts the connection
     */
    public function _reconfigure($config = array())
    {
        parent::_reconfigure($config);

        if (!isset($this->config['username'])) {
            throw new \Exception("Sauce Connect Extension requires a username.");
        }
        if (!isset($this->config['accesskey'])) {
            throw new \Exception("Sauce Connect Extension requires a accesskey.");
        }

        $processBuilder = new ProcessBuilder(__DIR__.'/../../../bin/sauce_connect']);
        $processBuilder->addEnvironmentVariables(
            [
                'SAUCE_USERNAME'   => $this->config['username'],
                'SAUCE_ACCESS_KEY' => $this->config['accesskey'],
            ]
        );

        $timeout = isset($this->config['timeout']) ? $this->config['timeout'] : 60;

        $this->process = $processBuilder->getProcess();
        $this->process->setTimeout(0);
        $this->process->start(
            function ($type, $buffer) {
                $buffer = explode("\n", $buffer);
                foreach ($buffer as $line) {
                    if (strpos($line, 'Press any key to see more output') === false) {
                        file_put_contents(codecept_output_dir().'/sauce_connect.log', $line."\n", FILE_APPEND);
                    }
                }
            }
        );

        $timer     = 0;
        $connected = false;
        $this->writeln(
            [
                "",
                "----------------------------------------------------------------------------",
                "Attempting to connect to SauceLabs. Waiting {$timeout} seconds."
            ]
        );
        while ($this->process->isRunning() && $timer < $timeout) {
            $output = $this->process->getOutput();
            if (strpos($output, 'Connected! You may start your tests.') !== false) {
                $connected = true;
                break;
            }
            sleep(1);
            $timer++;
            if ($timer % 5 === 0) {
                $this->write('.');
            }
        }

        if (false === $connected) {
            $this->process->stop();
            throw new \Exception(
                sprintf(
                    "Could not start tunnel. Check %s/sauce_connect.log for more information.",
                    codecept_root_dir()
                )
            );
        }

        $this->writeln(
            [
                "",
                "Connected to SauceLabs",
                "----------------------------------------------------------------------------",
                ""
            ]
        );
    }

    /**
     * Kills the connection
     */
    public function __destruct()
    {
        if ($this->output->isVerbose()) {
            $this->writeln("Closing SauceLabs tunnel.");
        }
        if (null !== $this->process) {
            $this->process->stop();
        }
    }


    public function beforeTest(TestEvent $event)
    {
        if (!$this->process->isRunning()) {
            return;
        }

        /** @var \RemoteWebDriver $driver */
        $driver = $this->getModule('WebDriver')->webDriver;
        $this->writeln(
            [
                "\n",
                "SauceLabs Connect Info: ",
                sprintf(
                    'SauceOnDemandSessionID=%s job-name=%s',
                    $driver->getSessionID(),
                    $event->getTest()->getName()
                ),
                ""
            ]
        );
    }
}
