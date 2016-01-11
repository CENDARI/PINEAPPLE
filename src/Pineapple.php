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
            "     nao:lastModified ?lastModified ; \n" .
            "     nie:plainTextContent ?plainText ; \n" .
            "     dc11:source ?source . \n" .
            "  OPTIONAL { <$full_uri> dc11:title ?title . } \n" .
            "} limit 1\n";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "id" => $uddi,
                "identifier" => $row->identifier->getValue(),
                "lastModified" => $row->lastModified->getValue(),
                "plainText" => $row->plainText->getValue(),
                "source" => $row->source->getValue(),
                "title" => property_exists($row, "title")
                    ? $row->title->getValue()
                    : $row->identifier->getValue(),
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
                "identifier" => $row->identifier->getValue(),
                "lastModified" => $row->lastModified->getValue(),
                "title" => $row->title->getValue(),
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
            "  <$full_uri> a ?type ; \n" .
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

        $query =

            "select distinct ?name (count (?s) as ?count) \n" .
            $this->getOntologyFromClause($ont) .
            "where {\n" .
            "  ?s a ?name . \n" .
            ($t ? " ?s a $t . " : "") .
            ($q ? ("?s skos:prefLabel ?prefLabel . \n" .
                $this->getLanguageFilter("?prefLabel") .
                $this->getSearchFilter("?prefLabel", $q)) : "") .
            "} order by DESC(?count)";

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

        $out[0]["relations"] = array_merge(
            $this->getOntologyResourceRelations($id),
            $this->getExplicitOntologyResourceRelations($id, $reltypes)
        );
        $out[0]["context"] = $this->getOntologyResourceContext($id);

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
                 <http://sismel.it/onto#hasStartDate> ?data_mss .
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
            "select  distinct ?mss ?mss_segnatura ?nome_opera ?nome_autore ?nome_ordine_autore ?nome_ente ?nome_ordine_ente ?info_ente ?data_mss\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getSearchFilter("?nome_opera", $q) .
            "  }" .
            "} offset $from limit $limit";

        $out = [];
        foreach ($this->triplestore->query($query) as $row) {
            array_push($out, [
                "mss" => $row->mss->getUri(),
                "nome_opera" => $row->nome_opera->getValue(),
                "mss_segnatura" => $row->mss_segnatura->getValue(),
                "nome_autore" => $row->nome_autore->getValue(),
                "nome_ordine_autore" => $row->nome_ordine_autore->getValue(),
                "nome_ente" => $row->nome_ente->getValue(),
                "nome_ordine_ente" => $row->nome_ordine_ente->getValue(),
                "info_ente" => $row->info_ente->getValue(),
                "data_mss" => $row->data_mss->getValue()
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
                                             $limit)
    {
        $query =

            "select  distinct ?info_ente count(?id_opera) as ?count\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getSearchFilter("?nome_opera", $q) .
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
                                                  $limit)
    {
        $query =

            "select  distinct ?nome_ordine_ente count(?id_opera) as ?count\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getSearchFilter("?nome_opera", $q) .
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
                                            $limit)
    {
        $query =

            "select  distinct ?nome_ordine_autore count(?id_opera) as ?count\n" .
            "where  {\n" .
            "  GRAPH <http://sismel/mdv> {\n" .
            $this->getMedievalDataPatterns() .
            $this->getExactFilter("?nome_autore", $author_name) .
            $this->getExactFilter("?info_ente", $organisation_name) .
            $this->getExactFilter("?nome_ordine_ente", $organisation_order) .
            $this->getExactFilter("?nome_ordine_autore", $author_order) .
            $this->getSearchFilter("?nome_opera", $q) .
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
            $this->getSearchFilter("?nome_opera", $q) .
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

}
