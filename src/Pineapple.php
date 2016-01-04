<?php
namespace Pineapple;

use EasyRdf_Namespace;
use Exception;

const DEFAULT_LANG = "en";


class ResourceNotFoundException extends Exception {
}

class AccessDeniedException extends Exception {
}

class Pineapple {

    private $triplestore;
    private $api;
    private $settings;
    private $lang;

    function __construct(Api $api, TripleStore $triplestore, $settings, $preferredLang = DEFAULT_LANG) {
        $this->api = $api;
        $this->triplestore = $triplestore;
        $this->settings = $settings;
        $this->lang = $preferredLang;
    }

    function withPreferredLang($lang) {
        $code = substr($lang, 0, 2);
        return new Pineapple($this->api, $this->triplestore, $this->settings, $code);
    }

    /**
     * Fetch data for a given resource.
     *
     * @param string $uddi the resource identifier
     * @return array an map of item data, including mentions
     * @throws ResourceNotFoundException
     */
    function getResource($uddi) {
        $full_uri = EasyRdf_Namespace::get("resources") . $uddi;

        $query =

            "select distinct ?title ?label ?identifier ?lastModified ?plainText ?source \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  <$full_uri> dc11:title ?title ; \n" .
            "     nao:identifier ?identifier ; \n" .
            "     nao:lastModified ?lastModified ; \n" .
            "     nie:plainTextContent ?plainText ; \n" .
            "     dc11:source ?source . \n" .
            "} limit 1\n";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "id" => $uddi,
                "title" => $row->title->getValue(),
                "identifier" => $row->title->getValue(),
                "lastModified" => $row->lastModified->getValue(),
                "plainText" => $row->plainText->getValue(),
                "source" => $row->source->getValue(),
            ]);
        }

        if (empty($out)) {
            throw new ResourceNotFoundException($full_uri);
        }

        $out[0]["mentions"] = $this->getResourceMentions($uddi);

        return $out[0];
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

            "select distinct ?title ?identifier ?lastModified count(?m) as ?count \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  ?s dc11:title ?title ; \n" .
            "     nao:identifier ?identifier ; \n" .
            "     nao:lastModified ?lastModified . \n" .
            " OPTIONAL { ?s schema:mentions ?m } .\n" .
            $this->getSearchFilter("?title", $q) .
            "} order by ASC(?title) \n" .
            "offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "title" => $row->title->getValue(),
                "identifier" => $row->identifier->getValue(),
                "lastModified" => $row->lastModified->getValue(),
                "numMentions" => $row->count->getValue()
            ]);
        }

        return $out;
    }

    /**
     * Fetch data for a CENDARI concept (and item defined by having
     * a prefLabel and an optional note.)
     *
     * @param string $uddi the resource identifier
     * @return array an map of item data, including mentions
     * @throws ResourceNotFoundException
     */
    function getConcept($type, $uddi) {
        $full_uri = EasyRdf_Namespace::get($type) . $uddi;

        $query =

            "select distinct ?type ?label ?note \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  <$full_uri> a ?type ; \n".
            "      skos:prefLabel ?label . \n" .
            "  OPTIONAL { <$full_uri>  skos:note ?note .} \n" .
            "}";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $short_type = EasyRdf_Namespace::shorten($row->type->getUri());
            array_push($out, [
                "uri" => $full_uri,
                "type" => $short_type ? $short_type : $row->type->getUri(),
                "prefLabel" => $row->label->getValue(),
                "note" => property_exists($row, "note") ? $row->note->getValue() : ""
            ]);
        }

        if (empty($out)) {
            throw new ResourceNotFoundException($full_uri);
        }

        return $out[0];
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

        $full_uri = EasyRdf_Namespace::get("resources") . $uddi;
        $query =

            "select distinct ?m ?type ?title \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  <$full_uri> schema:mentions ?m .\n" .
            "  ?m a ?type ; \n" .
            "     skos:prefLabel ?title .\n" .
            "}";


        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $m_type = EasyRdf_Namespace::shorten($row->type->getUri());
            $m_type = $m_type ? $m_type : $row->type->getUri();
            $types = array_key_exists($m_type, $out) ? $out[$m_type] : [];
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

            "select distinct ?r ?identifier ?title \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  ?r schema:mentions [\n" .
            //"        a $type ; " .
            // FIXME: literal type? why is this needed?
            "        skos:prefLabel \"$name\"^^xsd:string \n" .
            "     ] ; \n" .
            "     dc11:title ?title ; \n" .
            "     nao:identifier ?identifier . \n" .
            "} offset $from limit $limit";

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
    function getRelatedResources($uddi, $from, $limit) {
        $uri = EasyRdf_Namespace::get("resources") . $uddi;
        $query =

            "select distinct ?r ?identifier ?title \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  <$uri> schema:mentions ?m . \n" .
            "  ?r schema:mentions ?m . \n" .
            "  ?r dc11:title ?title ; \n" .
            "     nao:identifier ?identifier . \n" .
            "  FILTER (?r != <$uri> ) \n" .
            "} offset $from limit $limit";

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
        $type_uri = EasyRdf_Namespace::expand($type);
        $query =

            "select distinct ?s ?title \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  ?s a <$type_uri> ; \n" .
            "     skos:prefLabel ?title . \n" .
            $this->getSearchFilter("?title", $q) .
            ($this->settings["limit_related_access_points"] ? (
                "  FILTER EXISTS { \n" .
                "    ?m schema:mentions ?s ;\n" .
                "       nao:identifier [] ; \n" .
                "       dc11:title []. \n" .
                "  } \n"
            ) : "") .
            "} \n" .
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

    public function getOntologyGraphMeta($meta_uri) {
        $agg_pred = "http://www.openarchives.org/ore/terms/aggregates";
        $query =

            "select distinct ?uri ?name ?description \n".
            "where {\n" .
            "  <$meta_uri> <$agg_pred> ?uri . \n" .
            "    ?uri dcterms:title ?name ;\n" .
            "       dcterms:abstract ?description .\n" .
            "}";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "uri" => $row->uri->getUri(),
                "name" => $row->name->getValue(),
                "description" => $row->description->getValue()
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
    public function getOntologyResourceTypes($q = null, $t = null, $ont = []) {

        $query =

            "select distinct ?type (count (?s) as ?count) \n" .
            $this->getOntologyFromClause($ont) .
            "where {\n" .
            "  ?s a ?type . \n" .
            ($t ? " ?s a $t . " : "") .
            ($q ? ("?s skos:prefLabel ?prefLabel . \n" .
            $this->getLanguageFilter("?prefLabel") .
            $this->getSearchFilter("?prefLabel", $q)) : "") .
            "}";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $short_type = EasyRdf_Namespace::shorten($row->type->getUri());
            $type = $short_type ? $short_type : $row->type->getUri();
            array_push($out, [
                "type" => $type,
                "name" => $type,
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
    public function getOntologyResources($q = null, $t = null, $ont = [], $from, $limit) {

        $query =

            "select distinct ?s ?type STR(?prefLabel) as ?label \n" .
            $this->getOntologyFromClause($ont) .
            "where {\n" .
            "  ?s a ?type ; \n" .
            ($t ? " a $t ; " : "") .
            "     skos:prefLabel ?prefLabel . \n" .
            $this->getLanguageFilter("?prefLabel") .
            $this->getSearchFilter("?prefLabel", $q) .
            "} offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $short_type = EasyRdf_Namespace::shorten($row->type->getUri());
            array_push($out, [
                "uri" => $row->s->getUri(),
                "type" => $short_type ? $short_type : $row->type->getUri(),
                "prefLabel" => $row->label->getValue(),
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
    public function getOntologyResource($id, $reltypes) {
        $uri = EasyRdf_Namespace::get("ontology") . $id;

        $query =

            "select ?type ?prefLabel ?note ?lat ?long \n" .
            "where {\n" .
            "  <$uri> a ?type ; \n" .
            "    skos:prefLabel ?prefLabel . \n" .
            "    OPTIONAL { <$uri> skos:note ?note . }.\n" .
            $this->getLanguageFilter("?prefLabel") .
            $this->getLanguageFilter("?note") .
            "    OPTIONAL {\n" .
            "      <$uri> geo:lat ?lat ;\n" .
            "             geo:lat ?long .\n" .
            "    }.\n" .
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

        $out[0]["relations"] = $this->getOntologyResourceRelations($id, $reltypes);

        return $out[0];
    }

    /**
     * Get data for an ontology resource.
     *
     * @param string $uri an ontology resource URI
     * @param ont array an array of ontologies
     * @param $reltypes array prefixed relationship types to search
     * @return array
     */
    public function getOntologyResourceRelations($id, $reltypes) {
        $uri = EasyRdf_Namespace::get("ontology") . $id;

        $out = [];

        foreach ($reltypes as $reltype => $is_distinct) {
            $query =

                "select distinct ?r ?type STR(?prefLabel) as ?label \n" .
                "where {\n" .
                "  <$uri> $reltype ?r " . ($is_distinct ? "OPTION (T_DISTINCT)" : "") ." .\n" .
                "  ?r a ?type ; \n" .
                "    skos:prefLabel ?prefLabel . \n" .
                $this->getLanguageFilter("?prefLabel") .
                "}";

            foreach ($this->triplestore->query($query) as $row) {
                $short_type = EasyRdf_Namespace::shorten($row->type->getUri());
                if (!array_key_exists($reltype, $out)) {
                    $out[$reltype] = [];
                }
                array_push($out[$reltype], [
                    "uri" => $row->r->getUri(),
                    "type" => $short_type ? $short_type : $row->type->getUri(),
                    "prefLabel" => $row->label->getValue(),
                ]);
            }
        }

        return $out;
    }

    /**
     * Check if a resource exists.
     *
     * @param string $uri the resource unprefixed URI
     * @return bool
     */
    function checkResourceExists($full_uri) {
        $query_string = "ask { <$full_uri> ?p  ?o}";
        return $this->triplestore->ask($query_string);
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
            " FILTER regex ($pred, \"$alts\", \"i\" ) .\n";
        }
    }

    private function getLanguageFilter($pred) {
        return "FILTER(LANG($pred) = \"\" || LANGMATCHES(LANG($pred), \"" . $this->lang . "\")) \n";
    }

    private function getAuthType() {
        if (!isset($this->settings["AUTHORISATION_TYPE"])) {
            return "ENFORCING";
        }
        return $this->settings["AUTHORISATION_TYPE"];
    }

    private function getOntologyFromClause($uris = []) {
        return join("\n", array_map(function ($v) {
            return "FROM <$v> ";
        }, $uris));
    }
}
