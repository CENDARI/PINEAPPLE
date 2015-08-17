<?php

include_once("Resource.class.php");
include_once("Renderable.trait.php");

/**
 * Class Document
 *
 * A convenience wrapper for an EasyRdf_Graph object.
 */
class Document
{
    use Renderable;

    public $graph;
    public $pineapple;

    /**
     * Document constructor.
     */
    public function __construct(EasyRdf_Graph $graph, Pineapple $pineapple)
    {
        $this->graph = $graph;
        $this->pineapple = $pineapple;
    }

    public function getURI()
    {
        return $this->graph->getUri();
    }

    public function getTitle()
    {
        return $this->graph->get($this->graph->getUri(), 'dc11:title');
    }

    public function getId()
    {
        return $this->graph->get($this->graph->getUri(), 'nao:identifier');
    }

    public function getPlainText()
    {
        return $this->graph->get($this->graph->getUri(), 'nie:plainTextContent');
    }

    public function getSchemaName()
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
            array_push($out, new Resource($res, $this->pineapple));
        }
        return $out;
    }

    public function getProperties()
    {
        return $this->graph->toRdfPhp();
    }
}