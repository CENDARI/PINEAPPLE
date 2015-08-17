<?php
require "vendor/autoload.php";
require_once "Pineapple.class.php";
require_once "PineappleRequest.class.php";
require_once "render_html.php";

$app = new \Slim\Slim(array(
    "view" => new \Slim\Views\Twig()
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

$pineapple = new Pineapple();
$req = new PineappleRequest();

$app->get("/", function() use($app, &$pineapple, &$req) {
    $q = isset($_GET["q"]) ? $_GET["q"] : null;
    $list = $pineapple->get_all_resources($q, $req->offset, $req->limit);
    $app->render("list.html.twig", [
        "documents" => $list,
        "offset" => $req->offset,
        "limit" => $req->limit,
        "query" => $q
    ]);
})->name("home");

$app->get("/describe/:id+", function($id) use($app, &$pineapple, &$req) {
    $document = $pineapple->get_document_graph(join("/", $id));
    if ($req->file_extension === "html") {
        $app->render("describe.html.twig", ["document" => $document]);
    } else {
        echo $document->graph->serialise($req->file_extension);
    }
})->name("describe");

$app->get("/mention/:type/:name", function($type, $name) use($app, &$pineapple, &$req) {
    $mentions = $pineapple->get_document_mention_individuals(
        $type, $name, $req->offset, $req->limit);
    $app->render("mention.html.twig", [
            "name" => $name,
            "type" => $type,
            "mentions" => $mentions,
            "offset" => $req->offset,
            "limit" => $req->limit,
        ]
    );
})->name("mention");


$app->run();