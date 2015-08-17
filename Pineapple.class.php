<?php
require "vendor/autoload.php";
require_once "Document.class.php";


class ResourceNotFoundException extends Exception
{
}

class AccessDeniedException extends Exception
{
}

class MalformedAPIKeyException extends Exception
{
}

class Pineapple
{
    static $DEFAULT_ENDPOINT_URLS = array("http://localhost:8890/sparql");
    const DEFAULT_PAGINATION_LIMIT = 40;

    private $endpoints = array();

    private function getAuthParam($key)
    {
        return isset($_SERVER[$key]) ? $_SERVER[$key] : $_ENV[$key];
    }

    private function getApiKey()
    {
        $data = [
            "eppn" => $this->getAuthParam("eppn"),
            "mail" => $this->getAuthParam("mail"),
            "cn" => $this->getAuthParam("cn")
        ];

        $out = Requests::post("http://localhost:42042/v1/session",
            ["Content-type" => "application/json"], json_encode($data));
        return json_decode($out->body)->sessionKey;
    }

    private function getDataspaces()
    {
        $out = Requests::get("http://localhost:42042/v1/dataspaces",
            ["Authorization" => $this->getApiKey()]);
        return json_decode($out->body)->data;
    }

    function Pineapple($endpointURLs = null, $namespaces = null)
    {
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

    function get_document_graph($uddi, $inference = null)
    {
        $graph_uri = sprintf("resource:%s", $uddi);
        if (!$this->_check_resource_exists($graph_uri)) {
            throw new ResourceNotFoundException($graph_uri . " not found.");
        }
        $query =
            "select ?s ?p ?o " .
            $this->_get_permission_filter() .
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
        return new Document($combined_graph, $this);
    }

    function get_all_resources($q = null, $from = 0, $to = Pineapple::DEFAULT_PAGINATION_LIMIT)
    {
        $query =

            "select distinct ?g ?title ?identifier ?lastModified count(?m) as ?count " .
            $this->_get_permission_filter() .
            "where {" .
            "  graph ?g {" .
            "    [] schema:mentions ?m ; ".
            "       dc11:title ?title ; ".
            "       nao:lastModified ?lastModified ; ".
            "       nao:identifier ?identifier . ".
            $this->_get_search_filter("?title", $q) .
            "  }" .
            "} order by ASC(?title) " . // FIXME: Why doesn't this work???
            "offset $from limit $to";

        $out = array();
        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($query);

            foreach ($result as $row) {
                array_push($out, [
                    "resource" => new Resource($row->g, $this),
                    "title" => $row->title,
                    "lastModified" => date("d-m-Y H:i", intval($row->lastModified->getValue()) / 1000),
                    "count" => $row->count
                ]);
            }
        }

        return $out;
    }

    function get_document_mention_types($uddi, $inference = null)
    {
        $graph_uri = "resource:$uddi";

        if (!$this->_check_resource_exists($graph_uri)) {
            throw new ResourceNotFoundException($graph_uri . " not found.");
        }

        $query =
            "select distinct ?type " .
            $this->_get_permission_filter() .
            " where {" .
            " $graph_uri schema:mentions ?o." .
            " ?o rdf:type ?type.}";
        $out = array();

        foreach ($this->endpoints as $endpoint) {

            $result = $endpoint->query($query);

            foreach ($result as $row) {
                $out[] = $row->type;
            }
        }
        return $out;
    }

    function get_document_mention_individuals($type, $name, $inference = null)
    {
        $query =

            "select distinct ?s ?title ?identifier " .
            $this->_get_permission_filter() .
            "where {" .
            "  ?m a $type ; " .
            // FIXME: literal type? why is this needed?
            "     schema:name \"$name\"^^xsd:string . " .
            "  ?s schema:mentions ?m ; ".
            "     dc11:title ?title ; ".
            "     nao:identifier ?identifier . ".
            "} order by ASC(?title) "; // FIXME: Why doesn't this work???

        error_log("Query: $query");

        $out = array();
        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($query);

            foreach ($result as $row) {
                array_push($out, [
                    "resource" => new Resource($row->s, $this),
                    "title" => $row->title
                ]);
            }
        }

        return $out;
    }

    function get_resource_graph($uri, $permission_filter, $inference = null)
    {
        $uri = EasyRdf_Namespace::expand($uri);
        if (strpos($uri, "://") === false)
            $uri = "cendari://resources/" . $uri;

        if (!$this->_check_resource_exists($uri)) {
            throw new ResourceNotFoundException($uri . " not found.");
        }

        $query_string =

            "select ?s ?ps ?po ?o " .
            $this->_get_permission_filter() .
            " where {" .
            " {<$uri> ?po ?o}" .
            " UNION" .
            " {?s ?ps <$uri>}}";


        $combined_graph = new EasyRdf_Graph($uri);
        foreach ($this->endpoints as $endpoint) {

            $result = $endpoint->query($query_string);

            foreach ($result as $row) {
                if (isset($row->s))
                    $combined_graph->add($row->s, $row->ps, $uri);
                else if (isset($row->o))
                    $combined_graph->add($uri, $row->po, $row->o);
            }
        }

        return new Document($combined_graph, $this);

    }

    function complete_classname($name_fragment)
    {
        $query_string =
            "select ?concept where {" .
            " ?concept a owl:class." .
            " filter regex (?concept, '(/|#)${name_fragment}[a-zA-Z0-9]+$','i')";

        $out = array();
        foreach ($this->endpoints as $ep) {
            $result = $ep->query($query_string);
            foreach ($result as $row)
                $out[] = $row->concept;
        }

        return $out;


    }

    /*
     * TODO Find out - is this something that is necessary?
     *
    function _check_resource_permission($uri)
    {
       $query_string = sprintf("ask { graph ?g {<%s> ?p  ?o} %s}",
          EasyRdf_Namespace::expand($uri), _get_permission_filter("?g"));

       foreach($this->endpoints as $ep)
       {
          if($ep->query($query_string)->isTrue())
          {
             return true;
          }
       }
       return false;
    }*/


    function _check_resource_exists($uri)
    {
        $full_uri = EasyRdf_Namespace::expand($uri);
        $query_string = "ask { <$full_uri> ?p  ?o}";

        foreach ($this->endpoints as $ep) {
            if ($ep->query($query_string)->isTrue()) {
                return true;
            }
        }
        return false;
    }


    function _get_permission_filter()
    {
        if ($this->settings["AUTHORISATION_TYPE"] === "NONE")
            return "";
        else {
            $dataspaces = array();
            if ($this->settings["AUTHORISATION_TYPE"] === "DEBUG") {
                foreach ($this->settings["AUTHORISED_DATASPACES"] as $uddi)
                    $dataspaces[] = "litef://dataspaces/$uddi";
            } else {
                foreach ($this->getDataspaces() as $dataspace)
                    $dataspaces[] = "litef://dataspaces/" . $dataspace["id"];
            }


            $filter = array();
            foreach ($dataspaces as $ds)
                $filter[] = "?ds = <$ds>";
            $graphs_query =
                "select ?g where {" .
                " ?g rdfs:member ?ds" .
                " FILTER (" . implode(" || ", $filter) . ")}";

            $graphs = array();

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
    }

    private function _get_search_filter($pred, $q)
    {
        if ($q == null) {
            return "";
        } else {
            // Sadness. Apparently there's no way to parameterize
            // queries with Easy_RDF, so this rubbish thing will have
            // to do.
            $chars = preg_replace("/\W/", '', $q);
            if (strlen($chars) === 0) {
                return "";
            } else {
                return " FILTER regex ($pred, \"$chars\", \"i\" )";
            }
        }
    }
}