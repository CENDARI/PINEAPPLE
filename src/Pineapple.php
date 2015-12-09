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
    function getResource($type, $uddi) {
        $graph = $this->getResourceGraph($type, $uddi);
        return $this->getResourceData($type, $uddi, $graph);
    }

    /**
     * Fetch mentions for a given resource.
     *
     * @param string $uddi the resource identifier
     * @return array an associative array of mention data, with
     *               mentions keyed to the type (event, person, etc)
     * @throws ResourceNotFoundException
     */
    function getResourceMentions($type, $uddi) {

        $full_uri = EasyRdf_Namespace::expand(sprintf("%s:%s", $type, $uddi));
        $query =

            "select distinct ?m ?type ?title " .
            $this->getPermissionFilter() .
            "where {" .
            "  <$full_uri> schema:mentions ?m ." .
            "  ?m a ?type ; " .
            "     skos:prefLabel ?title ." .
            "}";


        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $m_type = EasyRdf_Namespace::shorten($row->type->getUri());
            $types = array_key_exists($type, $out) ? $out[$type] : [];
            array_push($types, [
                "uri" => $row->m->getUri(),
                "type" => $m_type,
                "title" => $row->title->getValue()
            ]);
            $out[$m_type] = $types;
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
    function getResourceGraph($type, $uddi) {
        $graph_uri = sprintf("%s:%s", $type, $uddi);
        if (!$this->checkResourceExists($graph_uri)) {
            throw new ResourceNotFoundException($graph_uri);
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

            "select distinct ?s ?title ?identifier ?lastModified count(?m) as ?count " .
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
            $ns_uri = EasyRdf_Namespace::shorten($row->s->getUri());
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "type" => substr($ns_uri, 0, mb_strpos($ns_uri, ":")),
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

            "select distinct ?r ?identifier ?title " .
            $this->getPermissionFilter() .
            "where {" .
            "  ?r schema:mentions [" .
            "        a $type ; " .
            // FIXME: literal type? why is this needed?
            "        skos:prefLabel \"$name\"^^xsd:string " .
            "     ] ; " .
            "     dc11:title ?title ; " .
            "     nao:identifier ?identifier . " .
            "} " .
            "offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $ns_uri = EasyRdf_Namespace::shorten($row->r->getUri());
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "type" => substr($ns_uri, 0, mb_strpos($ns_uri, ":")),
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
    function getRelatedResources($type, $uddi, $from, $limit) {
        $uri = EasyRdf_Namespace::expand(sprintf("%s:%s", $type, $uddi));
        $query =

            "select distinct ?r ?identifier ?title " .
            $this->getPermissionFilter() .
            "where {" .
            "  <$uri> schema:mentions ?m . " .
            "  ?r schema:mentions ?m . " .
            "  ?r dc11:title ?title ; " .
            "     nao:identifier ?identifier . " .
            "  FILTER (?r != <$uri> ) " .
            "} " .
            "offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $ns_uri = EasyRdf_Namespace::shorten($row->r->getUri());
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "type" => substr($ns_uri, 0, mb_strpos($ns_uri, ":")),
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
            "     skos:prefLabel ?title . " .
            $this->getSearchFilter("?title", $q) .
            ($this->settings["limit_related_access_points"] ? (
                "  FILTER EXISTS { " .
                "    ?m schema:mentions ?s ;" .
                "       nao:identifier [] ; " .
                "       dc11:title []. " .
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
     * Get distinct entity types contained within the specified ontology graphs.
     *
     * @param null $q an optional title filter string
     * @param null $t an optional (prefixed) type
     * @param array $ont_ids a list of ontology IDs to constrain the search
     * @return array
     */
    public function getOntologyResourceTypes($q = null, $t = null, $ont = null) {

        $query =

            "select distinct ?type (count (?s) as ?count) " .
            $this->getOntologyFromClause($ont) .
            "where {" .
            "  ?s a ?type . " .
            ($t ? " ?s a $t . " : "") .
            ($q ? ("?s skos:prefLabel ?prefLabel . " .
                $this->getSearchFilter("?prefLabel", $q)) : "") .
            "}";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $short_type = EasyRdf_Namespace::shorten($row->type->getUri());
            $type = $short_type ? $short_type : $row->type->getUri();
            array_push($out, [
                "type" => $type,
                // FIXME: Temporary solution
                "name" => substr($type, strpos($type, ":") + 1),
                "count" => $row->count->getValue()
            ]);
        }
        return $out;
    }

    /**
     * Get data for resources contained within a set of given named graphs.
     *
     * @param null $q an optional title filter string
     * @param null $t an optional type constraint
     * @param null $ont the ID of a specific ontology URI, as defined in the settings.ini
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function getOntologyResources($q = null, $t = null, $ont = null, $from, $limit) {

        $query =

            "select distinct ?s ?type ?prefLabel ?note " .
            $this->getOntologyFromClause($ont) .
            "where {" .
            "  ?s a ?type ; " .
            ($t ? " a $t ; " : "") .
            "     skos:prefLabel ?prefLabel . " .
            " OPTIONAL { ?s skos:note ?note } ." .
            $this->getSearchFilter("?prefLabel", $q) .
            "} offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $short_type = EasyRdf_Namespace::shorten($row->type->getUri());
            array_push($out, [
                "uri" => $row->s->getUri(),
                "type" => $short_type ? $short_type : $row->type->getUri(),
                "prefLabel" => $row->prefLabel->getValue(),
                "note" => property_exists($row, "note") ? $row->note->getValue() : ""
            ]);
        }
        return $out;
    }

    /**
     * Get data for an ontology resource.
     *
     * @param string $uri an ontology resource URI
     * @return array
     */
    public function getOntologyResource($uri) {

        $query =

            "select ?type ?prefLabel ?note ?lat ?long " .
            $this->getOntologyFromClause() .
            "where {" .
            "  <$uri> a ?type ; " .
            "    skos:prefLabel ?prefLabel . " .
            "    OPTIONAL { <$uri> skos:note ?note . }." .
            "    OPTIONAL {" .
            "      <$uri> geo:lat ?lat ;" .
            "             geo:lat ?long ." .
            "    }." .
            "}";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $short_type = EasyRdf_Namespace::shorten($row->type->getUri());
            array_push($out, [
                "uri" => $uri,
                "type" => $short_type ? $short_type : $row->type->getUri(),
                "prefLabel" => $row->prefLabel->getValue(),
                "note" => property_exists($row, "note") ? $row->note->getValue() : "",
                "lat" => property_exists($row, "lat") ? $row->lat->getValue() : "",
                "long" => property_exists($row, "long") ? $row->long->getValue() : ""
            ]);
        }
        if (empty($out)) {
            throw new ResourceNotFoundException($uri);
        }

        $out[0]["relations"] = $this->getOntologyResourceRelations($uri);

        return $out[0];
    }

    /**
     * Get data for an ontology resource.
     *
     * @param string $uri an ontology resource URI
     * @return array
     */
    public function getOntologyResourceRelations($uri) {

        $query =

            "select ?r ?p ?type ?prefLabel ?note ?lat ?long " .
            $this->getOntologyFromClause() .
            "where {" .
            "  <$uri> ?p ?r ." .
            "  ?r a ?type ; " .
            "    skos:prefLabel ?prefLabel . " .
            "    OPTIONAL { <$uri> skos:note ?note . }." .
            "    OPTIONAL {" .
            "      <$uri> geo:lat ?lat ;" .
            "            geo:lat ?long ." .
            "    }." .
            "}";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $short_pred = EasyRdf_Namespace::shorten($row->p->getUri());
            $short_type = EasyRdf_Namespace::shorten($row->type->getUri());
            if (!array_key_exists($short_pred, $out)) {
                $out[$short_pred] = [];
            }
            array_push($out[$short_pred], [
                "uri" => $row->r->getUri(),
                "type" => $short_type ? $short_type : $row->type->getUri(),
                "prefLabel" => $row->prefLabel->getValue(),
                "note" => property_exists($row, "note") ? $row->note->getValue() : "",
                "lat" => property_exists($row, "lat") ? $row->lat->getValue() : "",
                "long" => property_exists($row, "long") ? $row->long->getValue() : ""
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

    private function getResourceData($type, $uddi, EasyRdf_Graph $graph) {
        $uri = EasyRdf_Namespace::expand($graph->getUri());
        return [
            "id" => $uddi,
            "type" => $type,
            "title" => $graph->get($uri, 'dc11:title')->getValue(),
            "plainText" => $graph->get($uri, "nie:plainTextContent")->getValue(),
            "source" => $graph->get($uri, "dc11:source")->getValue(),
            "lastModified" => $graph->get($uri, "nao:lastModified")->getValue(),
            "mentions" => $this->getResourceMentions($type, $uddi)
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
            return " FILTER isLiteral($pred) ." .
            " FILTER regex ($pred, \"$alts\", \"i\" ) .";
        }
    }

    private function getAuthType() {
        if (!isset($this->settings["AUTHORISATION_TYPE"])) {
            return "ENFORCING";
        }
        return $this->settings["AUTHORISATION_TYPE"];
    }

    private function getOntologyFromClause($filter = null) {

        $uris = array_filter(array_values($this->settings["ontologies"]), function ($v) use ($filter) {
            return empty($filter) ? true : $filter == $v;
        });

        return join("\n", array_map(function ($v) {
            return "FROM <$v> ";
        }, $uris));
    }
}
