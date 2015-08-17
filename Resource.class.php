<?php

include_once("Renderable.trait.php");

/**
 * Class Document
 *
 * A convenience wrapper for an EasyRdf_Resource object.
 */
class Resource
{
    use Renderable;

    public $resource;
    public $settings;

    /**
     * Document constructor.
     */
    public function __construct(EasyRdf_Resource $resource, Pineapple $settings)
    {
        $this->resource = $resource;
        $this->settings = $settings;
    }

    public function getURI()
    {
        return $this->resource->getUri();
    }

    public function getId()
    {
        return $this->resource->getLiteral('nao:identifier');
    }

    public function getTitle()
    {
        return $this->resource->getLiteral('dc11:title');
    }

    public function getSchemaName()
    {
        return $this->resource->getLiteral('schema:name');
    }

    public function getSource()
    {
        return $this->resource->getLiteral('dc11:source');
    }

    public function getLastModified()
    {
        return $this->resource->getLiteral('nao:lastModified');
    }

    public function getProperties()
    {
        return $this->resource->toRdfPhp();
    }
}