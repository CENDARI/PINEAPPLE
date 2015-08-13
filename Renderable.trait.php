<?php

trait Renderable
{
    abstract function getUri();
    abstract function getId();
    abstract function getSchemaName();

    public function getName()
    {
        return $this->getSchemaName() ?: $this->getId() ?: $this->getUri();
    }

    public function getUrlIdent()
    {
        $prefix = "litef://resource/";
        return substr($this->getUri(), strlen($prefix));
    }

    public function __toString()
    {
        return $this->getURI();
    }
}