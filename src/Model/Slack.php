<?php

namespace Pantheon\LCMDeployCommand\Model;

/**
 * Simple and lightweight interface to the Slack API.
 */

class Slack
{
  // This default URL has access only to channel '#andy-testing-webhooks'
    private const DEFAULT_URL = 'https://hooks.slack.com/services/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';

    private $url, $maxtries, $interval;
    private $blocks = [];

    public function __construct(?string $url = null, int $maxtries = 5, $interval = 3)
    {
        $this->url = $url ?? self::DEFAULT_URL;
        $this->maxtries = $maxtries;
        $this->interval = $interval;
    }

  /**
   * Build Slack message 'payload' structure from $content array or $content+$type strings.
   */
    public function build($content, string $type = 'body')
    {
        if (is_string($content)) {
            $content = [$type => $content];
        }

        $blocks =& $this->blocks;
        foreach ($content as $key => $value) {
            switch (explode('_', $key)[0]) {
                case 'title':
                    $blocks[] = [
                    'type' => 'header',
                    'text' => [
                      'type' => 'plain_text',
                      'text' => $value,
                    ],
                    ];
                    break;

                case 'body':
                    $blocks[] = [
                    'type' => 'section',
                    'text' => [
                    'type' => 'mrkdwn',
                    'text' => implode("\n\n", (array) $value),
                    ],
                    ];
                    break;

                case 'thumbnail':
                    list($url, $title) = array_map('trim', explode('|', "$value|", 3));
                    $block =& $blocks[array_key_last($blocks)];
                    $block['accessory'] = [
                    'type' => 'image',
                    'image_url' => $url,
                    'alt_text' => $title,
                    ];
                    break;

                case 'image':
                    list($url, $title) = array_map('trim', explode('|', "$value|", 3));
                    $blocks[] = [
                    'type' => 'image',
                    'title' => [
                    'type' => 'plain_text',
                    'text' => $title,
                    ],
                    'image_url' => $url,
                    'alt_text' => $title,
                    ];
                    break;

                case 'fields':
                    $fields = [];
                    foreach ($value as $label => $text) {
                        if (!is_numeric($label)) {
                              $text = "*$label:*\n$text";
                        }
                        $fields[] = [
                        'type' => 'mrkdwn',
                        'text' => $text,
                        ];
                    }
                    $blocks[] = [
                    'type' => 'section',
                    'fields' => $fields,
                    ];
                    break;

                case 'notes':
                    $blocks[] = [
                    'type' => 'context',
                    'elements' => [
                    [
                    'type' => 'mrkdwn',
                    'text' => implode("\n\n", (array) $value),
                    ],
                    ],
                    ];
                    break;

                case 'button':
                    list($url, $title, $style) = array_map('trim', explode('|', "$value|", 4));
                    $block =& $blocks[array_key_last($blocks)];
                    $block['accessory'] = [
                    'type' => 'button',
                    'text' => [
                    'type' => 'plain_text',
                    'text' => $title,
                    ],
                    'action_id' => str_replace(' ', '_', strtolower($title)),
                    'url' => $url,
                    ];
                    if ($style == 'primary' || $style == 'danger') {
                        $block['accessory']['style'] = $style;
                    }
                    break;

                case 'divider':
                    $blocks[] = [
                    'type' => 'divider',
                    ];
                    break;
              // End switch.
            }
        }

        return $this;
    }

  /**
   * Post to Slack.
   * Payload can be passed as a parameter or set previously via build() method.
   */
    public function post(?string $payload = null)
    {
        if (!$payload) {
            $payload = json_encode(['blocks' => $this->blocks], JSON_PRETTY_PRINT | JSON_UNESCAPED_LINE_TERMINATORS);
            $this->blocks = [];
        }

      // We will retry for errors that we consider transient.
      // Full error list: https://curl.se/libcurl/c/libcurl-errors.html
        $transient_errors = [
        CURLE_OPERATION_TIMEDOUT,
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_HTTP_RETURNED_ERROR,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
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
            $error = CURLE_OPERATION_TIMEDOUT;
            $error_string = curl_strerror($error);
            echo "Slack post failed with cURL error ($error): $error_string - ";
            if (!in_array($error, $transient_errors) || $try++ > $this->maxtries) {
                echo "Giving up.\n";
                break;
            }
            echo "Retrying in {$this->interval} seconds...\n";
            sleep($this->interval);
        }

        curl_close($ch);
        return $this;
    }
}
