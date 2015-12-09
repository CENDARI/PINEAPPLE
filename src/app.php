<?php
require __DIR__ . '/../vendor/autoload.php';

const DEFAULT_PAGINATION_LIMIT = 20;

function type_to_name($type) {
    $name = [
        "schema:Event" => "Events",
        "schema:Place" => "Places",
        "schema:Person" => "People",
        "schema:Organisation" => "Organisations",
        "edm:Place" => "Places",
        "edm:Event" => "Events"
    ];
    return array_key_exists($type, $name) ? $name[$type] : $type;
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
    new Twig_Extensions_Extension_I18n
);

$app->notFound(function() use ($app) {
    $app->render("404.html.twig", ["error" => ""]);
});

$app->error(function(\Exception $e) use ($app) {
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

// Access type-to-name via templates
$view->getEnvironment()->addFilter(new Twig_SimpleFilter("type_to_name", function ($type) {
    return type_to_name($type);
}));

// Turn skos:Concept into Concept
$view->getEnvironment()->addFilter(new Twig_SimpleFilter("strip_rdf_prefix", function ($type) {
    return substr($type, mb_strpos($type, ":") + 1);
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

function accessPointListPage(\Slim\Slim $app, \Pineapple\Pineapple $pineapple, $type) {
    $q = $app->request->get("q");
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);
    $items = $pineapple->getAccessPoints($type, $q, $offset, $limit);
    $data = [
        "type" => type_to_name($type),
        "offset" => $offset,
        "limit" => $limit,
        "query" => $q,
        "accessPoints" => $items,
    ];
    respond($app, "access_points.html.twig", $data);
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
})->name("home");

$app->get("/mention/:type/:name+", function ($type, $name_parts) use ($app, &$pineapple) {
    $name = join("/", $name_parts);
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);

    $mentions = $pineapple->getMentionResources($type, $name, $offset, $limit);
    $data = [
        "name" => $name,
        "type" => $type,
        "mentions" => $mentions,
        "offset" => $offset,
        "limit" => $limit
    ];
    respond($app, "mention.html.twig", $data);
})->name("mention");


$app->get("/mentions/:type/:id", function ($type, $id) use ($app, &$pineapple) {
    $mentions = $pineapple->getResourceMentions($type, $id);
    respond($app, "_mentions.html.twig", $mentions);
})->name("mentions");

$app->get("/ontologies", function () use ($app, &$settings, &$pineapple) {
    $q = $app->request->get("q");
    $type = $app->request->get("type");
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);
    $ont = $app->request->get("ontology");

    $data = [
        "types" => $pineapple->getOntologyResourceTypes($q, $type, $ont),
        "resources" => $pineapple->getOntologyResources($q, $type, $ont, $offset, $limit),
        "offset" => $offset,
        "limit" => $limit,
        "query" => $q,
        "type_facet" => $type
    ];

    respond($app, "ontology_resources.html.twig", $data);
})->name("ontologies");

$app->get("/ontology/:name+", function ($uri_parts) use ($app, &$settings, &$pineapple) {
    $uri = $settings["namespaces"]["ontology"] . join("/", $uri_parts); // . "#this";
    $data = $pineapple->getOntologyResource($uri);
    respond($app, "ontology_resource.html.twig", $data);
})->name("ontology-resource");

$app->get("/people", function () use ($app, &$pineapple) {
    accessPointListPage($app, $pineapple, "schema:Person");
})->name("people");

$app->get("/organisations", function () use ($app, &$pineapple) {
    accessPointListPage($app, $pineapple, "schema:Organisation");
})->name("organisations");

$app->get("/places", function () use ($app, &$pineapple) {
    accessPointListPage($app, $pineapple, "edm:Place");
})->name("places");

$app->get("/events", function () use ($app, &$pineapple) {
    accessPointListPage($app, $pineapple, "edm:Event");
})->name("events");

// Fallback, which handles URLs like:
// BASEURL/resources/f22c70aa-c640-4773-884d-076ac2f536c4.
// When Pineapple is mounted at http://resources.cendari.dariah.eu
// this therefore handles URI resolution
$app->get("/:type/:id+", function ($type, $id_parts) use ($app, &$settings, &$pineapple) {
    $id = join("/", $id_parts);
    $data = $pineapple->getResource($type, $id);

    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", DEFAULT_PAGINATION_LIMIT);
    $more = [
        "related" => $pineapple->getRelatedResources($type, $id, $offset, $limit),
        "offset" => $offset,
        "limit" => $limit
    ];
    respond($app, "resource.html.twig", array_merge($data, $more));
})->name("resource");