<?php
namespace njpanderson\VarnishConnect;

/**
 * Sends and receives data to varnish over the administration port
 */
class Socket
{
    private $fp;
    private $params;
    private $auth;

    const NL = "\n";

    const CLIS_SYNTAX = 100;
    const CLIS_UNKNOWN = 101;
    const CLIS_UNIMPL = 102;
    const CLIS_TOOFEW = 104;
    const CLIS_TOOMANY = 105;
    const CLIS_PARAM = 106;
    const CLIS_AUTH = 107;
    const CLIS_OK = 200;
    const CLIS_TRUNCATED = 201;
    const CLIS_CANT = 300;
    const CLIS_COMMS = 400;
    const CLIS_CLOSE = 500;

    public function __construct($socket_ip, $socket_port, $secret)
    {
        $this->params = array(
            'socket_ip' => $socket_ip,
            'socket_port' => $socket_port,
            'secret' => $secret,
        );
    }

    public function connect()
    {
        $this->fp = @fsockopen(
            $this->params['socket_ip'],
            $this->params['socket_port'],
            $errno,
            $errstr,
            5
        );

        if (!$this->fp) {
            throw new \Exception('Socket error: ' . $errstr . '(' . $errno . ')');
        }

        // get initial authentication response and authenticate
        $response = $this->receive();
        $this->sendAuth($response['message'][0]);
    }

    public function disconnect()
    {
        fclose($this->fp);
    }

    public function getBanList()
    {
        $this->checkAuth();
        $response = $this->write('ban.list', self::CLIS_OK);
        $banlist = array();

        for ($a = 1; $a < count($response['message']); $a += 1) {
            preg_match(
                '/([\d\.]+)\s+(\d+)\s([CRO-]+) +(0x[^ ]+)?(.+)?/',
                $response['message'][$a],
                $match
            );

            if (count($match) === 6) {
                $flags = str_split(trim(str_replace('-', '', $match[3])));

                $banlist[] = array(
                    'timestamp' => (double) $match[1],
                    'gmdate' => gmdate('c', $match[1]),
                    'ref' => (int) $match[2],
                    'flags' => (count($flags) > 0 && !empty($flags[0]) ? $flags : null),
                    'pointer' => (!empty($match[4]) ? $match[4] : null),
                    'spec' => trim($match[5])
                );
            }
        }

        return $banlist;
    }

    public function addBan($spec)
    {
        $response = $this->write('ban ' . $spec);

        switch ($response['code']) {
            case self::CLIS_OK:
                return true;

            default:
                return $response;
        }
    }

    private function sendAuth($challenge)
    {
        $response = $this->write('auth ' . $this->genAuthCode($challenge));

        if ($response['code'] === self::CLIS_OK) {
            $this->auth = true;
        } else {
            throw new \Exception('Authentication failed.');
        }
    }

    private function write($data, $expectedResponseCode = null)
    {
        if ($this->fp) {
            fwrite($this->fp, $data . self::NL);
            return $this->receive($expectedResponseCode);
        }
    }

    private function receive($expectedResponseCode = null)
    {
        $response = '';
        $chars_sent = 0;
        $chars_expected = 0;

        while (($line = fgets($this->fp)) !== false) {
            // line starts with numeric code defining the response type and length of response
            if (preg_match('/(\d{3})\s(\d+)/', $line, $code)) {
                // set expected characters (including +1 for terminating newline)
                $chars_expected = (int) $code[2] + 1;
            } else {
                $chars_sent += strlen($line);
            }

            $response .= $line;

            if ($chars_sent >= $chars_expected) {
                break;
            }
        }

        $response = $this->parseResponse($response);

        if (empty($expectedResponseCode) || $response['code'] === $expectedResponseCode) {
            return $response;
        } else {
            throw new \Exception('Invalid response from server');
        }
    }

    private function parseResponse($data)
    {
        $data = explode(self::NL, $data);
        $message = array();

        if (count($data) >= 2) {
            $responseCode = trim($data[0]);
            $responseCode = explode(' ', $responseCode);
            $responseCode = (int) $responseCode[0];

            if (is_numeric($responseCode)) {
                for ($a = 1; $a < count($data); $a += 1) {
                    $data[$a] = trim($data[$a]);

                    if (!empty($data[$a])) {
                        $message[] = $data[$a];
                    }
                }
            } else {
                return null;
            }
        }

        return array(
            'code' => $responseCode,
            'message' => $message
        );
    }

    private function genAuthCode($challenge)
    {
        $string =
            $challenge . self::NL .
            $this->params['secret'] . self::NL .
            $challenge . self::NL;

        return hash(
            'sha256',
            $string
        );
    }

    private function checkAuth()
    {
        if (!$this->fp || !$this->auth) {
            throw new \Exception('Connection has not yet taken place. Have you connected with connect()?');
        }
    }
}
