<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;
use Predis\Client;
use Predis\Pipeline\Pipeline;

const VALUE_PLACEHOLDER = "?";
const VALUE_MAX_LEN = 100;
const VALUE_TOO_LONG_MARK = "...";
const CMD_MAX_LEN = 1000;

class Predis
{
    /**
     * @var array
     */
    private static $connections = [];

    /**
     * Static method to add instrumentation to the Predis library
     */
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('The ddtrace extension is required to instrument Predis', E_USER_WARNING);
            return;
        }
        if (!class_exists(Client::class)) {
            trigger_error('Predis is not loaded and connot be instrumented', E_USER_WARNING);
            return;
        }

        // public Predis\Client::__construct ([ mixed $dsn [, mixed $options ]] )
        dd_trace(Client::class, '__construct', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('Predis.Client__construct');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\REDIS);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setResource('Predis.Client.__construct');

            try {
                $this->__construct(...$args);
                Predis::storeConnectionParams($this, $args);
                return $this;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public void Predis\Client::connect()
        dd_trace(Client::class, 'connect', function () {
            $scope = GlobalTracer::get()->startActiveSpan('Predis.Client.connect');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\REDIS);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setResource('Predis.Client.connect');
            Predis::setConnectionTags($this, $span);

            try {
                return $this->connect();
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public mixed Predis\Client::executeCommand(CommandInterface $command)
        dd_trace(Client::class, 'executeCommand', function ($command) {
            $arguments = $command->getArguments();
            array_unshift($arguments, $command->getId());
            $query = Predis::formatArguments($arguments);

            $scope = GlobalTracer::get()->startActiveSpan('Predis.Client.executeCommand');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\REDIS);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setTag('redis.raw_command', $query);
            $span->setTag('redis.args_length', count($arguments));
            $span->setResource($query);
            Predis::setConnectionTags($this, $span);

            try {
                return $this->executeCommand($command);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public mixed Predis\Client::executeRaw(array $arguments, bool &$error)
        dd_trace(Client::class, 'executeRaw', function ($arguments, &$error = null) {
            $query = Predis::formatArguments($arguments);

            $scope = GlobalTracer::get()->startActiveSpan('Predis.Client.executeCommand');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\REDIS);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setTag('redis.raw_command', $query);
            $span->setTag('redis.args_length', count($arguments));
            $span->setResource($query);
            Predis::setConnectionTags($this, $span);

            try {
                return $this->executeRaw($arguments, $error);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // protected array Predis\Pipeline::executePipeline(ConnectionInterface $connection, \SplQueue $commands)
        dd_trace(Pipeline::class, 'executePipeline', function ($connection, $commands) {
            $scope = GlobalTracer::get()->startActiveSpan('Predis.Pipeline.executePipeline');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\REDIS);
            $span->setTag(Tags\SERVICE_NAME, 'redis');
            $span->setTag('redis.pipeline_length', count($commands));

            try {
                return $this->executePipeline($connection, $commands);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }

    public static function storeConnectionParams($predis, $args)
    {
        $tags = [];

        try {
            $identifier = (string)$predis->getConnection();
            list($host, $port) = explode(':', $identifier);
            $tags[Tags\TARGET_HOST] = $host;
            $tags[Tags\TARGET_PORT] = $port;
        } catch (\Exception $e) {
        }

        if (isset($args[1])) {
            $options = $args[1];
            if (isset($options['parameters']) && isset($options['parameters']['database'])) {
                $tags['out.redis_db'] = $options['parameters']['database'];
            }
        }

        self::$connections[spl_object_hash($predis)] = $tags;
    }

    public static function setConnectionTags($predis, $span)
    {
        $hash = spl_object_hash($predis);
        if (!isset(self::$connections[$hash])) {
            return;
        }

        foreach (self::$connections[$hash] as $tag => $value) {
            $span->setTag($tag, $value);
        }
    }

    /**
     * Format a command by removing unwanted values
     *
     * Restrict what we keep from the values sent (with a SET, HGET, LPUSH, ...):
     * - Skip binary content
     * - Truncate
     */
    public static function formatArguments($arguments)
    {
        $len = 0;
        $out = [];

        foreach ($arguments as $argument) {
            // crude test to skip binary
            if (strpos($argument, "\0") !== false) {
                continue;
            }

            $cmd = (string)$argument;

            if (strlen($cmd) > VALUE_MAX_LEN) {
                $cmd = substr($cmd, 0, VALUE_MAX_LEN) . VALUE_TOO_LONG_MARK;
            }

            if (($len + strlen($cmd)) > CMD_MAX_LEN) {
                $prefix = substr($cmd, 0, CMD_MAX_LEN - $len);
                $out[] = $prefix . VALUE_TOO_LONG_MARK;
                break;
            }

            $out[] = $cmd;
            $len += strlen($cmd);
        }

        return implode(' ', $out);
    }
}
