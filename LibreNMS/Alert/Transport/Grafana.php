<?php
/* This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>. */

/**
 * Grafana Oncall Transport
 */

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
            $opts["title"] = $this->config["title"];
            $opts["url"] = $this->config["url"];
            $opts["message_template"] = $this->config["message_template"];
            $opts["detail_link_template"] =
                $this->config["detail_link_template"];
        }
        return $this->contactGrafana($obj, $opts);
    }

    public function contactGrafana($obj, $opts)
    {
        $curl = curl_init();
        Proxy::applyToCurl($curl);

        $host = $opts["url"];

        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
        ];

        $body = $this->makeBody($obj, $opts);

        curl_setopt($curl, CURLOPT_URL, $host);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $ret = curl_exec($curl);

        if (curl_errno($curl)) {
            $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            throw new AlertTransportDeliveryException($obj, $code, $ret);
        }

        return true;
    }

    private function makeBody($obj, $opts)
    {
        return (object) array_merge(
            (array) $this->grafana_base_body($obj, $opts),
            (array) $this->rendered_body($obj, $opts)
        );
    }

    private function rendered_body($obj, $opts)
    {
        if ($opts["message_template"]) {
            // TODO: Parser
            return [
                "message" => $opts["message_template"],
            ];
        }
        return [];
    }

    private function grafana_base_body($obj, $opts)
    {
        return [
            "alert_uid" => $obj["uid"],
            "title" => $opts["title"],
            "link_to_upstream_details" => $opts["detail_link_template"],
        ];
    }

    public static function configTemplate()
    {
        return [
            "config" => [
                [
                    "title" => "WebHook",
                    "name" => "url",
                    "descr" => "Grafana WebHook URL",
                    "type" => "text",
                ],
                [
                    "title" => "Alert Title",
                    "name" => "title",
                    "descr" => "Grafana Alert Title",
                    "type" => "text",
                ],
                [
                    "title" => "Upstream Link (template)",
                    "name" => "detail_link_template",
                    "descr" => "Link to Upstream Details",
                    "type" => "text",
                ],
                [
                    "title" => "Message Body (template)",
                    "name" => "message_template",
                    "descr" => "Grafana Message Body (template)",
                    "type" => "textarea",
                ],
            ],
            "validation" => [
                "url" => "required",
                "title" => "required",
            ],
        ];
    }
}
