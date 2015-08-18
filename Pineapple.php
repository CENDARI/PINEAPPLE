<?php
require "vendor/autoload.php";


class ResourceNotFoundException extends Exception {
}

class AccessDeniedException extends Exception {
}

class MalformedAPIKeyException extends Exception {
}

class Pineapple {
    static $DEFAULT_ENDPOINT_URLS = array("http://localhost:8890/sparql");
    const DEFAULT_PAGINATION_LIMIT = 20;

    private $endpoints = array();

    private function getAuthParam($key) {
        return isset($_SERVER[$key]) ? $_SERVER[$key] : $_ENV[$key];
    }

    private function getApiKey() {
        $data = [
            "eppn" => $this->getAuthParam("eppn"),
            "mail" => $this->getAuthParam("mail"),
            "cn" => $this->getAuthParam("cn")
        ];

        $out = Requests::post("http://localhost:42042/v1/session",
            ["Content-type" => "application/json"], json_encode($data));
        return json_decode($out->body)->sessionKey;
    }

    private function getDataspaces() {
        $out = Requests::get("http://localhost:42042/v1/dataspaces",
            ["Authorization" => $this->getApiKey()]);
        return json_decode($out->body)->data;
    }

    function Pineapple($endpointURLs = null, $namespaces = null) {
        $this->settings = parse_ini_file("settings.ini");
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

    function getResource($uddi) {
        $graph = $this->getResourceGraph($uddi);
        return $this->getResourceData($graph);
    }

    function getResourceMentions($uddi) {
        $graph = $this->getResourceGraph($uddi);
        return $this->getResourceMentionData($graph);
    }

    function getResourceGraph($uddi) {
        $graph_uri = sprintf("resource:%s", $uddi);
        if (!$this->checkResourceExists($graph_uri)) {
            throw new ResourceNotFoundException($graph_uri . " not found.");
        }
        $query =
            "select ?s ?p ?o " .
            $this->getPermissionFilter() .
            " where {" .
            " graph $graph_uri" .
            " {?s ?p ?o} }";

        error_log("Query: " . $query);

        $combined_graph = new EasyRdf_Graph($graph_uri);
        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($query);
            foreach ($result as $row) {
                $combined_graph->add($row->s, $row->p, $row->o);
            }
        }
        return $combined_graph;
    }

    private function getResourceData(EasyRdf_Graph $graph) {
        $uri = $graph->getUri();
        return [
            "id" => $graph->get($uri, 'nao:identifier')->getValue(),
            "title" => $graph->get($uri, 'dc11:title')->getValue(),
            "plainText" => $graph->get($uri, "nie:plainTextContent")->getValue(),
            "source" => $graph->get($uri, "dc11:source")->getValue(),
            "lastModified" => $graph->get($uri, "nao:lastModified")->getValue(),
            "mentions" => $this->getResourceMentionData($graph)
        ];
    }

    private function getResourceMentionData(EasyRdf_Graph $graph) {
        $uri = $graph->getUri();

        $out = [];
        foreach ($graph->allResources($uri, 'schema:mentions') as $res) {
            array_push($out, [
                "uri" => $res->getUri(),
                "type" => $res->type(),
                "title" => $res->getLiteral("schema:name")->getValue()
            ]);
        }

        return $out;
    }

    function getResources($q = null, $from, $to) {
        $query =

            "select distinct ?title ?identifier ?lastModified count(?m) as ?count " .
            $this->getPermissionFilter() .
            "where {" .
            "  [] schema:mentions ?m ; " .
            "     dc11:title ?title ; " .
            "     nao:lastModified ?lastModified ; " .
            "     nao:identifier ?identifier . " .
            $this->getSearchFilter("?title", $q) .
            "} order by ASC(?title) " . // FIXME: Why doesn't this work???
            "offset $from limit $to";

        $out = [];
        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($query);

            foreach ($result as $row) {
                array_push($out, [
                    "id" => $row->identifier->getValue(),
                    "title" => $row->title->getValue(),
                    "lastModified" => $row->lastModified->getValue(),
                    "numMentions" => $row->count->getValue()
                ]);
            }
        }

        return $out;
    }

    function getMentionResources($type, $name, $from, $to) {
        $query =

            "select distinct ?title ?identifier " .
            $this->getPermissionFilter() .
            "where {" .
            "  [] schema:mentions [" .
            "        a $type ; " .
            // FIXME: literal type? why is this needed?
            "        schema:name \"$name\"^^xsd:string " .
            "     ] ; " .
            "     dc11:title ?title ; " .
            "     nao:identifier ?identifier . " .
            "}" .
            "offset $from limit $to";

        error_log("Query: $query");

        $out = [];
        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($query);

            foreach ($result as $row) {
                array_push($out, [
                    "id" => $row->identifier->getValue(),
                    "title" => $row->title->getValue()
                ]);
            }
        }

        return $out;
    }

    private function checkResourceExists($uri) {
        $full_uri = EasyRdf_Namespace::expand($uri);
        $query_string = "ask { <$full_uri> ?p  ?o}";

        foreach ($this->endpoints as $ep) {
            if ($ep->query($query_string)->isTrue()) {
                return true;
            }
        }
        return false;
    }


    private function getPermissionFilter() {
        if ($this->settings["AUTHORISATION_TYPE"] === "NONE") {
            return "";
        }

        $dataspaces = [];
        if ($this->settings["AUTHORISATION_TYPE"] === "DEBUG") {
            foreach ($this->settings["AUTHORISED_DATASPACES"] as $uddi)
                $dataspaces[] = "litef://dataspaces/$uddi";
        } else {
            foreach ($this->getDataspaces() as $dataspace)
                $dataspaces[] = "litef://dataspaces/" . $dataspace["id"];
        }


        $filter = [];
        foreach ($dataspaces as $ds)
            $filter[] = "?ds = <$ds>";
        $graphs_query =
            "select ?g where {" .
            " ?g rdfs:member ?ds" .
            " FILTER (" . implode(" || ", $filter) . ")}";

        $graphs = [];
        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($graphs_query);
            foreach ($result as $row) {
                $graphs[] = $row->g;
            }
        }

        $output = "";
        foreach ($graphs as $g) {
            $output = $output . "FROM <$g>\n";
        }

        return $output;
    }

    private function getSearchFilter($pred, $q) {
        if ($q == null || trim($q) === "") {
            return "";
        }

        // Sadness. Apparently there's no way to parameterize
        // queries with Easy_RDF, so this rubbish thing will have
        // to do.
        error_log("Raw query: $q");
        $chars = preg_replace("/[^\+\s\w]/", ' ', $q);
        $alts = implode("|", explode(" ", $chars));
        if (strlen($chars) === 0) {
            return "";
        } else {
            return " FILTER regex ($pred, \"$alts\", \"i\" )";
        }
    }
}