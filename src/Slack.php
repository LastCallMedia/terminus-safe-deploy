<?php

namespace LastCall\TerminusSafeDeploy;

use SlackPhp\BlockKit\Surfaces\Message;

/**
 * Simple and lightweight interface to the Slack API.
 */

class Slack
{

    const MAX_RETRIES = 5;
    const RETRY_INTERVAL = 3;

    /**
     * Send message to Slack channel.
     */
    public static function send(Message $message, $url = null)
    {
        $payload = $message->toJson();
        // We will retry for errors that we consider transient.
        // Full error list: https://curl.se/libcurl/c/libcurl-errors.html
        $transient_errors = [
            CURLE_OPERATION_TIMEDOUT,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_HTTP_RETURNED_ERROR,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url ?? ($_ENV['SLACK_URL'] ?? ''));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        for ($try = 1;;) {
            $result = curl_exec($ch);
            $error = curl_errno($ch);
            // Check that curl was successful.
            if ($error == CURLE_OK) {
                // Check that Slack accepted the post.
                if ($result != 'ok') {
                    echo "\nERROR: Slack reported: '$result'\n";
                }
                break;
            }
            $error_string = curl_strerror($error);
            echo "Slack post failed with cURL error ($error): $error_string - ";
            if (!in_array($error, $transient_errors) || $try++ > self::MAX_RETRIES) {
                echo "Giving up.\n";
                break;
            }

            echo "Retrying in {${self::RETRY_INTERVAL}} seconds...\n";
            sleep(self::RETRY_INTERVAL);
        }

        curl_close($ch);
    }
}
