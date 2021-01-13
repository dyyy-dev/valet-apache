<?php

namespace Valet;

use DomainException;

class Apache
{
    public $brew;
    public $cli;
    public $files;
    public $configuration;
    public $site;
    const APACHE_CONF = '/usr/local/etc/httpd/httpd.conf';

    /**
     * Create a new Nginx instance.
     *
     * @param  Brew $brew
     * @param  CommandLine $cli
     * @param  Filesystem $files
     * @param  Configuration $configuration
     * @param  Site $site
     */
    public function __construct(
        Brew $brew,
        CommandLine $cli,
        Filesystem $files,
        Configuration $configuration,
        Site $site
    ) {
        $this->cli = $cli;
        $this->brew = $brew;
        $this->site = $site;
        $this->files = $files;
        $this->configuration = $configuration;
    }

    /**
     * Install service.
     *
     * @return void
     */
    public function install()
    {
        if (!$this->brew->hasInstalledApache()) {
            $this->brew->installOrFail('httpd');
        }

        $this->disableBuiltIn();
        $this->installConfiguration();
        // $this->installServer();
        $this->installApacheDirectory();

        return $this->configuration->read()['domain'];
    }

    /**
     * Stop and disable the builtin apache
     *
     * @return void
     */
    public function disableBuiltIn()
    {
        $this->cli->quietly(
            'sudo apachectl stop',
            function ($exitCode, $outputMessage) {
                throw new DomainException("Can't stop the builtin Apache [$exitCode: $outputMessage].");
            }
        );

        $this->cli->run(
            'sudo launchctl unload -w /System/Library/LaunchDaemons/org.apache.httpd.plist 2>/dev/null',
            function ($exitCode, $outputMessage) {
                throw new DomainException("Can't unload the builtin Apache [$exitCode: $outputMessage].");
            }
        );
    }

    /**
     * Install the configuration files.
     *
     * @return void
     */
    public function installConfiguration()
    {
        $contents = $this->files->get(__DIR__.'/../stubs/httpd.conf');

        $this->files->putAsUser(
            static::APACHE_CONF,
            str_replace(['VALET_USER', 'VALET_HOME_PATH', 'VALET_SERVER_PATH'], [user(), VALET_HOME_PATH, VALET_SERVER_PATH], $contents)
        );
    }

    /**
     * Install the Valet server configuration file.
     *
     * @return void
     */
    public function installServer()
    {
        $domain = $this->configuration->read()['domain'];

        $this->files->ensureDirExists('/usr/local/etc/nginx/valet');

        $this->files->putAsUser(
            '/usr/local/etc/nginx/valet/valet.conf',
            str_replace(
                ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX'],
                [VALET_HOME_PATH, VALET_SERVER_PATH, VALET_STATIC_PREFIX],
                $this->files->get(__DIR__.'/../stubs/valet.conf')
            )
        );

        $this->files->putAsUser(
            '/usr/local/etc/nginx/fastcgi_params',
            $this->files->get(__DIR__.'/../stubs/fastcgi_params')
        );
    }

    /**
     * Install the configuration directory to the ~/.valet directory.
     *
     * This directory contains all site-specific Apache servers.
     *
     * @return void
     */
    public function installApacheDirectory()
    {
        if (! $this->files->isDir($apacheDirectory = VALET_HOME_PATH.'/Apache')) {
            $this->files->mkdirAsUser($apacheDirectory);
        }

        $this->files->putAsUser($apacheDirectory.'/.keep', "\n");

        $this->rewriteSecureApacheFiles();
    }

    /**
     * Check nginx.conf for errors.
     */
    private function lint()
    {
        $this->cli->quietly(
            'apachectl configtest',
            function ($exitCode, $outputMessage) {
                throw new DomainException("Apache cannot start, please check your httpd.conf [$exitCode: $outputMessage].");
            }
        );
    }

    /**
     * Generate fresh Nginx servers for existing secure sites.
     *
     * @return void
     */
    public function rewriteSecureApacheFiles()
    {
        $domain = $this->configuration->read()['domain'];

        $this->site->resecureForNewDomain($domain, $domain);
    }

    /**
     * Restart the service.
     *
     * @return void
     */
    public function restart()
    {
        $this->lint();

        $this->brew->restartService($this->brew->apacheServiceName());
    }

    /**
     * Stop the service.
     *
     * @return void
     */
    public function stop()
    {
        info('[apache] Stopping');

        $this->cli->quietly('brew services stop '. $this->brew->apacheServiceName());
    }

    /**
     * Prepare for uninstallation.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->stop();
    }
}
