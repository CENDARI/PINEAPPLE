<?php
namespace Pineapple;

use EasyRdf_Sparql_Client, EasyRdf_Namespace;
use Generator;


/**
 * Class TripleStore
 *
 * Abstracts data over several endpoints.
 */
class TripleStore {

    private $endpoints = array();
    private $settings = array();

    function __construct($settings, $endpointURLs = null, $namespaces = null) {
        $this->settings = $settings;
        #print_r($this->pineapple_settings);

        if ($endpointURLs == null)
            $endpointURLs =& $this->settings["endpoints"];
        if ($namespaces == null)
            $namespaces =& $this->settings["namespaces"];

        foreach ($endpointURLs as $url) {
            $sparql = new EasyRdf_Sparql_Client($url);
            array_push($this->endpoints, $sparql);
        }

        foreach ($namespaces as $prefix => $uri) {
            EasyRdf_Namespace::set($prefix, $uri);
        }
    }

    /**
     * Query the available endpoints and return
     * generator of result rows.
     *
     * NB: This is not an ideal way of doing federated
     * queries, since
     *
     * @param $sparqlQuery
     * @return Generator of return rows
     */
    public function query($sparqlQuery) {
        error_log("Query: $sparqlQuery");
        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($sparqlQuery);
            //error_log($result->dump("application/sparql-results+xml"));
            foreach ($result as $row) {
                yield $row;
            }
        }
    }

    /**
     * Returns true of any of the available
     * endpoints
     *
     * @param $sparqlQuery
     * @return bool
     */
    public function ask($sparqlQuery) {
        foreach ($this->endpoints as $endpoint) {
            if ($endpoint->query($sparqlQuery)->isTrue()) {
                return true;
            }
        }
        return false;
    }
}