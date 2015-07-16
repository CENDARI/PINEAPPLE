<?php
require_once "pineapple.php";
#require_once "dataspaceUtils.php";

class PineappleRequest{
   public $verb;
   public $action;
   public $resource;
   public $resource_is_uddi;
   public $accept_types;
   public $options;
   public $file_extention;

   static $FILE_MAPS = array (
      ".json" => "json",
      ".xml" => "rdf",
      ".rdf" => "rdf",
      ".html" => "html",
      ".htm" => "html");
 

   public function __construct($pineapple=null)
   {
      $this->pineapple = $pineapple;
      $this->verb = $_SERVER["REQUEST_METHOD"];
      
      $this->content_type = false;
      if(isset($_SERVER["CONTENT_TYPE"]))
         $this->content_type= $_SERVER["CONTENT_TYPE"];

      $this->action = false;
      if(isset($_GET["funciton"]))
         $this->action = $_GET["function"];

      $this->resource = false;
      $this->resource_is_uddi = false;
      if(isset($_GET["resource"]))
      {
         $this->resource = $_GET["resource"];
         $this->resource_is_uddi = preg_match("/^[a-f0-9\-]+$/",$this->resource);
      }

      $this->accept_types = array();
      if(isset($_SERVER["ACCEPT"]))
      {
         $this->accept_types = explode(",",$_SERVER["ACCEPT"]);
      }

      $this->file_extension = null;
      if(isset($_GET["format"]))
      {
         $this->file_extension =  PineappleRequest::$FILE_MAPS[$_GET["format"]] ;
      }

      $this->inference = array();
      if(isset($_GET["inference"]))
      {
         foreach(explode(",",$_GET["inference"]) as $g)
            $this->inference[] = $g;
      }
         

   }

   function execute($pineapple = null)
   {

      if($pineapple==null)
         $pineapple = $this->pineapple;

      $output = null;

      if ($this->action = "describe" && $this->resource_is_uddi)
      {
         $output = $pineapple->describe_document($this->resource,$this->file_extension);
      }
      else if($this->action = "describe" && !$this->resource_is_uddi)
      {
         $output = $pineapple->describe_resource($this->resource,$this->file_extension);
      }

      return $output;
   }



}

$pineapple = new Pineapple();

$req = new PineappleRequest();
//print_r($req);
try
{
   getDataspaces();
   echo $req->execute($pineapple);
} 
catch(ResourceNotFoundExcetion $e)
{
   header("HTTP/1.0 404 Not Found");
   echo "404";
}

?>
