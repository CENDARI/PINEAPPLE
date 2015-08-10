<?php

include_once("Resource.class.php");

/**
 * Class Document
 *
 * A convenience wrapper for an EasyRdf_Graph object.
 */
class Document
{
    public $graph;

    /**
     * Document constructor.
     */
    public function __construct(EasyRdf_Graph $graph)
    {
        $this->graph = $graph;
    }

    public static function from(EasyRdf_Graph $graph)
    {
        return new Document($graph);
    }

    public function getURI()
    {
        return $this->graph->getUri();
    }

    public function getId()
    {
        return $this->graph->get($this->graph->getUri(), 'nao:identifier');
    }

    public function getPlainText()
    {
        return $this->graph->get($this->graph->getUri(), 'nie:plainTextContent');
    }

    public function getName()
    {
        return $this->graph->get($this->graph->getUri(), 'schema:name');
    }

    public function getSource()
    {
        return $this->graph->get($this->graph->getUri(), 'dc11:source');
    }

    public function getLastModified()
    {
        return $this->graph->get($this->graph->getUri(), 'nao:lastModified');
    }

    public function getLinkedResources()
    {
        $out = array();
        foreach ($this->graph->allResources($this->graph->getUri(), 'schema:mentions') as $res)
        {
            array_push($out, new Resource($res));
        }
        return $out;
    }
}