<?php
/*
 * This file is part of the phpflo/phpflo package.
 *
 * (c) Henri Bergius <henri.bergius@iki.fi>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpFlo;

use PhpFlo\Common\ComponentInterface;
use PhpFlo\Common\SocketInterface;
use PhpFlo\Exception\IncompatibleDatatypeException;
use PhpFlo\Exception\InvalidDefinitionException;
use PhpFlo\Interaction\InternalSocket;

/**
 * Class Network
 *
 * @package PhpFlo
 * @author Henri Bergius <henri.bergius@iki.fi>
 */
class Network
{
    /**
     * @var array
     */
    private $processes;

    /**
     * @var array
     */
    private $connections;

    /**
     * @var Graph
     */
    private $graph;

    /**
     * @var \DateTime
     */
    private $startupDate;

    private $container;

    /**
     * @param Graph $graph
     */
    public function __construct(Graph $graph)
    {
        $this->graph = $graph;
        $this->startupDate = $this->createDateTimeWithMilliseconds();

        $this->graph->on('addNode', [$this, 'addNode']);
        $this->graph->on('removeNode', [$this, 'removeNode']);
        $this->graph->on('addEdge', [$this, 'addEdge']);
        $this->graph->on('removeEdge', [$this, 'removeEdge']);

        $this->processes = [];
        $this->connections = [];
    }

    /**
     * @return bool|\DateInterval
     */
    public function uptime()
    {
        return $this->startupDate->diff($this->createDateTimeWithMilliseconds());
    }

    /**
     * @param array $node
     * @throws InvalidDefinitionException
     */
    public function addNode(array $node)
    {
        if (isset($this->processes[$node['id']])) {
            return $this;
        }

        $process = [];
        $process['id'] = $node['id'];

        if (isset($node['component'])) {
            $componentClass = $node['component'];
            if (!class_exists($componentClass) && strpos($componentClass, '\\') === false) {
                $componentClass = "PhpFlo\\Component\\{$componentClass}";
                if (!class_exists($componentClass)) {
                    throw new InvalidDefinitionException("Component class {$componentClass} not found");
                }
            }
            $component = new $componentClass();
            if (!$component instanceof ComponentInterface) {
                throw new InvalidDefinitionException("Component {$node['component']} doesn't appear to be a valid PhpFlo component");
            }
            $process['component'] = $component;
        }

        $this->processes[$node['id']] = $process;

        return $this;
    }

    /**
     * @param array $node
     * @return $this
     */
    public function removeNode(array $node)
    {
        if (isset($this->processes[$node['id']])) {
            unset($this->processes[$node['id']]);
        }

        return $this;
    }

    /**
     * @param string $id
     * @return mixed|null
     */
    public function getNode($id)
    {
        if (!isset($this->processes[$id])) {
            return null;
        }

        return $this->processes[$id];
    }

    /**
     * @return null|Graph
     */
    public function getGraph()
    {
        return $this->graph;
    }

    /**
     * @param SocketInterface $socket
     * @param array $process
     * @param Port $port
     * @throws InvalidDefinitionException
     * @return mixed
     */
    private function connectInboundPort(SocketInterface $socket, array $process, $port)
    {
        $socket->to = [
            'process' => $process,
            'port' => $port,
        ];

        if (!$process['component']->inPorts()->has($port)) {
            throw new InvalidDefinitionException("No inport {$port} defined for process {$process['id']}");
        }

        return $process['component']
            ->inPorts()
            ->get($port)
            ->attach($socket);
    }

    /**
     * @param array $edge
     * @throws InvalidDefinitionException
     */
    public function addEdge(array $edge)
    {
        if (!isset($edge['from']['node'])) {
            return $this->addInitial($edge);
        }
        $socket = new InternalSocket();

        $from = $this->getNode($edge['from']['node']);
        if (!$from) {
            throw new InvalidDefinitionException("No process defined for outbound node {$edge['from']['node']}");
        }

        $to = $this->getNode($edge['to']['node']);
        if (!$to) {
            throw new InvalidDefinitionException("No process defined for inbound node {$edge['to']['node']}");
        }

        $this->connectPorts($socket, $from, $to, $edge['from']['port'], $edge['to']['port']);

        $this->connections[] = $socket;
    }

    /**
     * Connect out to inport and compare data types.
     *
     * @param SocketInterface $socket
     * @param array $from
     * @param array $to
     * @param string $edgeFrom
     * @param string $edgeTo
     * @throws IncompatibleDatatypeException
     * @throws InvalidDefinitionException
     */
    private function connectPorts(SocketInterface $socket, array $from, array $to, $edgeFrom, $edgeTo)
    {
        $socket->from = [
            'process' => $from,
            'port' => $edgeFrom,
        ];

        if (!$from['component']->outPorts()->has($edgeFrom)) {
            throw new InvalidDefinitionException("No outport {$edgeFrom} defined for process {$from['id']}");
        }

        $socket->to = [
            'process' => $to,
            'port' => $edgeTo,
        ];

        if (!$to['component']->inPorts()->has($edgeTo)) {
            throw new InvalidDefinitionException("No inport {$edgeTo} defined for process {$to['id']}");
        }

        $fromType = $from['component']->outPorts()->get($edgeFrom)->getAttribute('datatype');
        $toType = $to['component']->inPorts()->get($edgeTo)->getAttribute('datatype');

        // compare out and in ports for datatype definitions
        if (!$this->isPortCompatible($fromType, $toType)) {
            throw new IncompatibleDatatypeException(
                "Process {$from['id']}: outport type \"{$fromType}\" of port \"{$edgeFrom}\" ".
                "does not match {$to['id']} inport type \"{$toType}\" of port \"{$edgeTo}\""
            );
        }

        $from['component']->outPorts()->get($edgeFrom)->attach($socket);
        $to['component']->inPorts()->get($edgeTo)->attach($socket);

        return;
    }

    /**
     * @param array $edge
     * @return $this
     */
    public function removeEdge(array $edge)
    {
        foreach ($this->connections as $index => $connection) {
            if ($edge['to']['node'] == $connection->to['process']['id'] && $edge['to']['port'] == $connection->to['process']['port']) {
                $connection->to['process']['component']->inPorts()->get($edge['to']['port'])->detach($connection);
                $this->connections = array_splice($this->connections, $index, 1);
            }

            if (isset($edge['from']['node'])) {
                if ($edge['from']['node'] == $connection->from['process']['id'] && $edge['from']['port'] == $connection->from['process']['port']) {
                    $connection->from['process']['component']->inPorts()->get($edge['from']['port'])->detach($connection);
                    $this->connections = array_splice($this->connections, $index, 1);
                }
            }
        }

        return $this;
    }

    /**
     * @param array $initializer
     * @return $this
     */
    public function addInitial(array $initializer)
    {
        $socket = new InternalSocket();
        $to = $this->getNode($initializer['to']['node']);
        if (!$to) {
            throw new InvalidDefinitionException("No process defined for inbound node {$initializer['to']['node']}");
        }

        $this->connectInboundPort($socket, $to, $initializer['to']['port']);
        $socket->connect();
        $socket->send($initializer['from']['data']);
        $socket->disconnect();

        $this->connections[] = $socket;

        return $this;
    }

    /**
     * @param Graph $graph
     * @return Network
     */
    public static function create(Graph $graph)
    {
        $network = new Network($graph);

        foreach ($graph->nodes as $node) {
            $network->addNode($node);
        }

        foreach ($graph->edges as $edge) {
            $network->addEdge($edge);
        }

        foreach ($graph->initializers as $initializer) {
            $network->addInitial($initializer);
        }

        return $network;
    }

    /**
     * Load PhpFlo graph definition from string.
     *
     * @param string $string
     * @return Network
     */
    public static function loadString($string)
    {
        $graph = Graph::loadString($string);

        return Network::create($graph);
    }

    /**
     * Load PhpFlo graph definition from file.
     *
     * @param string $file
     * @return Network
     */
    public static function loadFile($file)
    {
        $graph = Graph::loadFile($file);

        return Network::create($graph);
    }

    /**
     * @return \DateTime
     */
    private function createDateTimeWithMilliseconds()
    {
        return \DateTime::createFromFormat('U.u', sprintf('%.6f', microtime(true)));
    }

    /**
     * Compare in and outport datatypes.
     *
     * @param string $fromType
     * @param string $toType
     * @return bool
     */
    private function isPortCompatible($fromType, $toType)
    {
        switch(true) {
            case (($fromType == $toType) || ($toType == 'all' || $toType == 'bang')):
                $isCompatible = true;
                break;
            case (($fromType == 'int' || $fromType == 'integer') && $toType == 'number'):
                $isCompatible = true;
                break;
            default:
                $isCompatible = false;
        }

        return $isCompatible;
    }
}
