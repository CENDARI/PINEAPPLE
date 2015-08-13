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
);

$pineapple = new Pineapple();
$req = new PineappleRequest();

$app->get("/", function() use($app, &$pineapple, &$req) {
    $list = $pineapple->get_all_graphs($req->offset, $req->limit);
    $app->render("list.html.twig", [
        "documents" => $list,
        "offset" => $req->offset,
        "limit" => $req->limit
    ]);
})->name("home");

$app->get("/describe/:id+", function($id) use($app, &$pineapple, &$req) {
    $document = $pineapple->get_document_graph(join("/", $id));
    $app->render("describe.html.twig", ["document" => $document]);
})->name("describe");


$app->run();