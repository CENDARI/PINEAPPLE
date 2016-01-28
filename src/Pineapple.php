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

            "select distinct ?title ?identifier ?lastModified ?plainText ?source \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  <$full_uri>\n" .
            "     nao:identifier ?identifier ; \n" .
            "     nie:plainTextContent ?plainText ;\n" .
            "     nao:lastModified ?lastModified . \n" .
            "  OPTIONAL { <$full_uri> dc11:title ?title }\n" .
            "  OPTIONAL { <$full_uri> dc11:source ?source } \n" .
            "} limit 1\n";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "id" => $uddi,
                "identifier" => $row->identifier->getValue(),
                "lastModified" => $row->lastModified->getValue(),
                "plainText" => $row->plainText->getValue(),
                "source" => property_exists($row, "source") ? $row->source->getValue() : "",
                "title" => property_exists($row, "title") ? $row->title->getValue() : $full_uri,
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

            "select distinct ?s ?title ?identifier ?lastModified \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  ?s nao:identifier ?identifier ; \n" .
            "     nao:lastModified ?lastModified ; \n" .
            "     nie:plainTextContent ?plainText . \n" .
            " OPTIONAL { ?s dc11:title ?title . } \n" .
            $this->getContainsFilter("?plainText", $q) .
            "}\n" .
            "offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "uri" => $row->s->getUri(),
                "id" => $row->identifier->getValue(),
                "identifier" => $row->identifier->getValue(),
                "lastModified" => $row->lastModified->getValue(),
                "title" => property_exists($row, "title")
                    ? $row->title->getValue()
                    : $row->s->getUri()
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
            "  <$full_uri> a ?type ; \n" .
            "      skos:prefLabel ?label . \n" .
            "  OPTIONAL { <$full_uri>  skos:note ?note .} \n" .
            "} limit 1";

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

        $out[0]["ontology_resources"] =
            $this->getCloseMatchOntologyResources($out[0]["prefLabel"], 0, 10);

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
            $m_type = EasyRdf_Namespace::splitUri($row->m->getUri(), TRUE);
            $m_type = $m_type[0];
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
        $uri = EasyRdf_Namespace::get($type) . $name;

        $query =

            "select distinct ?r ?identifier ?title \n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  ?r schema:mentions <$uri> ; \n" .
            "     nao:identifier ?identifier . \n" .
            "     OPTIONAL { ?r dc11:title ?title } \n" .
            "} offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $ns_uri = EasyRdf_Namespace::shorten($row->r->getUri());
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "type" => substr($ns_uri, 0, mb_strpos($ns_uri, ":")),
                "title" => property_exists($row, "title")
                    ? $row->title->getValue()
                    : $row->r->getUri()
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
            "  ?r nao:identifier ?identifier . \n" .
            "  OPTIONAL { ?r dc11:title ?title } \n" .
            "  FILTER (?r != <$uri> ) \n" .
            "} offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $ns_uri = EasyRdf_Namespace::shorten($row->r->getUri());
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "type" => substr($ns_uri, 0, mb_strpos($ns_uri, ":")),
                "title" => property_exists($row, "title")
                    ? $row->title->getValue()
                    : $row->r->getUri()
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
    function getCloseMatchResources($text, $from, $limit) {
        $query =

            "select distinct ?r ?identifier ?title ?sc\n" .
            $this->getPermissionFilter() .
            "where {\n" .
            "  ?r nie:plainTextContent ?plainText ; \n" .
            "     nao:identifier ?identifier . \n" .
            "  OPTIONAL { ?r dc11:title ?title } \n" .
            "  ?plainText bif:contains \"" . $this->getMatchQuery($text) . "\"\n" .
            "  OPTION (score ?sc)\n" .
            "} ORDER BY DESC (?sc)\n" .
            " OFFSET $from LIMIT $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $ns_uri = EasyRdf_Namespace::shorten($row->r->getUri());
            array_push($out, [
                "id" => $row->identifier->getValue(),
                "type" => substr($ns_uri, 0, mb_strpos($ns_uri, ":")),
                "title" => property_exists($row, "title")
                    ? $row->title->getValue()
                    : $row->r->getUri(),
                "score" => property_exists($row, "sc")
                    ? $row->sc->getValue() : 0
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
            $this->getContainsFilter("?title", $q) .
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

            "select distinct ?uri ?name ?description \n" .
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

        // NB: Hardcoding the types here is an optimisation, and for
        // some reason it makes a massive difference to query speed.
        $type_uris = $this->settings["ontology_item_types"];

        $query =

            "select distinct ?name (count (?s) as ?count) \n" .
            $this->getOntologyFromClause($ont) .
            "where {\n" .
                join( " UNION", array_map(function($uri) use ($q, $t) {
                    return "{ ?s a <$uri> . ?s a ?name . " .($t ? " ?s a $t . " : "") . "}\n";
                }, $type_uris)) .
            "} order by DESC(?count)";

        if ($q) {
            // If we have a text filter we can do a considerable simpler and
            // faster query...
            $query =

                "select distinct ?name (count (?s) as ?count) \n" .
                $this->getOntologyFromClause($ont) .
                "where {\n" .
                ($t ? " ?s a $t . " : "") .
                "  ?s skos:prefLabel ?prefLabel . \n" .
                $this->getContainsFilter("?prefLabel", $q) .
                $this->getLanguageFilter("?prefLabel") .
                "  ?s a ?name .\n" .
                "} order by DESC(?count)";
        }

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            $short_type = EasyRdf_Namespace::shorten($row->name->getUri());
            array_push($out, [
                "name" => $short_type ? $short_type : $row->name->getUri(),
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
            $this->getContainsFilter("?prefLabel", $q) .
            $this->getLanguageFilter("?prefLabel") .
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
     * Fetch ontology resources that match a given string.
     *
     * @param string $text the search query
     * @param int $from the start offset
     * @param int $limit the maximum returned items
     * @return array
     */
    function getCloseMatchOntologyResources($text, $from, $limit) {
        $onts = array_map(function ($v) {
            return $v["uri"];
        }, $this->getOntologyGraphMeta($this->settings["ontology_meta"]));

        $query =

            "select distinct ?s ?type STR(?prefLabel) as ?label ?sc\n" .
            $this->getOntologyFromClause($onts) .
            "where {\n" .
            "  ?s a ?type ; \n" .
            "     skos:prefLabel ?prefLabel . \n" .
            "  ?prefLabel bif:contains \"" . $this->getMatchQuery($text) . "\"\n" .
            "  OPTION (score ?sc)\n" .
            "} ORDER BY DESC (?sc)\n" .
            " OFFSET $from LIMIT $limit";

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

        $out[0]["relations"] = array_merge(
            $this->getOntologyResourceRelations($id),
            $this->getExplicitOntologyResourceRelations($id, $reltypes)
        );
        $out[0]["context"] = $this->getOntologyResourceContext($id);
        $out[0]["resources"] = $this->getCloseMatchResources($out[0]["prefLabel"], 0, 10);

        return $out[0];
    }

    /**
     * Fetch (optional) data about the graph context of an ontology
     * resource.
     *
     * @param string $id the ID of an ontology resource
     * @return array|null
     */
    public function getOntologyResourceContext($id) {
        $uri = EasyRdf_Namespace::get("ontology") . $id;

        $query =

            "select distinct ?g ?name ?description ?rights ?rightsRef ?references \n" .
            "where {\n" .
            "  graph ?g { <$uri> skos:prefLabel [] }.\n" .
            "  ?g dcterms:title ?name ;\n" .
            "     dcterms:abstract ?description .\n" .
            "  OPTIONAL {\n" .
            "     ?g dc11:rights ?rights ;\n" .
            "        dcterms:rights ?rightsRef ;\n" .
            "        dcterms:references ?references ." .
            "  } .\n" .
            "} limit 1";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "uri" => $row->g->getUri(),
                "name" => $row->name->getValue(),
                "description" => $row->description->getValue(),
                "rights" => property_exists($row, "rights") ? $row->rights->getValue() : "",
                "rightsRef" => property_exists($row, "rightsRef") ? $row->rightsRef->getUri() : "",
                "references" => property_exists($row, "references") ? $row->references->getUri() : ""
            ]);
        }
        return empty($out) ? null : $out[0];
    }

    /**
     * Get all relation data for an ontology resource.
     *
     * @param string $uri an ontology resource URI
     * @return array
     */
    public function getOntologyResourceRelations($id) {
        $uri = EasyRdf_Namespace::get("ontology") . $id;
        $query =
            "select distinct ?r ?p ?type ?prefLabel " .
            "where {" .
            "  <$uri> ?p ?r ." .
            "  ?r a ?type ; " .
            "    skos:prefLabel ?prefLabel . " .
            // FIXME: Hack: filter out SKOS relations since
            // we use inference of inverseOf reasoning.
            " FILTER (!REGEX( ?p, \"skos\")) ." .
            $this->getLanguageFilter("?prefLabel") .
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
                "prefLabel" => $row->prefLabel->getValue()
            ]);
        }
        return $out;
    }

    /**
     * Get specific relation types for an ontology resource.
     *
     * @param string $uri an ontology resource URI
     * @param ont array an array of ontologies
     * @param $reltypes array prefixed relationship types to search
     * @return array
     */
    public function getExplicitOntologyResourceRelations($id, $reltypes) {
        $uri = EasyRdf_Namespace::get("ontology") . $id;

        $out = [];

        foreach ($reltypes as $reltype => $is_distinct) {
            $query =

                "select distinct ?r ?type STR(?prefLabel) as ?label \n" .
                "where {\n" .
                "  <$uri> $reltype ?r " . ($is_distinct ? "OPTION (T_DISTINCT)" : "") . " .\n" .
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

    private function getMedievalDataPatterns() {
        // The basic patterns for selecting medieval data
        return <<<EOL

    ?id_opera <http://sismel.it/onto#hasAuthorID> ?id_autore ;
              <http://sismel.it/onto#hasTitle> ?nome_opera .
    ?id_autore <http://sismel.it/onto#hasName> ?nome_autore ;
               <http://sismel.it/onto#belongsToReligiousOrderID> ?id_relig_autore .
    ?id_relig_autore <http://sismel.it/onto#isReligiousOrder> ?nome_ordine_autore .
    ?mss <http://sismel.it/onto#hasName> ?mss_segnatura ;
         <http://sismel.it/onto#hasManuscriptSectionID> ?mss_section .
    ?mss_section <http://sismel.it/onto#hasOperaID> ?id_opera ;
                 <http://sismel.it/onto#hasHoldingID> ?id_ente ;
                 <http://sismel.it/onto#hasStartDate> ?data_mss ;
                 <http://sismel.it/onto#hasEndDate> ?data_end_mss .
    ?id_ente <http://sismel.it/onto#hasName> ?nome_ente ;
             <http://sismel.it/onto#belongsToReligiousOrderID> ?id_relig_ente ;
             <http://sismel.it/onto#hasInfo> ?info_ente .
    ?id_relig_ente <http://sismel.it/onto#isReligiousOrder> ?nome_ordine_ente .

EOL;
    }

    /**
     * Medieval data queries. No, this does not belong here.
     *
     * @param string|null $q an optional query
     * @param int $from the search offset
     * @param int $limit the search limit
     */
    public function getMedievalResources($q = null,
                                         $author_name = null,
                                         $organisation_name = null,
                                         $organisation_order = null,
                                         $author_order = null,
                                         $from,
                                         $limit) {

        $query =
            "select  distinct ?id_opera ?mss ?nome_opera ?mss_segnatura ?nome_autore ?data_mss ?data_end_mss\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getRegexFilters([
                "?nome_opera",
                "?nome_autore",
                "?info_ente",
                "?nome_ordine_ente",
                "?nome_ordine_autore"
            ], $q) .
            "  }" .
            "} offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "id_opera" => $row->id_opera->getUri(),
                "mss" => $row->mss->getUri(),
                "nome_opera" => $row->nome_opera->getValue(),
                "mss_segnatura" => $row->mss_segnatura->getValue(),
                "nome_autore" => $row->nome_autore->getValue(),
                "data_mss" => $row->data_mss->getValue(),
                "data_end_mss" => $row->data_end_mss->getValue()
            ]);
        }

        return $out;
    }

    /**
     * List of organisations, ordered by manuscript count
     *
     * @param null $q a query for the manuscript name
     * @param int $from search offset
     * @param int $limit search limit
     * @return array
     */
    public function getMedievalOrganisations($q = null,
                                             $author_name = null,
                                             $organisation_name = null,
                                             $organisation_order = null,
                                             $author_order = null,
                                             $from,
                                             $limit) {
        $query =

            "select  distinct ?info_ente count(?id_opera) as ?count\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getRegexFilters([
                "?nome_opera",
                "?nome_autore",
                "?info_ente",
                "?nome_ordine_ente",
                "?nome_ordine_autore"
            ], $q) .
            "  }\n" .
            "}  order by desc(?count) offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "name" => $row->info_ente->getValue(),
                "count" => $row->count->getValue()
            ]);
        }

        return $out;
    }

    /**
     * List of organisation's orders, ordered by manuscript count
     *
     * @param null $q a query for the manuscript name
     * @param int $from search offset
     * @param int $limit search limit
     * @return array
     */
    public function getMedievalOrganisationOrders($q,
                                                  $author_name = null,
                                                  $organisation_name = null,
                                                  $organisation_order = null,
                                                  $author_order = null,
                                                  $from,
                                                  $limit) {
        $query =

            "select  distinct ?nome_ordine_ente count(?id_opera) as ?count\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getRegexFilters([
                "?nome_opera",
                "?nome_autore",
                "?info_ente",
                "?nome_ordine_ente",
                "?nome_ordine_autore"
            ], $q) .
            "  }\n" .
            "}  order by desc(?count) offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "name" => $row->nome_ordine_ente->getValue(),
                "count" => $row->count->getValue()
            ]);
        }

        return $out;
    }

    /**
     * List of author's orders, ordered by manuscript count
     *
     * @param null $q a query for the manuscript name
     * @param int $from search offset
     * @param int $limit search limit
     * @return array
     */
    public function getMedievalAuthorOrders($q,
                                            $author_name = null,
                                            $organisation_name = null,
                                            $organisation_order = null,
                                            $author_order = null,
                                            $from,
                                            $limit) {
        $query =

            "select  distinct ?nome_ordine_autore count(?id_opera) as ?count\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getRegexFilters([
                "?nome_opera",
                "?nome_autore",
                "?info_ente",
                "?nome_ordine_ente",
                "?nome_ordine_autore"
            ], $q) .
            "  }\n" .
            "}  order by desc(?count) offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "name" => $row->nome_ordine_autore->getValue(),
                "count" => $row->count->getValue()
            ]);
        }

        return $out;
    }

    /**
     * Can a list of top authors, ordered by manuscript count
     *
     * @param null $q a query for the manuscript name
     * @param int $from search offset
     * @param int $limit search limit
     * @return array
     */
    public function getMedievalAuthors($q = null,
                                       $author_name = null,
                                       $organisation_name = null,
                                       $organisation_order = null,
                                       $author_order = null,
                                       $from,
                                       $limit) {

        $query =

            "select  distinct ?nome_autore count(?id_opera) as ?count\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getRegexFilters([
                "?nome_opera",
                "?nome_autore",
                "?info_ente",
                "?nome_ordine_ente",
                "?nome_ordine_autore"
            ], $q) .
            "  }\n" .
            "} order by desc(?count) offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "name" => $row->nome_autore->getValue(),
                "count" => $row->count->getValue()
            ]);
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

    private function getRegexFilters($predList, $q) {
        if ($q == null || trim($q) === "") {
            return "";
        }

        $filters = array_filter(array_map(function ($p) use ($q) {
            return $this->getRegexFilterPredicate($p, $q);
        }, $predList), function ($f) {
            return !empty($f);
        });
        return empty($filters) ? "" : "FILTER(" . join(" || ", $filters) . ")";
    }

    private function getRegexFilterPredicate($pred, $q) {
        $words = explode(" ", trim($q));
        $res = array_map(function ($word) use ($pred) {
            $chars = preg_replace("/\\\\/", '\\\\\\', preg_quote(trim($word)));
            return "regex($pred, '$chars', 'i' )";
        }, $words);
        return "(" . join(" && ", $res) . ")";
    }

    private function getContainsFilter($pred, $q) {
        if ($q == null || trim($q) === "") {
            return "";
        }

        $query = $this->getMatchQuery($q);
        return "FILTER(bif:contains($pred, \"$query\"))\n";
    }

    private function getMatchQuery($q) {
        $cleaned = $this->stripPunctuation(trim($q));
        $words = mb_split(" ", $cleaned);
        $remove_keywords = array_filter($words, function ($t) {
            return !in_array($t, ["or", "and"]);
        });
        $quoted = array_map(function($w) {
            return "'" . trim($w) . "'";
        }, $remove_keywords);
        return join(" AND ", $quoted);
    }

    private function getExactFilter($pred, $q, $op = "=") {
        if ($q == null || trim($q) === "") {
            return "";
        }

        return "FILTER ($pred $op \"$q\") .\n";
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

    private function stripPunctuation($string) {
        $string = preg_replace("/'/", "", $string);
        $string = mb_strtolower($string);
        $string = preg_replace("/[[:punct:]]+/", " ", $string);
        $string = preg_replace("/\\s+/", " ", $string);
        return trim($string);
    }
}
