<?php

require_once realpath(__DIR__) . DIRECTORY_SEPARATOR . "../Pineapple.php";
require_once realpath(__DIR__) . DIRECTORY_SEPARATOR . "../TripleStore.php";
require_once realpath(__DIR__) . DIRECTORY_SEPARATOR . "../FileRepository.php";

class PineappleTest extends PHPUnit_Framework_TestCase {

    protected $settings;
    protected $repo;
    protected $store;

    protected function setUp() {
        $this->settings = parse_ini_file(
            realpath(__DIR__) . DIRECTORY_SEPARATOR . "../settings.ini"
        );

        $this->repo = $this->getMockBuilder("FileRepository")
            ->setConstructorArgs([$this->settings])
            ->getMock();
        $this->store = $this->getMockStore();
        $this->assertEquals("NONE", $this->settings["AUTHORISATION_TYPE"]);
    }


    public function testGetResource() {
        $this->store
            ->method("ask")
            ->willReturn(true);
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("resource"));

        $pineapple = new Pineapple($this->repo, $this->store, $this->settings);

        $resource = $pineapple->getResource("c87de1f4-8452-44e5-aea0-75edc3bc77d7");

        $this->assertEquals("Race in progress", $resource["title"]);
    }

    /**
     * @expectedException ResourceNotFoundException
     * @expectedExceptionMessage resource:not-here not found.
     */
    public function testGetResourceNotFound() {
        $this->store
            ->method("ask")
            ->willReturn(false);
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("resource"));

        $pineapple = new Pineapple($this->repo, $this->store, $this->settings);
        $pineapple->getResource("not-here");
    }

    public function testGetResources() {
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("list"));
        $pineapple = new Pineapple($this->repo, $this->store, $this->settings);
        $resources = $pineapple->getResources("test", 0, 20);

        $this->assertEquals("Race in progress", $resources[0]["title"]);
        $this->assertEquals("Colonel of the regiment watching the greasy pole competition",
            $resources[1]["title"]);
    }

    public function testGetMentionResources() {
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("mention_resources"));
        $pineapple = new Pineapple($this->repo, $this->store, $this->settings);
        $resources = $pineapple->getMentionResources("schema:Event", "World War",  0, 20);

        $this->assertEquals("Race in progress", $resources[0]["title"]);
        $this->assertEquals("Colonel of the regiment watching the greasy pole competition",
            $resources[1]["title"]);
    }

    public function testCheckResourceExists() {
        // Mock the ask method so the result is always true...
        $this->store
            ->method("ask")
            ->willReturn(true);
        $pineapple = new Pineapple($this->repo, $this->store, $this->settings);
        $this->assertTrue($pineapple->checkResourceExists("mock-item-uri"));
    }

    public function testGetPermisionFilter() {
        // This assumes a lot of knowledge about the getPermissionFilter
        // function, but it's difficult to test otherwise. Here we mock
        // the return value of getDataspaces with a known value, assert
        // that it produces the right Sparql query, and the right FROM
        // clause with mocked data from the triplestore.
        $this->settings["AUTHORISATION_TYPE"] = "ENFORCING";
        $this->repo
            ->method("getDataspaces")
            ->willReturn([
                [
                    "id" => "mock-ds-id",
                    "name" => "Mock Dataspace"
                ]
            ]);
        $this->store
            ->method("query")
            ->with("select ?g where { ?g rdfs:member ?ds FILTER (?ds = <litef://dataspaces/mock-ds-id>)}")
            ->willReturn($this->getMockResult("dataspace_members"));
        $pineapple = new Pineapple($this->repo, $this->store, $this->settings);
        $fromClause = $pineapple->getPermissionFilter();
        $this->assertEquals("FROM <litef://resource/mock1>\nFROM <litef://resource/mock2>\n", $fromClause);
    }

    private function getFixture($name) {
        return file_get_contents(realpath(__DIR__) . DIRECTORY_SEPARATOR . "fixtures/$name.xml");
    }

    private function getMockResult($name) {
        return new EasyRdf_Sparql_Result(
            $this->getFixture($name), "application/sparql-results+xml");
    }

    private function getMockStore() {
        return $this->getMockBuilder("TripleStore")
            ->setConstructorArgs([$this->settings, null, null])
            ->getMock();
    }
}