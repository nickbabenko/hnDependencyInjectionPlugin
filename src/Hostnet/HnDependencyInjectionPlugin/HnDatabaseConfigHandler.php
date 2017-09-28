<?php
namespace Hostnet\HnDependencyInjectionPlugin;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Alternative for the \sfDatabaseConfigHandler, it reads the config in the
 * Symfony2 format
 *
 * If you want to use this
 * Let your ApplicationConfiguration extend the
 * Hostnet\HnDependencyInjectionPlugin\ApplicationConfiguration
 *
 * If you want to develop on this class, please note that this is very early in
 * the sf1 initialization
 * So you can't use much more then sfConfig::get('sf_environment');
 *
 * @author Nico Schoenmaker <nico@hostnet.nl>
 */
class HnDatabaseConfigHandler
{

    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @see sfConfigHandler::execute()
     * @return string PHP code
     */
    public function execute()
    {
        $debug = $this->container->getParameter('kernel.debug');

        $connections = array();
        if (! $this->container->hasParameter('hn_entities_enable_backwards_compatible_connections')
            ||  $this->container->getParameter('hn_entities_enable_backwards_compatible_connections')
        ) {
            $connections = $this->container->get('doctrine')->getConnections();
        }

        if (strpos(SYMFONY_VERSION, '1.1.') === 0) {
            $output = $this->createSymfony11Output($connections);
        } else {
            if (strpos(SYMFONY_VERSION, '1.3.') === 0
                || strpos(SYMFONY_VERSION, '1.4.') === 0
                || strpos(SYMFONY_VERSION, '1.5.') === 0
            ) {
                $output = $this->createSymfony14Output($debug, $connections);
            } else {
                throw new \DomainException(
                    'Untested Symfony version ' . SYMFONY_VERSION .
                    ', but maybe one of the others will work'
                );
            }
        }
        // compile data
        return sprintf(
            "<?php\n" . "// auto-generated by hnDatabaseConfigHandler\n// date: %s\n\n%s\n",
            date('Y/m/d H:i:s'),
            $output
        );
    }

    private function createSymfony11Output(array $connections)
    {
        $output = '';
        foreach ($connections as $name => $connection) {
            $config = array(
                'dsn' => $this->formatDSN($connection),
                'name' => $name
            );

            $output .= sprintf(
                '$this->setDatabase(\'%s\', new %s(%s));',
                $name,
                $this->getPropelClass(),
                var_export($config, true)
            );

            $output .= PHP_EOL . PHP_EOL;
        }
        return $output;
    }

    private function createSymfony14Output($debug, array $connections)
    {
        $output = 'return array(' . PHP_EOL;

        foreach ($connections as $name => $connection) {
            /* @var $connection Connection */

            $dsn = sprintf(
                '%s:dbname=%s;host=%s;port=%s',
                $this->getPropelDriverName($connection->getDriver()),
                $connection->getDatabase(),
                $connection->getHost(),
                $connection->getPort()
            );

            $config = array(
                'classname' => $this->getClassname($name, $debug),
                'dsn' => $dsn,
                'username' => $connection->getUsername(),
                'password' => $connection->getPassword(),
                'persistent' => true,
                'pooling' => true,
                'encoding' => 'utf8',
                'name' => $name
            );

            $output .= sprintf(
                "'%s' => new %s(%s),",
                $name,
                $this->getPropelClass(),
                var_export($config, true)
            );

            $output .= PHP_EOL . PHP_EOL;
        }

        $output .= ');';
        return $output;
    }

    /**
     * @todo make "sfPropelDatabase" dynamic, specific for each connection
     * @return string
     */
    private function getPropelClass()
    {
        return 'sfPropelDatabase';
    }

    /**
     * @todo make the mysql bit dynamic
     * @param Connection $connection
     */
    private function formatDSN(Connection $connection)
    {
        $driver_name = $this->getPropelDriverName($connection->getDriver());
        if ($connection->getDriver()->getName() == 'pdo_sqlite') {
            return sprintf(
                '%s://hack.nl/%s',
                $driver_name,
                $connection->getDatabase()
            );
        }
        $params = $connection->getParams();
        return sprintf(
            '%s://%s:%s@%s%s/%s?encoding=%s',
            $driver_name,
            $connection->getUsername(),
            $connection->getPassword(),
            $connection->getHost(),
            $connection->getPort() ? ':' . $connection->getPort() : '',
            $connection->getDatabase(),
            isset($params['charset']) ? $params['charset'] :  'utf8'
        );
    }

    /**
     * @param Driver $driver
     * @throws \DomainException
     * @return string
     */
    private function getPropelDriverName(Driver $driver)
    {
        $lookup_table = array(
            'pdo_mysql' => 'mysql',
            'pdo_pgsql' => 'pgsql',
            'pdo_sqlite' => 'sqlite'
        );
        if (isset($lookup_table[$driver->getName()])) {
            return $lookup_table[$driver->getName()];
        }
        throw new \DomainException(sprintf('Unknown driver "%s"', $driver->getName()));
    }

    /**
     * @param string $name
     * @param bool $debug
     * @return string
     */
    private function getClassname($name, $debug)
    {
        if ($this->container->hasParameter($name . '_database_classname')) {
            return $this->container->getParameter($name . '_database_classname');
        }
        return $debug ? 'DebugPDO' : 'PropelPDO';
    }
}