<?php

namespace LdapRecord;

use Closure;
use Exception;
use ErrorException;

abstract class LdapBase implements LdapInterface
{
    use DetectsErrors;

    /**
     * The LDAP host that is currently connected.
     *
     * @var string|null
     */
    protected $host;

    /**
     * The LDAP connection resource.
     *
     * @var resource|null
     */
    protected $connection;

    /**
     * The bound status of the connection.
     *
     * @var bool
     */
    protected $bound = false;

    /**
     * Whether the connection must be bound over SSL.
     *
     * @var bool
     */
    protected $useSSL = false;

    /**
     * Whether the connection must be bound over TLS.
     *
     * @var bool
     */
    protected $useTLS = false;

    /**
     * {@inheritDoc}
     */
    public function isUsingSSL()
    {
        return $this->useSSL;
    }

    /**
     * {@inheritDoc}
     */
    public function isUsingTLS()
    {
        return $this->useTLS;
    }

    /**
     * {@inheritDoc}
     */
    public function isBound()
    {
        return $this->bound;
    }

    /**
     * {@inheritDoc}
     */
    public function canChangePasswords()
    {
        return $this->isUsingSSL() || $this->isUsingTLS();
    }

    /**
     * {@inheritDoc}
     */
    public function ssl($enabled = true)
    {
        $this->useSSL = $enabled;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function tls($enabled = true)
    {
        $this->useTLS = $enabled;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Returns the LDAP protocol to utilize for the current connection.
     *
     * @return string
     */
    public function getProtocol()
    {
        return $this->isUsingSSL() ? $this::PROTOCOL_SSL : $this::PROTOCOL;
    }

    /**
     * {@inheritDoc}
     */
    public function getExtendedError()
    {
        return $this->getDiagnosticMessage();
    }

    /**
     * Convert warnings to exceptions for the given operation.
     *
     * @param Closure $operation
     *
     * @return mixed
     *
     * @throws LdapRecordException
     */
    protected function executeFailableOperation(Closure $operation)
    {
        set_error_handler(function ($severity, $message, $file, $line) {
            if (! $this->shouldBypassError($message)) {
                throw new ErrorException($message, $severity, $severity, $file, $line);
            }
        });

        try {
            if (($result = $operation()) !== false) {
                return $result;
            }

            if ($this->shouldBypassFailure($method = debug_backtrace()[1]['function'])) {
                return $result;
            }

            throw new Exception("LDAP operation [$method] failed.");
        } catch (ErrorException $e) {
            throw LdapRecordException::withDetailedError($e, $this->getDetailedError());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Determine if the failed operation should be bypassed.
     *
     * @param string $method
     *
     * @return bool
     */
    protected function shouldBypassFailure($method)
    {
        return in_array($method, ['search', 'read', 'listing']);
    }

    /**
     * Determine if the error should be bypassed.
     *
     * @param string $error
     *
     * @return bool
     */
    protected function shouldBypassError($error)
    {
        return $this->causedByPaginationSupport($error) || $this->causedBySizeLimit($error) || $this->causedByNoSuchObject($error);
    }

    /**
     * Determine if the current PHP version supports server controls.
     *
     * @return bool
     */
    public function supportsServerControlsInMethods()
    {
        return version_compare(PHP_VERSION, '7.3.0') >= 0;
    }

    /**
     * Generates an LDAP connection string for each host given.
     *
     * @param string|array $hosts
     * @param string       $protocol
     * @param string       $port
     *
     * @return string
     */
    protected function getConnectionString($hosts, $protocol, $port)
    {
        // If we are using SSL and using the default port, we
        // will override it to use the default SSL port.
        if ($this->isUsingSSL() && $port == static::PORT) {
            $port = static::PORT_SSL;
        }

        $hosts = array_map(function ($host) use ($protocol, $port) {
            return "{$protocol}{$host}:{$port}";
        }, (array) $hosts);

        return implode(' ', $hosts);
    }
}
