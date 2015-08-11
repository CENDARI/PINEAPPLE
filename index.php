<?php
require "vendor/autoload.php";
require_once "Pineapple.class.php";
require_once "render_html.php";


class PineappleRequest
{
    public $verb;
    public $action;
    public $resource;
    public $resource_is_uddi;
    public $accept_types;
    public $options;
    public $file_extension;

    static $FILE_MAPS = array(
        ".json" => "json",
        ".xml" => "rdf",
        ".rdf" => "rdf",
        ".html" => "html",
        ".htm" => "html");


    public function __construct()
    {
        $this->verb = $_SERVER["REQUEST_METHOD"];

        $this->content_type = false;
        if (isset($_SERVER["CONTENT_TYPE"]))
            $this->content_type = $_SERVER["CONTENT_TYPE"];

        $this->action = false;
        if (isset($_GET["function"]))
            $this->action = $_GET["function"];

        $this->resource = false;
        $this->resource_is_uddi = false;
        if (isset($_GET["resource"])) {
            $raw = $_GET["resource"];
            $this->resource = urldecode($raw);
            $this->resource_is_uddi = preg_match("/^[a-f0-9\-]+$/", $raw);
        }

        $this->accept_types = array();
        if (isset($_SERVER["ACCEPT"])) {
            $this->accept_types = explode(",", $_SERVER["ACCEPT"]);
        }

        $this->file_extension = "html";
        if (isset($_GET["format"])) {
            $this->file_extension = PineappleRequest::$FILE_MAPS[$_GET["format"]];
        }

        $this->inference = array();
        if (isset($_GET["inference"])) {
            foreach (explode(",", $_GET["inference"]) as $g)
                $this->inference[] = $g;
        }


    }

    function execute(Pineapple $pineapple, Twig_Environment $twig)
    {

        $document = null;
        if ($this->resource) {
            if ($this->action = "describe" && $this->resource_is_uddi) {
                $document = $pineapple->get_document_graph($this->resource, $this->inference);
            } else if ($this->action = "describe" && !$this->resource_is_uddi) {
                $document = $pineapple->get_resource_graph($this->resource, "", $this->inference);
            }
        }

        if ($this->file_extension != "html") {
            return $document->graph->serialise($this->file_extension);
        } else {
            return $twig->render("describe.html.twig", ["document" => $document]);
        }
    }
}

$loader = new Twig_Loader_Filesystem("templates");
$twig = new Twig_Environment($loader, ["debug" => getenv("APP_DEBUG"), "cache" => "cache"]);

$pineapple = new Pineapple();

$req = new PineappleRequest();

try {
    echo $req->execute($pineapple, $twig);
} catch (ResourceNotFoundException $e) {
    header("HTTP/1.0 404 Not Found");
    echo "404";
}
