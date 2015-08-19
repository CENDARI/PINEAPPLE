<?php
require "vendor/autoload.php";

use Pineapple\Pineapple;

$app = new \Slim\Slim(array(
    "view" => new \Slim\Views\Twig(),
    "debug" => getenv("APP_DEBUG")
));

$log = $app->getLog();

$view = $app->view();
$view->parserOptions = array(
    "debug" => getenv("APP_DEBUG"),
    "cache" => dirname(__FILE__) . "/cache"
);
$view->parserExtensions = array(
    new \Slim\Views\TwigExtension(),
    new Twig_Extensions_Extension_I18n
);

// Add a simple template function to format the millisecond date strings
// we get back from the triplestore
$view->getEnvironment()->addFilter(new Twig_SimpleFilter("prettyDate", function ($milliseconds) {
    return date("d-m-Y H:i", intval($milliseconds) / 1000);
}));

$settings = parse_ini_file("settings.ini");
$filerepo = new \Pineapple\FileRepository($settings);
$triplestore = new \Pineapple\TripleStore($settings);

// Instantiate the Pineapple object where the interesting
// stuff happens
$pineapple = new \Pineapple\Pineapple($filerepo, $triplestore, $settings);


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
    if (($accept != null && preg_match("/\/json/i", $accept))
        || ($format != null && strtolower($format) === "json")
    ) {
        $app->response->headers()->set("Content-type", "application/json");
        echo json_encode($data);
    } else {
        $app->render($template, $data);
    }
}


$app->get("/", function () use ($app, &$pineapple) {
    $q = $app->request->get("q");
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", Pineapple::DEFAULT_PAGINATION_LIMIT);

    $list = $pineapple->getResources($q, $offset, $limit);
    $data = [
        "resources" => $list,
        "offset" => $offset,
        "limit" => $limit,
        "query" => $q
    ];
    respond($app, "list.html.twig", $data);
})->name("home");


$app->get("/resource/:id", function ($id) use ($app, &$pineapple) {
    $data = $pineapple->getResource($id);
    respond($app, "resource.html.twig", $data);
})->name("resource");


$app->get("/mention/:type/:name", function ($type, $name) use ($app, &$pineapple) {
    $offset = $app->request->get("offset", 0);
    $limit = $app->request->get("limit", Pineapple::DEFAULT_PAGINATION_LIMIT);

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


$app->get("/mentions/:id", function ($id) use ($app, &$pineapple) {
    $mentions = $pineapple->getResourceMentions($id);
    respond($app, "_mentions.html.twig", $mentions);
})->name("mentions");


$app->run();