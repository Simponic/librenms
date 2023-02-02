<?php

namespace LibreNMS\Alert\Transport;

use LibreNMS\Alert\Transport;
use LibreNMS\Exceptions\AlertTransportDeliveryException;
use LibreNMS\Util\Proxy;

class Grafana extends Transport
{
    protected $name = "Grafana-OnCall";
    public function deliverAlert($obj, $opts)
    {
        if (!empty($this->config)) {
            $opts["url"] = $this->config["url"];
        }
        return $this->contactGrafana($obj, $opts);
    }

    public function contactGrafana($obj, $opts)
    {
        $host = $opts["url"];
        $curl = curl_init();
        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
        ];

        Proxy::applyToCurl($curl);
        curl_setopt($curl, CURLOPT_URL, $host);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(["test" => $obj]));

        $ret = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        //        if ($code != 204) {
        //            throw new AlertTransportDeliveryException($obj, $code, $ret);
        //        }

        return true;
    }

    public static function configTemplate()
    {
        return [
            "config" => [
                [
                    "title" => "Grafana WebHook URL",
                    "name" => "url",
                    "descr" => "Grafana WebHook URL",
                    "type" => "text",
                ],
            ],
            "validation" => [
                "url" => "required",
            ],
        ];
    }
}
