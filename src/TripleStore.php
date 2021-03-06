<?php
namespace Pineapple;

use EasyRdf_Namespace;
use EasyRdf_Sparql_Client;
use Generator;


/**
 * Class TripleStore
 *
 * Abstracts data over several endpoints.
 */
class TripleStore {

    private $endpoints = array();
    private $settings = array();
    private $preamble;

    /**
     * Constructor
     *
     * @param array $settings a map of settings containing a list
     * of endpoints and namespaces
     * @param null $endpointURLs an optional set of endpoint URLs
     * @param null $namespaces an optional set of namespaces
     */
    function __construct($settings, $endpointURLs = null, $namespaces = null, $preamble = null) {
        $this->settings = $settings;
        #print_r($this->pineapple_settings);

        $this->preamble = $preamble !== null
            ? $preamble
            : (array_key_exists("sparql_preamble", $this->settings)
                ? $this->settings["sparql_preamble"] : "");

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
        $queryWithPreamble = $this->preprocessQuery($sparqlQuery);
        error_log("Sparql Query:\n$queryWithPreamble");
        foreach ($this->endpoints as $endpoint) {
            $time = microtime(TRUE);
            $result = $endpoint->query($queryWithPreamble);
            error_log(sprintf("Asked: %s; result in %0.3f seconds",
                $endpoint->getQueryUri(), microtime(TRUE) - $time));
            //error_log($result->dump("application/json"));
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
            if ($endpoint->query($this->preprocessQuery($sparqlQuery))->isTrue()) {
                return true;
            }
        }
        return false;
    }

    private function preprocessQuery($query) {
        $prefixes = $this->preamble . "\n";
        // NB: This duplicates the (slightly broken) logic in EasyRDF which
        // adds (known) prefixes if it finds them in the query. It duplicates
        // it verbatim in order that we can add custom preamble to the query
        // to allow for, e.g. Virtuoso pragmas.
        // The reason the logic is slightly broken is that a query that
        // contains, e.g. "?s schema:mentions ?m" will result in the "ma:"
        // prefix being added (because it contains the substring "ma", albeit
        // within another prefix.) We cannot "fix" the logic here because
        // then EasyRdf will prepend our query with that erroneous prefix
        // anyway, breaking it if it contains a preamble pragma.
        foreach (EasyRdf_Namespace::namespaces() as $prefix => $uri) {
            if (strpos($query, "{$prefix}:") !== false and
                strpos($query, "PREFIX {$prefix}:") === false
            ) {
                $prefixes .= "PREFIX {$prefix}: <{$uri}>\n";
            }
        }
        return $prefixes . $query;
    }
}