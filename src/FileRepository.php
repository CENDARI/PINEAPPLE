<?php
namespace Pineapple;

use Requests;

class FileRepository {

    private $url;
    private $settings;

    function __construct($settings) {
        $this->url = $settings["ckan"];
        $this->settings = $settings;
    }

    private function getAuthParam($key) {
        return isset($_SERVER[$key]) ? $_SERVER[$key] : $_ENV[$key];
    }

    private function getApiKey() {
        $data = [
            "eppn" => $this->getAuthParam("eppn"),
            "mail" => $this->getAuthParam("mail"),
            "cn" => $this->getAuthParam("cn")
        ];

        $out = Requests::post($this->url."/session",
            ["Content-type" => "application/json"], json_encode($data));
        return json_decode($out->body)->sessionKey;
    }

    /**
     * Fetch a list of dataspaces
     *
     * @return array
     */
    public function getDataspaces() {
        $out = Requests::get($this->url."/dataspaces", [
            "Authorization" => $this->getApiKey()
        ]);
        return json_decode($out->body, true)["data"];
    }
}