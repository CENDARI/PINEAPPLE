<?php
namespace Pineapple;

use PHPUnit_Framework_TestCase;
use EasyRdf_Sparql_Result;


class PineappleTest extends PHPUnit_Framework_TestCase {

    protected $settings;
    protected $api;
    protected $store;

    protected function setUp() {
        $this->settings = parse_ini_file(
            realpath(__DIR__) . "/../settings.ini"
        );

        $this->api = $this->getMockBuilder('\Pineapple\Api')
            ->setConstructorArgs([$this->settings])
            ->getMock();
        $this->store = $this->getMockBuilder('\Pineapple\TripleStore')
            ->setConstructorArgs([$this->settings, null, null])
            ->getMock();
        $this->assertEquals("NONE", $this->settings["AUTHORISATION_TYPE"]);
    }

    public function testGetResource() {
        $this->store
            ->method("ask")
            ->willReturn(true);
        $this->store
            ->method("query")
            ->will($this->onConsecutiveCalls(
                $this->getMockResult("resource"),
                $this->getMockResult("resource_mentions")));

        $pineapple = new Pineapple($this->api, $this->store, $this->settings);

        $resource = $pineapple->getResource("c87de1f4-8452-44e5-aea0-75edc3bc77d7");

        $this->assertEquals("Race in progress", $resource["title"]);
    }

    /**
     * @expectedException \Pineapple\ResourceNotFoundException
     * @expectedExceptionMessage not-here
     */
    public function testGetResourceNotFound() {
        $this->store
            ->method("ask")
            ->willReturn(false);
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("resource"));

        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $pineapple->getResource("not-here");
    }

    public function testGetResources() {
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("list"));
        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $resources = $pineapple->getResources("test", 0, 20);

        $this->assertEquals("Race in progress", $resources[0]["title"]);
        $this->assertEquals("Colonel of the regiment watching the greasy pole competition",
            $resources[1]["title"]);
    }

    public function testGetMentionResources() {
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("mention_resources"));
        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $resources = $pineapple->getMentionResources("schema:Event", "World War",  0, 20);

        $this->assertEquals("Race in progress", $resources[0]["title"]);
        $this->assertEquals("Colonel of the regiment watching the greasy pole competition",
            $resources[1]["title"]);
    }

    public function testGetRelatedResources() {
        // NB: Re-using the mention_resources fixture here
        // since it's just a list of resources
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("mention_resources"));
        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $resources = $pineapple->getRelatedResources("test",  0, 20);

        $this->assertEquals("Race in progress", $resources[0]["title"]);
        $this->assertEquals("Colonel of the regiment watching the greasy pole competition",
            $resources[1]["title"]);
    }

    public function testGetAccessPoints() {
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("access_points"));
        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $resources = $pineapple->getAccessPoints("schema:Person", "",  0, 20);

        $this->assertEquals("Race", $resources[0]["title"]);
        $this->assertEquals("Attribution", $resources[1]["title"]);
    }

    public function testGetOntologyResourceTypes() {
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("ontology_resource_types"));
        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $resources = $pineapple->getOntologyResourceTypes(null, null, null);

        $this->assertEquals("skos:Concept", $resources[0]["type"]);
        $this->assertEquals(2257, $resources[0]["count"]);
    }

    public function testGetOntologyResources() {
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("ontology_resources"));
        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $resources = $pineapple->getOntologyResources(null, null, null, 0, 20);

        $this->assertEquals("Italian", $resources[0]["prefLabel"]);
    }

    public function testCheckResourceExists() {
        // Mock the ask method so the result is always true...
        $this->store
            ->method("ask")
            ->willReturn(true);
        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $this->assertTrue($pineapple->checkResourceExists("mock-item-uri"));
    }

    public function testGetPermissionFilter() {
        // This assumes a lot of knowledge about the getPermissionFilter
        // function, but it's difficult to test otherwise. Here we mock
        // the return value of getDataspaces with a known value, assert
        // that it produces the right Sparql query, and the right FROM
        // clause with mocked data from the triplestore.
        $this->settings["AUTHORISATION_TYPE"] = "ENFORCING";
        $this->settings["dataspaces"] = "http://resources.cendari.dariah.eu/dataspaces/";
        $this->api
            ->method("getDataspaces")
            ->willReturn([
                [
                    "id" => "mock-ds-id",
                    "name" => "Mock Dataspace"
                ]
            ]);
        $this->store
            ->method("query")
            ->with("select ?g where { ?ds rdfs:member ?g FILTER (?ds = <http://resources.cendari.dariah.eu/dataspaces/mock-ds-id>)}")
            ->willReturn($this->getMockResult("dataspace_members"));
        $pineapple = new Pineapple($this->api, $this->store, $this->settings);
        $fromClause = $pineapple->getPermissionFilter();
        $this->assertEquals("FROM <http://resources.cendari.dariah.eu/dataspaces/mock1>\nFROM <http://resources.cendari.dariah.eu/dataspaces/mock2>\n", $fromClause);
    }

    private function getFixture($name) {
        return file_get_contents(realpath(__DIR__) . "/fixtures/$name.xml");
    }

    private function getMockResult($name) {
        return new EasyRdf_Sparql_Result(
            $this->getFixture($name), "application/sparql-results+xml");
    }
}