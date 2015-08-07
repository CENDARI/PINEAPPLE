<?php
require "vendor/autoload.php";
require_once "render_html.php";

function getAuthParam($key)
{
    return isset($_SERVER[$key]) ? $_SERVER[$key] : $_ENV[$key];
}

function getApiKey()
{

    $ch = curl_init("http://localhost:42042/v1/session");

    $json_request = array("eppn" => getAuthParam("eppn"),
        "mail" => getAuthParam("mail"),
        "cn" => getAuthParam("cn"));


    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_request));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $response = json_decode(curl_exec($ch));
    return $response->sessionKey;
}

function getDataspaces()
{
    $apiKey = getApiKey();
    $headers = array("Authorization: $apiKey");
    $ch = curl_init("http://localhost:42042/v1/dataspaces");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $response = json_decode(curl_exec($ch));
    return $response->data;

}

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

    private $endpoints = array();

    function Pineapple(
        $endpointURLs = null, $namespaces = null)
    {
        $this->pineapple_settings = parse_ini_file("settings.ini");
        #print_r($this->pineapple_settings);

        if ($endpointURLs == null)
            $endpointURLs =& $this->pineapple_settings["endpoints"];
        if ($namespaces == null)
            $namespaces =& $this->pineapple_settings["namespaces"];

        foreach ($endpointURLs as $url) {
            $sparql = new EasyRdf_Sparql_Client($url);
            array_push($this->endpoints, $sparql);
        }

        foreach ($namespaces as $prefix => $uri) {
            EasyRdf_Namespace::set($prefix, $uri);
        }
    }


    function describe_document($uddi, $format)
    {
        $doc_graph = $this->get_document_graph($uddi);
        if ($format != "html") {
            return $doc_graph->serialise($format);
        } else {
            return render_document_html($doc_graph);
        }
    }

    function describe_resource($uddi, $format)
    {
        $resource_graph = $this->get_resource_graph($uddi, "");
        if ($format != "html") {
            return $resource_graph->serialise($format);
        } else {
            return render_document_html($resource_graph);
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


        $combined_graph = new EasyRdf_Graph($graph_uri);
        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($query);

            foreach ($result as $row) {
                $combined_graph->add($row->s, $row->p, $row->o);
            }
        }

        return $combined_graph;

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

    function get_document_mention_individuals($uddi, $type, $inference = null)
    {
        $graph_uri = "litef://resource:$uddi";
        $type = EasyRdf_Namespace::expand($type);

        if (!$this->_check_resource_exists($graph_uri)) {
            throw new ResourceNotFoundException($graph_uri . " not found.");
        }

        $query_string =
            "select distinct ?x " .
            $this->_get_permission_filter() .
            " where {" .
            " $graph_uri schema:mentions ?x." .
            " ?x rdf:type <$type>}}";

        $out = array();

        foreach ($this->endpoints as $endpoint) {
            $result = $endpoint->query($query_string);
            foreach ($result as $row)
                $out[] = $row->x;
        }
        return $out;
    }

    function get_resource_graph($uri, $permission_filter, $inference = null)
    {
        $uri = EasyRdf_Namespace::expand($uri);
        if (strpos($uri, "://") == false)
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

        return $combined_graph;

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

        return out;


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
        if ($this->pineapple_settings["AUTHORISATION_TYPE"]
            == "NONE"
        )
            return "";
        else {
            $dataspaces = array();
            if ($this->pineapple_settings["AUTHORISATION_TYPE"]
                == "DEBUG"
            )
                foreach ($this->pineapple_settings["AUTHORISED_DATASPACES"]
                         as $uddi)
                    $dataspaces[] = "litef://dataspaces/$uddi";
            else {
                foreach (getDataspaces() as $dataspace)
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
}