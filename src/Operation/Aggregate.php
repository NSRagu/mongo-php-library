<?php

namespace MongoDB\Operation;

use MongoDB\Driver\Command;
use MongoDB\Driver\Server;
use MongoDB\Exception\InvalidArgumentException;
use MongoDB\Exception\InvalidArgumentTypeException;
use MongoDB\Exception\RuntimeException;
use MongoDB\Exception\UnexpectedValueException;
use ArrayIterator;
use stdClass;
use Traversable;

/**
 * Operation for the aggregate command.
 *
 * @api
 * @see MongoDB\Collection::aggregate()
 * @see http://docs.mongodb.org/manual/reference/command/aggregate/
 */
class Aggregate implements Executable
{
    private static $wireVersionForCursor = 2;

    private $databaseName;
    private $collectionName;
    private $pipeline;
    private $options;

    /**
     * Constructs an aggregate command.
     *
     * Supported options:
     *
     *  * allowDiskUse (boolean): Enables writing to temporary files. When set
     *    to true, aggregation stages can write data to the _tmp sub-directory
     *    in the dbPath directory. The default is false.
     *
     *  * batchSize (integer): The number of documents to return per batch.
     *
     *  * maxTimeMS (integer): The maximum amount of time to allow the query to
     *    run.
     *
     *  * useCursor (boolean): Indicates whether the command will request that
     *    the server provide results using a cursor. The default is true.
     *
     *    For servers < 2.6, this option is ignored as aggregation cursors are
     *    not available.
     *
     *    For servers >= 2.6, this option allows users to turn off cursors if
     *    necessary to aid in mongod/mongos upgrades.
     *
     * @param string $databaseName   Database name
     * @param string $collectionName Collection name
     * @param array  $pipeline       List of pipeline operations
     * @param array  $options        Command options
     * @throws InvalidArgumentException
     */
    public function __construct($databaseName, $collectionName, array $pipeline, array $options = array())
    {
        $options += array(
            'allowDiskUse' => false,
            'useCursor' => true,
        );

        if ( ! is_bool($options['allowDiskUse'])) {
            throw new InvalidArgumentTypeException('"allowDiskUse" option', $options['allowDiskUse'], 'boolean');
        }

        if (isset($options['batchSize']) && ! is_integer($options['batchSize'])) {
            throw new InvalidArgumentTypeException('"batchSize" option', $options['batchSize'], 'integer');
        }

        if (isset($options['maxTimeMS']) && ! is_integer($options['maxTimeMS'])) {
            throw new InvalidArgumentTypeException('"maxTimeMS" option', $options['maxTimeMS'], 'integer');
        }

        if ( ! is_bool($options['useCursor'])) {
            throw new InvalidArgumentTypeException('"useCursor" option', $options['useCursor'], 'boolean');
        }

        if (isset($options['batchSize']) && ! $options['useCursor']) {
            throw new InvalidArgumentException('"batchSize" option should not be used if "useCursor" is false');
        }

        $expectedIndex = 0;

        foreach ($pipeline as $i => $op) {
            if ($i !== $expectedIndex) {
                throw new InvalidArgumentException(sprintf('$pipeline is not a list (unexpected index: "%s")', $i));
            }

            if ( ! is_array($op) && ! is_object($op)) {
                throw new InvalidArgumentTypeException(sprintf('$pipeline[%d]', $i), $op, 'array or object');
            }

            $expectedIndex += 1;
        }

        $this->databaseName = (string) $databaseName;
        $this->collectionName = (string) $collectionName;
        $this->pipeline = $pipeline;
        $this->options = $options;
    }

    /**
     * Execute the operation.
     *
     * @see Executable::execute()
     * @param Server $server
     * @return Traversable
     */
    public function execute(Server $server)
    {
        $isCursorSupported = \MongoDB\server_supports_feature($server, self::$wireVersionForCursor);

        $command = $this->createCommand($server, $isCursorSupported);
        $cursor = $server->executeCommand($this->databaseName, $command);

        if ($isCursorSupported && $this->options['useCursor']) {
            return $cursor;
        }

        $result = current($cursor->toArray());

        if (empty($result->ok)) {
            throw new RuntimeException(isset($result->errmsg) ? $result->errmsg : 'Unknown error');
        }

        if ( ! isset($result->result) || ! is_array($result->result)) {
            throw new UnexpectedValueException('aggregate command did not return a "result" array');
        }

        return new ArrayIterator($result->result);
    }

    /**
     * Create the aggregate command.
     *
     * @param Server  $server
     * @param boolean $isCursorSupported
     * @return Command
     */
    private function createCommand(Server $server, $isCursorSupported)
    {
        $cmd = array(
            'aggregate' => $this->collectionName,
            'pipeline' => $this->pipeline,
        );

        // Servers < 2.6 do not support any command options
        if ( ! $isCursorSupported) {
            return new Command($cmd);
        }

        $cmd['allowDiskUse'] = $this->options['allowDiskUse'];

        if (isset($this->options['maxTimeMS'])) {
            $cmd['maxTimeMS'] = $this->options['maxTimeMS'];
        }

        if ($this->options['useCursor']) {
            $cmd['cursor'] = isset($this->options["batchSize"])
                ? array('batchSize' => $this->options["batchSize"])
                : new stdClass;
        }

        return new Command($cmd);
    }
}
