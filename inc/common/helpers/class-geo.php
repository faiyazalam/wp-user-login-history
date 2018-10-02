<?php
namespace User_Login_History\Inc\Common\Helpers;


    class Geo {

        /**
         * URL of the thirst party service.
         */
        const GEO_API_URL = 'https://tools.keycdn.com/geo.json?host=';


        /**
         * Retrieve IP Address of user.
         *
         * @return string The IP address of user.
         */
        static public function get_ip() {
            
            $ip_address = $_SERVER['REMOTE_ADDR'];

            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $ip_address = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            return $ip_address;
        }

        /**
         * Retrieve the geo location.
         *
         * @return string The response from geo API.
         */
        static public function get_geo_location() {


            $defaults = array('timeout' => 5);
            $args = apply_filters('faulh_remote_get_args', $defaults);
            $r = wp_parse_args($args, $defaults);
            $response = wp_remote_get(self::GEO_API_URL . self::get_ip(), $r);
            if (is_wp_error($response)) {
                Error_Log::error_log("error while calling wp_remote_get method:" . $response->get_error_message(), __LINE__, __FILE__);
                return FALSE;
            }

            if (is_array($response)) {
                $decoded = json_decode($response['body']);
                if (isset($decoded->data->geo)) {
                    return (array) $decoded->data->geo;
                }
            }
            return FALSE;
        }

    }



