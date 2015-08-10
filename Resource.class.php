<?php

/**
 * Class Document
 *
 * A convenience wrapper for an EasyRdf_Resource object.
 */
class Resource
{
    public $resource;

    /**
     * Document constructor.
     */
    public function __construct(EasyRdf_Resource $resource)
    {
        $this->resource = $resource;
    }

    public function getURI()
    {
        return $this->resource->getUri();
    }

    public function getId()
    {
        return $this->resource->getLiteral('nao:identifier');
    }

    public function getName()
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
}