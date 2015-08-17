<?php

trait Renderable
{
    abstract function getUri();
    abstract function getId();
    abstract function getTitle();
    abstract function getSchemaName();
    abstract function getLastModified();

    public function getName()
    {
        return $this->getTitle() ?: $this->getSchemaName() ?: $this->getId() ?: $this->getUri();
    }

    public function getLastModifiedDate()
    {
        return date("d-m-Y H:i", intval($this->getLastModified()->getValue()) / 1000);
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