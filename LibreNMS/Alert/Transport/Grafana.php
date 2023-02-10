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

use App\View\SimpleTemplate;
use Exception;

class Grafana extends Transport
{
    protected $name = "Grafana-OnCall";

    public function deliverAlert($obj, $opts)
    {
        if (!empty($this->config)) {
            $opts["alias"] = $this->config["alias"];
            $opts["title_template"] = $this->config["title_template"];
            $opts["url"] = $this->config["url"];
            $opts["message_template"] = $this->config["message_template"];
            $opts["detail_link_template"] =
                $this->config["detail_link_template"];
        }
        return $this->contactGrafana($obj, $opts);
    }

    public function contactGrafana($obj, $opts)
    {
        $aliased_obj = Grafana::build_obj_alias_from_aliases(
            $obj,
            $opts["alias"]
        );
        $body = $this->makeBody($aliased_obj, $opts);

        $curl = curl_init();
        Proxy::applyToCurl($curl);

        curl_setopt($curl, CURLOPT_URL, $opts["url"]);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Accept: application/json",
            "Content-Type: application/json",
        ]);
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
        return (object) array_filter((array) $this->rendered_body($obj, $opts));
    }

    private function rendered_body($obj, $opts)
    {
        return array_filter([
            "message" => $opts["message_template"]
                ? SimpleTemplate::parse($opts["message_template"], $obj)
                : null,
            "link_to_upstream_details" => $opts["detail_link_template"]
                ? SimpleTemplate::parse($opts["detail_link_template"], $obj)
                : null,
        ]);
    }

    public static function tokenize_alias($alias_s)
    {
        $matches = [];
        $result = preg_match(
            "/(?:((?:[\w\d_])+) )?((?:[\w\d_]|\-\>)+) as ([\w\d_]+)/",
            $alias_s,
            $matches
        );

        if ($result) {
            switch (count($matches)) {
                case 3:
                    return [
                        "from" => $matches[1],
                        "as" => $matches[2],
                    ];
                    break;
                case 4:
                    return [
                        "keyword" => $matches[1],
                        "from" => $matches[2],
                        "as" => $matches[3],
                    ];
                    break;
            }
        }

        throw new Exception("'" . $alias_s . "' is not a valid alias string.");
    }

    public static function tokenize_alias_strings($f_str)
    {
        $alias_strings = array_filter(preg_split("/\s*,\s*/", $f_str));
        $aliases = array_map("self::tokenize_alias", $alias_strings);
        return $aliases;
    }

    public static function get_field_from_access_order(
        $obj,
        $access_order_array
    ) {
        if (empty($access_order_array)) {
            return $obj;
        }

        $index = $access_order_array[0];

        if (is_object($obj)) {
            $ret = $obj->{$index};
        } elseif (is_array($obj)) {
            $ret = $obj[$index];
        }

        if (isset($ret)) {
            return Grafana::get_field_from_access_order(
                $ret,
                array_slice($access_order_array, 1)
            );
        }

        throw new Exception("'" . $index . "' is not an accessible field");
    }

    public static function alias_token($obj, $token)
    {
        $keyword = $token["keyword"];
        $from = $token["from"];
        $to = $token["as"];

        $obj_accesses = explode("->", $from);

        if (isset($keyword)) {
            if ($keyword == "join") {
                $access_to_find_parent_array = array_slice(
                    $obj_accesses,
                    0,
                    -1
                );
                $array_field_to_join_on = end($obj_accesses);
                return [
                    $to => implode(
                        ", ",
                        array_map(
                            fn($arr) => $arr[$array_field_to_join_on],
                            Grafana::get_field_from_access_order(
                                $obj,
                                $access_to_find_parent_array
                            )
                        )
                    ),
                ];
            }
        }

        return [
            $to => Grafana::get_field_from_access_order($obj, $obj_accesses),
        ];
    }

    public static function build_obj_alias_from_aliases($obj, $alias_strs)
    {
        $tokens = Grafana::tokenize_alias_strings($alias_strs);

        return array_reduce(
            $tokens,
            fn($a, $x) => array_merge($a, Grafana::alias_token($obj, $x)),
            []
        );
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
                    "title" => "Alias String",
                    "name" => "alias",
                    "descr" =>
                        "Alias strings (a as b, c->d as e, etc.) to use in templates",
                    "type" => "text",
                ],
                [
                    "title" => "Alert Title",
                    "name" => "title_template",
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
                "url" => "required|url",
                "title_template" => "required",
                "alias" => "required",
            ],
        ];
    }
}
