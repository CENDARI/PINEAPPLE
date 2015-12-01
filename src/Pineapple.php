<?php
namespace Pineapple;

use EasyRdf_Graph;
use EasyRdf_Namespace;
use Exception;


class ResourceNotFoundException extends Exception {
}

class AccessDeniedException extends Exception {
}

class Pineapple {

    private $triplestore;
    private $api;
    private $settings;

    function __construct(Api $api, TripleStore $triplestore, $settings) {
        $this->api = $api;
        $this->triplestore = $triplestore;
        $this->settings = $settings;
    }

    /**
     * Fetch data for a given resource.
     *
     * @param string $uddi the resource identifier
     * @return array an map of item data, including mentions
     * @throws ResourceNotFoundException
     */
    function getResource($uddi) {
        $graph = $this->getResourceGraph($uddi);
        return $this->getResourceData($uddi, $graph);
    }

    /**
     * Fetch mentions for a given resource.
     *
     * @param string $uddi the resource identifier
     * @return array an associative array of mention data, with
     *               mentions keyed to the type (event, person, etc)
     * @throws ResourceNotFoundException
     */
    function getResourceMentions($uddi) {

        $full_uri = EasyRdf_Namespace::expand("resource:" . $uddi);
        $query =

            "select distinct ?m ?type ?title " .
            $this->getPermissionFilter() .
            "where {" .
            "  <$full_uri> schema:mentions ?m ." .
            "  ?m a ?type ; " .
            "     schema:name ?title ." .
            "}";


        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $type = EasyRdf_Namespace::shorten($row->type->getUri());
            $types = array_key_exists($type, $out) ? $out[$type] : [];
            array_push($types, [
                "uri" => $row->m->getUri(),
                "type" => $type,
                "title" => $row->title->getValue()
            ]);
            $out[$type] = $types;
        }

        return $out;
    }

    /**
     * Fetch the named graph for a given resource.
     *
     * @param string $uddi the resource identifier
     * @return EasyRdf_Graph
     * @throws ResourceNotFoundException
     */
    function getResourceGraph($uddi) {
        $graph_uri = sprintf("resource:%s", $uddi);
        if (!$this->checkResourceExists($graph_uri)) {
            throw new ResourceNotFoundException($uddi);
        }
        $query =
            "select ?s ?p ?o " .
            $this->getPermissionFilter() .
            " where {" .
            " graph $graph_uri" .
            " {?s ?p ?o} }";

        $combined_graph = new EasyRdf_Graph($graph_uri);
        foreach ($this->triplestore->query($query) as $row) {
            $combined_graph->add($row->s, $row->p, $row->o);
        }
        return $combined_graph;
    }

    /**
     * Fetch a list of resource data, with an optional
     * filter on the title value.
     *
     * @param null $q an optional title filter string
     * @param int $from the start offset
     * @param int $limit the maximum returned items
     * @return array
     */
    function getResources($q = null, $from, $limit) {
        $query =

            "select distinct ?title ?identifier ?lastModified count(?m) as ?count " .
            $this->getPermissionFilter() .
            "where {" .
            "  ?s dc11:title ?title ; " .
            "     nao:lastModified ?lastModified ; " .
            "     nao:identifier ?identifier . " .
            " OPTIONAL { ?s schema:mentions ?m } ." .
            $this->getSearchFilter("?title", $q) .
            "} order by ASC(?title) " .
            "offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "title" => $row->title->getValue(),
                "lastModified" => $row->lastModified->getValue(),
                "numMentions" => $row->count->getValue()
            ]);
        }

        return $out;
    }

    /**
     * Fetch resources mentioned by a given item.
     *
     * @param string $type the prefixed item type
     * @param string $name the item's name
     * @param int $from the start offset
     * @param int $limit the maximum returned items
     * @return array
     */
    function getMentionResources($type, $name, $from, $limit) {
        $query =

            "select distinct ?identifier ?title " .
            $this->getPermissionFilter() .
            "where {" .
            "  [] schema:mentions [" .
            "        a $type ; " .
            // FIXME: literal type? why is this needed?
            "        schema:name \"$name\"^^xsd:string " .
            "     ] ; " .
            "     dc11:title ?title ; " .
            "     nao:identifier ?identifier . " .
            "} " .
            "offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "title" => $row->title->getValue()
            ]);
        }

        return $out;
    }

    /**
     * Fetch resources related to the current one via
     * mentions.
     *
     * @param string $uddi the resource identifier
     * @param int $from the start offset
     * @param int $limit the maximum returned items
     * @return array
     */
    function getRelatedResources($uddi, $from, $limit) {
        $query =

            "select distinct ?identifier ?title " .
            $this->getPermissionFilter() .
            "where {" .
            //"  <$graph_uri> schema:mentions ?m . " .
            "  ?s nao:identifier \"$uddi\"^^xsd:string . " .
            "  ?s schema:mentions ?m . " .
            "  ?r schema:mentions ?m . " .
            "  ?r dc11:title ?title ; " .
            "     nao:identifier ?identifier . " .
            "  FILTER (?r != ?s ) " .
            "} " .
            "offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "title" => $row->title->getValue()
            ]);
        }

        return $out;
    }

    /**
     * Fetch a list of access points of a given type which relate to
     * at least one other resource.
     *
     * @param string $type the (prefixed) rdf type
     * @param null $q an optional title filter string
     * @param int $from the start offset
     * @param int $limit the maximum returned items
     * @return array
     */
    public function getAccessPoints($type, $q = null, $from, $limit) {
        $typeUri = EasyRdf_Namespace::expand($type);
        $query =

            "select distinct ?s ?title " .
            $this->getPermissionFilter() .
            "where {" .
            "  ?s a <$typeUri> ; " .
            "     schema:name ?title . " .
            $this->getSearchFilter("?title", $q) .
            ($this->settings["limit_related_access_points"] ? (
                "  FILTER EXISTS { ".
                "    ?m schema:mentions ?s ;".
                "       nao:identifier [] ; ".
                "       dc11:title []. ".
                "  } "
            ) : "") .
            "} " .
            "offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "uri" => $row->s->getUri(),
                "type" => $type,
                "title" => $row->title->getValue()
            ]);
        }

        return $out;
    }

    /**
     * Check if a resource exists.
     *
     * @param string $uri the resource URI
     * @return bool
     */
    function checkResourceExists($uri) {
        $full_uri = EasyRdf_Namespace::expand($uri);
        $query_string = "ask { <$full_uri> ?p  ?o}";
        return $this->triplestore->ask($query_string);
    }

    private function getResourceData($uddi, EasyRdf_Graph $graph) {
        $uri = $graph->getUri();
        return [
            "id" => $graph->get($uri, 'nao:identifier')->getValue(),
            "title" => $graph->get($uri, 'dc11:title')->getValue(),
            "plainText" => $graph->get($uri, "nie:plainTextContent")->getValue(),
            "source" => $graph->get($uri, "dc11:source")->getValue(),
            "lastModified" => $graph->get($uri, "nao:lastModified")->getValue(),
            "mentions" => $this->getResourceMentions($uddi)
        ];
    }

    /**
     * Looks up which CKAN dataspaces the user
     * is allowed to access given the API key
     * and constructs a Sparql FROM clause for
     * named graphs that have an rdfs:member to
     * the litef://dataspaces/{DS} resource.
     * The config AUTHORISATION_TYPE value can
     * be one of: NONE, DEBUG, or ENFORCING, with
     * ENFORCING being the default.
     *
     * @return string a Sparql FROM clause
     */
    function getPermissionFilter() {
        $authType = $this->getAuthType();
        if ($this->getAuthType() === "NONE") {
            return "";
        }

        $dataspaces = [];

        if ($authType === "DEBUG") {
            foreach ($this->settings["AUTHORISED_DATASPACES"] as $uddi) {
                $dataspaces[] = $this->settings["dataspaces"] . $uddi;
            }
        } elseif ($authType === "ENFORCING") {
            foreach ($this->api->getDataspaces() as $dataspace) {
                $dataspaces[] = $this->settings["dataspaces"] . $dataspace["id"];
            }
        }

        $filter = [];
        foreach ($dataspaces as $ds) {
            $filter[] = "?ds = <$ds>";
        }
        $graphs_query =
            "select ?g where {" .
            " ?ds rdfs:member ?g" .
            " FILTER (" . implode(" || ", $filter) . ")}";

        $output = "";
        foreach ($this->triplestore->query($graphs_query) as $row) {
            $output = $output . "FROM <" . $row->g . ">\n";
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
        $chars = preg_replace("/[^\+\s\w]/", ' ', trim($q));
        $alts = implode("|", explode(" ", $chars));
        if (strlen($chars) === 0) {
            return "";
        } else {
            return " FILTER regex ($pred, \"$alts\", \"i\" )";
        }
    }

    private function getAuthType() {
        if (!isset($this->settings["AUTHORISATION_TYPE"])) {
            return "ENFORCING";
        }
        return $this->settings["AUTHORISATION_TYPE"];
    }
}