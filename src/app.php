<?php
require __DIR__ . '/../vendor/autoload.php';

// Ensure we're using UTF-8
mb_internal_encoding("UTF-8");

const DEFAULT_PAGINATION_LIMIT = 20;

// Relations to try and fetch, mapped to whether they require the
// Virtuoso-specific T_DISTINCT option (very sad face :( ... )
// It's about a billion times more efficient to query for relations
// in one go, but that prevents inference from determining inverse
// relations, which we need to do.
// The sad state of affairs is that we now ask every ontology resource
// if it's got relationships of these types in X separate queries...
$ontology_resource_relation_types = [
    "skos:broader" => false,
    "skos:narrower" => false,
    "skos:exactMatch" => true,
    "skos:related" => false
];

class PineappleTwigExtension extends \Twig_Extension {
    public function getName() {
        return 'pineapple';
    }

    public function getFunctions() {
        return [
            new \Twig_SimpleFunction('path', [$this, 'path'])
        ];
    }

    public function path( $appName = 'default') {
        return \Slim\Slim::getInstance($appName)->request->getPath();
    }
}

$app = new \Slim\Slim(array(
    "view" => new \Slim\Views\Twig(),
    "debug" => getenv("APP_DEBUG")
));

$log = $app->getLog();

$view = $app->view();
$view->parserOptions = array(
    "debug" => getenv("APP_DEBUG"),
    "cache" => __DIR__ . '/../cache'
);
$view->setTemplatesDirectory(__DIR__ . '/../templates');

$view->parserExtensions = array(
    new \Slim\Views\TwigExtension(),
    new Twig_Extensions_Extension_I18n,
    new PineappleTwigExtension()
);

$app->notFound(function () use ($app) {
    $app->render("404.html.twig", ["error" => ""]);
});

$app->error(function (\Exception $e) use ($app) {
    if ($e instanceof \Pineapple\ResourceNotFoundException) {
        $app->render("404.html.twig", ["error" => $e->getMessage()], 404);
    } else {
        //Invoke error handler
        error_log($e->getMessage());
        $app->render("500.html.twig", ["error" => $e->getMessage()], 500);
    }
});


$settings = parse_ini_file(__DIR__ . '/../settings.ini');

// Add a simple template function to format the millisecond date strings
// we get back from the triplestore
$view->getEnvironment()->addFilter(new Twig_SimpleFilter("pretty_date", function ($milliseconds) {
    return date("d-m-Y H:i", intval($milliseconds) / 1000);
}));

// Turn skos:Concept into Concept
$view->getEnvironment()->addFilter(new Twig_SimpleFilter("strip_rdf_prefix", function ($type) {
    return mb_strpos($type, ":") !== FALSE ? substr($type, mb_strpos($type, ":") + 1) : $type;
}));

// Turn skos:Concept into Concept
$view->getEnvironment()->addFilter(new Twig_SimpleFilter("strip_namespace", function ($uri) use (&$settings) {
    foreach ($settings["namespaces"] as $ns => $nsuri) {
        if (substr($uri, 0, mb_strlen($nsuri)) === $nsuri) {
            return substr($uri, mb_strlen($nsuri));
        }
    }
    return $uri;
}));

// Ugh: stopgap measure! camelCaseText to Title Case Text
// Probably won't work with non-ASCII
// Borrowed from: https://gist.github.com/justjkk/1402061
$view->getEnvironment()->addFilter(new Twig_SimpleFilter("camel_to_title", function ($camelStr) {
    $intermediate = preg_replace('/(?!^)([[:upper:]][[:lower:]]+)/', ' $0', $camelStr);
    return ucwords(preg_replace('/(?!^)([[:lower:]])([[:upper:]])/', '$1 $2', $intermediate));
}));

$view->getEnvironment()->addFilter(new Twig_SimpleFilter("snake_to_title", function ($snakeStr) {
    return ucwords(str_replace('_', ' ', $snakeStr));
}));

$api = new \Pineapple\Api($settings);
$triplestore = new \Pineapple\TripleStore($settings);

// Instantiate the Pineapple object where the interesting
// stuff happens
$pineapple = new \Pineapple\Pineapple($api, $triplestore, $settings);


/**
 * Return a response. The app supports HTML and JSON. JSON
 * will be render if either the accept header contains
 * application/json or the format parameter is `json`.
 *
 * @param \Slim\Slim $app
 * @param $template
 * @param $data
 */
function respond(\Slim\Slim $app, $template, $data) {
    $accept = $app->request->headers("accept");
    $format = $app->request->get("format");
    if (($accept != null && preg_match("/\/json|javascript/i", $accept))
        || ($format != null && strtolower($format) === "json")
    ) {
        // JSONP callback
        $callback = $app->request->get("callback") !== null
            ? preg_replace("/[^a-z0-9\$_]/si", "", $app->request->get("callback"))
            : false;
        $app->response->headers()->set("Access-Control-Allow-Origin", "*");
        $app->response->headers()->set("Content-type",
            "application/" . ($callback ? 'x-javascript' : 'json') . "; charset=UTF-8");
        echo ($callback ? $callback . "(" : "") . json_encode($data) . ($callback ? ")" : "");
    } else {
        $app->render($template, $data);
    }
}

$app->get("/", function () use ($app, &$pineapple) {
    $q = $app->request->get("q");
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);

    $list = $pineapple->getResources($q, $offset, $limit);
    $data = [
        "resources" => $list,
        "offset" => $offset,
        "limit" => $limit,
        "query" => $q
    ];
    respond($app, "resources.html.twig", $data);
})->name("resources");


$app->get("/ontologies", function () use ($app, &$settings, &$pineapple) {
    $q = $app->request->get("q");
    $type = $app->request->get("type");
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);
    $ont_facet = $app->request->get("ontology");

    $meta = $pineapple->getOntologyGraphMeta($settings["ontology_meta"]);
    $ont_uris = array_map(function ($v) { return $v["uri"]; }, $meta);
    $active_onts = $ont_uris;

    if (in_array($ont_facet, $ont_uris, true)) {
        $active_onts = [$ont_facet];
    } else {
        $ont_facet = null; // invalid
    }

    // We can't do anything useful without the graph metadata, so return
    // early if that's missing.
    $data = !empty($active_onts) ? [
        "types" => $pineapple->getOntologyResourceTypes($q, $type, $active_onts),
        "resources" => $pineapple->getOntologyResources($q, $type, $active_onts, $offset, $limit),
        "offset" => $offset,
        "limit" => $limit,
        "query" => $q,
        "type_facet" => $type,
        "ont_facet" => $ont_facet,
        "graph_meta" => $meta
    ] : [];

    respond($app, "ontology_resources.html.twig", $data);
})->name("ontologies");


$app->get("/ontology/:name+", function ($uri_parts) use ($app, &$settings, &$pineapple, &$ontology_resource_relation_types) {
    $id = join("/", array_map(function ($p) { return urlencode($p);}, $uri_parts));

    // Digging the hole ever deeper here. Due to inconsistency in resource URI
    // encoding, resolving them requires some sadness, namely, trying to
    // reconstruct the URI with and without encoding, and with or without
    // a trailing #this.
    // This should be removed when CENDARI URIs are more internally consistent.
    $id_non_enc = join("/", $uri_parts);
    $tries = [$id . "#this", $id_non_enc . "#this", $id, $id_non_enc];
    foreach ($tries as $try_uri) {
        try {
            $data = $pineapple->getOntologyResource($try_uri, $ontology_resource_relation_types);
            respond($app, "ontology_resource.html.twig", $data);
            return;
        } catch (\Pineapple\ResourceNotFoundException $e) {
            // Only throw when we've exhausted all attempts... :(
            error_log("Resolution failed: " . $try_uri);
        }
    }
    throw new \Pineapple\ResourceNotFoundException($settings["namespaces"]["ontology"].$id);
})->name("ontology-resource");

$app->get("/resources/:id+", function ($id_parts) use ($app, &$settings, &$pineapple) {
    $id = join("/", array_map(function ($p) { return urlencode($p);}, $id_parts));
    $data = $pineapple->getResource($id);

    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);
    $more = [
        "related" => $pineapple->getRelatedResources($id, $offset, $limit),
        "offset" => $offset,
        "limit" => $limit
    ];
    respond($app, "resource.html.twig", array_merge($data, $more));
})->name("resource");

$app->get("/medieval", function () use ($app, &$settings, &$api) {
    // NB: Since we're using a different endpoint for the medieval data, create
    // a new Triplestore and Pineapple instance containing just that data.
    $ts = new \Pineapple\TripleStore($settings, ["http://git-trame.fefonlus.it/sparql/"], [], "");
    $pineapple = new \Pineapple\Pineapple($api, $ts, $settings);

    $q = $app->request->get("q");
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);
    $author_facet = $app->request->get("author");
    $organisation_facet = $app->request->get("organisation");
    $organisation_order_facet = $app->request->get("organisation_order");
    $author_order_facet = $app->request->get("author_order");
    $num_facets = $app->request->get("num_facets", 10);

    $data = [
        "offset" => $offset,
        "limit" => $limit,
        "query" => $q,

        "resources" => $pineapple->getMedievalResources($q, $author_facet, $organisation_facet,
            $organisation_order_facet, $author_order_facet, $offset, $limit),

        "author_facet" => $author_facet,
        "authors" => $pineapple->getMedievalAuthors($q, $author_facet, $organisation_facet,
            $organisation_order_facet, $author_order_facet, 0, $num_facets),

        "organisation_facet" => $organisation_facet,
        "organisations" => $pineapple->getMedievalOrganisations($q, $author_facet, $organisation_facet,
            $organisation_order_facet, $author_order_facet, 0, $num_facets),
        
        "organisation_order_facet" => $organisation_order_facet,
        "organisation_orders" => $pineapple->getMedievalOrganisationOrders($q, $author_facet, $organisation_facet,
            $organisation_order_facet, $author_order_facet, 0, $num_facets),
        
        "author_order_facet" => $author_order_facet,
        "author_orders" => $pineapple->getMedievalAuthorOrders($q, $author_facet, $organisation_facet,
            $organisation_order_facet, $author_order_facet, 0, $num_facets)
    ];

    respond($app, "medieval_resources.html.twig", $data);
})->name("medieval");

// Fallback, which handles URLs like:
// BASEURL/resources/f22c70aa-c640-4773-884d-076ac2f536c4.
// When Pineapple is mounted at http://resources.cendari.dariah.eu
// this therefore handles URI resolution
$app->get("/:type/:name+", function ($type, $name_parts) use ($app, &$settings, &$pineapple) {
    $name = join("/", array_map("urlencode", $name_parts));
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);

    $data = $pineapple->getConcept($type, $name);
    $mentions = $pineapple->getMentionResources($type, $name, $offset, $limit);
    respond($app, "ontology_resource.html.twig",
        array_merge($data, [
            "mentions" => $mentions,
            "offset" => $offset,
            "limit" => $limit
        ])
    );
})->name("concept");