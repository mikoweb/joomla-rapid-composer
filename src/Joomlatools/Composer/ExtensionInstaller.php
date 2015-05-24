<?php
/**
 * Joomlatools Composer plugin - https://github.com/joomlatools/joomla-composer
 *
 * @copyright	Copyright (C) 2011 - 2013 Johan Janssens and Timble CVBA. (http://www.timble.net)
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html>
 * @link		http://github.com/joomlatools/joomla-composer for the canonical source repository
 */

namespace Joomlatools\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Installer\LibraryInstaller;
use \JLog as JLog;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer installer class
 *
 * @author  Steven Rombauts <https://github.com/stevenrombauts>
 * @package Joomlatools\Composer
 */
class ExtensionInstaller extends LibraryInstaller
{
    protected $_config      = null;
    protected $_verbosity   = OutputInterface::VERBOSITY_NORMAL;
    protected $_application = null;
    protected $_credentials = array();

    /**
     * {@inheritDoc}
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library')
    {
        parent::__construct($io, $composer, $type);

        $this->_config = $composer->getConfig();

        if ($io->isDebug()) {
            $this->_verbosity = OutputInterface::VERBOSITY_DEBUG;
        } elseif ($io->isVeryVerbose()) {
            $this->_verbosity = OutputInterface::VERBOSITY_VERY_VERBOSE;
        } elseif ($io->isVerbose()) {
            $this->_verbosity = OutputInterface::VERBOSITY_VERBOSE;
        }

        $this->_initialize();
    }

    /**
     * Initializes extension installer.
     *
     * @return void
     */
    protected function _initialize()
    {
        $credentials = $this->_config->get('joomla');

        if(is_null($credentials) || !is_array($credentials)) {
            $credentials = array();
        }

        $defaults = array(
            'name'      => 'root',
            'username'  => 'root',
            'groups'    => array(8),
            'email'     => 'root@localhost.home');

        $this->_credentials = array_merge($defaults, $credentials);

        $this->_bootstrap();
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $config = $this->_config->get('joomla');
        //return (isset($config['basedir']) ? $config['basedir'] . '/' : '') . 'tmp/' . $package->getPrettyName();
        return 'tmp/' . $package->getPrettyName();
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $this->_setupExtmanSupport($package);

        $this->io->write('    <fg=cyan>Installing</fg=cyan> into Joomla'.PHP_EOL);
        
        $this->_componentPath($package);
        
        try {
            if(!$this->_application->install($this->getInstallPath($package)))
            {
                // Get all error messages that were stored in the message queue
                $descriptions = $this->_getApplicationMessages();

                $error = 'Error while installing '.$package->getPrettyName();
                if(count($descriptions)) {
                    $error .= ':'.PHP_EOL.implode(PHP_EOL, $descriptions);
                }

                throw new \RuntimeException($error);
            }
        } catch(\Exception $exception) {
            $this->io->write('<error>[' . $package->getPrettyName(). ']: '
            . $exception->getMessage() 
            . PHP_EOL . '[file]: ' . $exception->getFile()
            . PHP_EOL . '[line]: ' . $exception->getLine()
            . '</error>' . PHP_EOL);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);

        $this->_setupExtmanSupport($target);

        $this->io->write('    <fg=cyan>Updating</fg=cyan> Joomla extension'.PHP_EOL);
        
        $this->_componentPath($target);
        
        try {
            if(!$this->_application->update($this->getInstallPath($target)))
            {
                // Get all error messages that were stored in the message queue
                $descriptions = $this->_getApplicationMessages();

                $error = 'Error while updating '.$target->getPrettyName();
                if(count($descriptions)) {
                    $error .= ':'.PHP_EOL.implode(PHP_EOL, $descriptions);
                }

                throw new \RuntimeException($error);
            }
        } catch(\Exception $exception) {
            $this->io->write('<error>[' . $target->getPrettyName(). ']: '
            . $exception->getMessage() 
            . PHP_EOL . '[file]: ' . $exception->getFile()
            . PHP_EOL . '[line]: ' . $exception->getLine()
            . '</error>' . PHP_EOL);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'joomlatools-installer';
    }

    /**
     * {@inheritDoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $installer = $this->_application->getInstaller();
        $installer->setPath('source', $this->getInstallPath($package));
    
        try {
            $manifest = $installer->getManifest();
        } catch (\Exception $e) {
            $manifest = null;
        }
    
        if($manifest)
        {
            $type    = (string) $manifest->attributes()->type;
            $element = $this->getElementFromManifest($manifest);
    
            return !empty($element) ? $this->_application->hasExtension($element, $type) : false;
        }
    
        return false;
    }
        
    /**
     * @param PackageInterface $package
     */
    private function _componentPath(PackageInterface $package)
    {
        $installer = $this->_application->getInstaller();
        $installer->setPath('source', $this->getInstallPath($package));
        $manifest = $installer->getManifest();

        if($manifest) {
            $type    = (string) $manifest->attributes()->type;
            $element = $this->getElementFromManifest($manifest);

            if ($type == 'component') {
                // Define component path.
                if(!defined('JPATH_COMPONENT')) {
                    define('JPATH_COMPONENT', JPATH_BASE . '/components/' . $element);
                }
                if(!defined('JPATH_COMPONENT_SITE')) {
                    define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/' . $element);
                }
                if(!defined('JPATH_COMPONENT_ADMINISTRATOR')) {
                    define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/' . $element);
                }
            }
        }
    }    

    /**
     * Bootstraps the Joomla application
     *
     * @return void
     */
    protected function _bootstrap()
    {
        $config = $this->_config->get('joomla');
        
        if(!defined('_JEXEC'))
        {
            $_SERVER['HTTP_HOST']   = 'localhost';
            $_SERVER['HTTP_USER_AGENT'] = 'Composer';

            define('_JEXEC', 1);
            define('DS', DIRECTORY_SEPARATOR);

            define('JPATH_BASE', realpath('.') . (isset($config['basedir']) ? '/' . $config['basedir'] : ''));
            require_once JPATH_BASE . '/includes/defines.php';

            require_once JPATH_BASE . '/includes/framework.php';
            require_once JPATH_LIBRARIES . '/import.php';

            require_once JPATH_LIBRARIES . '/cms.php';
        }

        if(!($this->_application instanceof Application))
        {
            $options = array(
                'root_user' => $this->_credentials['username'],
                'loglevel'  => $this->_verbosity
            );

            $this->_application = new Application($options);
            $this->_application->authenticate($this->_credentials);
        }
    }

    /**
     * Fetches the enqueued flash messages from the Joomla application object.
     *
     * @return array
     */
    protected function _getApplicationMessages()
    {
        $messages       = $this->_application->getMessageQueue();
        $descriptions   = array();

        foreach($messages as $message)
        {
            if($message['type'] == 'error') {
                $descriptions[] = $message['message'];
            }
        }

        return $descriptions;
    }

    protected function _setupExtmanSupport(PackageInterface $target)
    {
        // If we are installing a Joomlatools extension, make sure to load the ComExtmanDatabaseRowExtension class
        $name = strtolower($target->getPrettyName());
        $parts = explode('/', $name);
        if($parts[0] == 'joomlatools' && $parts[1] != 'extman')
        {
            \JPluginHelper::importPlugin('system', 'koowa');

            if(class_exists('Koowa') && !class_exists('ComExtmanDatabaseRowExtension')) {
                \KObjectManager::getInstance()->getObject('com://admin/extman.database.row.extension');
            }
        }
    }

    public function getElementFromManifest($manifest)
    {
        $element    = '';
        $type       = (string) $manifest->attributes()->type;
        $prefix     = isset($this->_prefixes[$type]) ? $this->_prefixes[$type].'_' : 'com_';

        switch($type)
        {
            case 'module':
                if(count($manifest->files->children()))
                {
                    foreach($manifest->files->children() as $file)
                    {
                        if((string) $file->attributes()->module)
                        {
                            $element = (string) $file->attributes()->module;
                            break;
                        }
                    }
                }
                break;
            case 'plugin':
                if(count($manifest->files->children()))
                {
                    foreach($manifest->files->children() as $file)
                    {
                        if ((string) $file->attributes()->$type)
                        {
                            $element = (string) $file->attributes()->$type;
                            break;
                        }
                    }
                }
                break;
            case 'template':
                $element = strtolower((string) $manifest->name);
                $element = preg_replace('/[^A-Z0-9_\.-]/i', '', $element);                
                break;                
            case 'component':
            default:
                $element = strtolower((string) $manifest->name);
                $element = preg_replace('/[^A-Z0-9_\.-]/i', '', $element);

                if(substr($element, 0, 4) != 'com_') {
                    $element = 'com_'.$element;
                }
                break;
        }

        return $element;
    }

    public function __destruct()
    {
        if(!defined('_JEXEC')) {
            return;
        }
        // Clean-up to prevent PHP calling the session object's __destruct() method;
        // which will burp out Fatal Errors all over the place because the MySQLI connection
        // has already closed at that point.
        $session = \JFactory::$session;
        if(!is_null($session) && is_a($session, 'JSession')) {
            $session->close();
        }
    }
}
