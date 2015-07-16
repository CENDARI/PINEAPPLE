<?php
function render_document_header($data_item, $document)
{
   $title = $document->get($data_item,'nao:identifier');
   $header = <<<"EOH"
   <head>
      <title>$title</title>
   </head>

EOH;
   return $header;
}

function render_document_body($data_item, $document)
{
   $raw_text = $document->get($data_item,'nie:plainTextContent');
   $item_id =  $document->get($data_item,'nao:identifier');
   $source = $document->get($data_item,'dc11:source');
   $last_modified = $document -> get($data_item,'nao:lastModified');
   $linked_resources = make_linked_resources_list($data_item, $document);
   $repository =  make_repository_description($data_item, $document);

   $body = <<<"DESC"
<body>
   <div id='doc_description'>
      <H1>Data Item: $item_id</H1>
      <dl>
         <dt>Plain Text</dt>
         <dd id='raw_text'>$raw_text</dd>
         <dt>Source</dt>
         <dd>$source</dd>
         <dt>Last Modified</dt>
         <dd>$last_modified</dd>
      </dl>
   </div>
   <div id='linked_resources'>
     <div id='mentions'>
      <H2>This document mentions:</H2>
      $linked_resources
     </div>
     <div id='is_mentioned'>
      <H2>This document is mentioned by:</H2>
     </div>
   </div>
   <div id='all_info'>
     <H2>All triples<H2> 
     $repository
   </div>

DESC;
   
#$body .= <ul>\n";#$raw_text</body>\n";
   foreach($document->resources() as $res)
   {
      $body .= "<li>$res</li>\n<ul>";
      foreach($document->properties($res) as $prop)
         $body .= "<li>$prop</li>\n";
      $body.="</ul>\n";
   }
   $body.="</ul>\n</body>";

   return $body;  
}

function make_linked_resources_list($data_item, $document)
{
   $links = "<dl>\n";
   foreach($document->allResources($data_item,'schema:mentions') as $res)
   {
      $name = $res->getliteral("schema:name");
      $link_url =  make_resource_url($res);
      $types =  implode(" and ",$document->allResources($res,'rdf:type'));
      
      $links .= "<dt><a href='$link_url' title='$res'>$name</a></dt>".
         "<dd> A $types</dd>\n";
   }
   $links.="</dl>";
   return $links;
}

function make_repository_description($data_item, $document)
{
   $repository_list = $document->allOfType("cao:Repository");

   if($repository_list == null)
      return "";

   $repository = $repository_list[0];
   $address = $repository->get("cao:hasRepositoryAddress");
   $street = $address->get("vcard:street-address");
   $locality = $address->get("vcard:locality");
   $postcode = $address->get("vcard:postal-code");
   $country = $address->get("vcard:country-name");
   $description = $repository->get("cao:repositoryHistoryNote");

   return <<<"DIV"
   <div id="repository">
      <h2>Repository:</h2>
      <ul id="address">
         <li>$street</li>
         <li>$locality</li>
         <li>$postcode</li>
         <li>$country</li>
       </ul>
       <p id="description">$description<p>
   </div>
DIV;
}
function render_document_html($document)
{
   $data_item = $document->getURI();#resourcesMatching("rdf:type","resource:DataItem")[0];
   
   return
      "<html>\n".
      render_document_header($data_item, $document).
      render_document_body($data_item, $document).
      "</html>";
}

function make_resource_url($resource)
{
   $prefix = "cendari://resources/";
   return substr($resource->getUri(), strlen($prefix)).".html";
}
