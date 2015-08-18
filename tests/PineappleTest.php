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
        $this->repo->method("getDataspaces")
            ->willReturn([]);
        $this->store = $this->getMockStore();
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

    public function testListResources() {
        $this->store
            ->method("query")
            ->willReturn($this->getMockResult("list"));
        $pineapple = new Pineapple($this->repo, $this->store, $this->settings);
        $resources = $pineapple->getResources("test", 0, 20);

        $this->assertEquals("Race in progress", $resources[0]["title"]);
        $this->assertEquals("Colonel of the regiment watching the greasy pole competition",
            $resources[1]["title"]);
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