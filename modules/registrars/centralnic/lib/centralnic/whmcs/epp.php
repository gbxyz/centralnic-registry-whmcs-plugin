<?php

declare(strict_types=1);

namespace centralnic\whmcs;

/**
 * EPP utility class
 */
class epp {
    const xmlns_prefix      = 'urn:ietf:params:xml:ns:';
    const xmlns             = self::xmlns_prefix.'epp-1.0';
    const xmlns_domain      = self::xmlns_prefix.'domain-1.0';
    const xmlns_contact     = self::xmlns_prefix.'contact-1.0';
    const xmlns_host        = self::xmlns_prefix.'host-1.0';
    const xmlns_fee         = self::xmlns_prefix.'fee-0.5';
    const version           = '1.0';

    const epp               = 'epp';
    const command           = 'command';
    const create            = 'create';
    const renew             = 'renew';
    const update            = 'update';
    const transfer          = 'transfer';
    const delete            = 'delete';

	const port              = 700;
	const timeout           = 3;

    private $greeting;
    private $socket;

    private array $log = [];

    public function __construct(string $host, string $id, string $pw) {
        $this->connect($host);
        $this->login($id, $pw);
    }

    /**
     * connect to the server
     */
    private function connect(string $host) {
        $this->socket = stream_socket_client(
            'tls://'.$host.':'.self::port,
            $error_code,
            $error_mesg,
            self::timeout,
            STREAM_CLIENT_CONNECT,
        );
        if (!is_resource($this->socket)) {
            throw new error(sprintf(
                'Error connecting to %s:%d: %s (%u)',
                $host,
                self::port,
                $error_mesg,
                $error_code,
            ));

        } else {
            $this->greeting = $this->getFrame();

        }
    }

    /**
     * send a <login> command
     */
    private function login(string $id, string $pw) {
        $frame = new xml\frame;

        $login = $frame->appendChild($frame->createElementNS(self::xmlns, 'epp'))
                    ->appendChild($frame->createElement('command'))
                        ->appendChild($frame->createElement('login'));

        $login->appendChild($frame->createElement('clID', htmlspecialchars($id)));
        $login->appendChild($frame->createElement('pw',   htmlspecialchars($pw)));

        $options = $login->appendChild($frame->createElement('options'));
        $options->appendChild($frame->createElement('version', self::version));
        $options->appendChild($frame->createElement('lang', $this->greeting->getElementsByTagName('lang')->item(0)->textContent));

        $svcs = $login->appendChild($frame->createElement('svcs'));
        foreach ($this->greeting->getElementsByTagName('objURI') as $uri) $svcs->appendChild($frame->importNode($uri, true));

        $svcExtension = $this->greeting->getElementsByTagName('svcExtension')->item(0);
        if ($svcExtension) $svcs->appendChild($frame->importNode($svcExtension, true));

        return $this->request($frame);
    }

    /**
     * get a frame from the server
     */
    private function getFrame() : xml\frame {
        $hdr = fread($this->socket, 4);
        if (false === $hdr || strlen($hdr) != 4) {
            throw new error('error reading frame header');

        } else {
            list(,$len) = unpack('N', $hdr);

            $was = libxml_use_internal_errors(false);

            $frame = new xml\frame;
            $ok = $frame->loadXML(fread($this->socket, $len-4));

            libxml_use_internal_errors($was);

            if (false === $ok) {
                foreach (libxml_get_errors() as $e) {
                    if (in_array($e->level, [LIBXML_ERR_ERROR, LIBXML_ERR_FATAL])) {
                        throw new error(sprintf(
                            'error parsing XML from server: %s on line %u column %u',
                            $e->message,
                            $e->line,
                            $e->column,
                        ));
                    }
                }
            } else {
                return $frame;
            }
        }
    }

    /**
     * send a frame to the server
     */
    private function sendFrame(xml\frame $frame) {
        if (1 == $frame->getElementsByTagName('command')->length && 0 == $frame->getElementsByTagName('clTRID')->length) {
            $frame->getElementsByTagName('command')->item(0)->appendChild($frame->createElement('clTRID', self::generateclTRID()));
        }

        $xml = $frame->saveXML();

        fwrite(
            $this->socket,
            pack('N', 4+strlen($xml)).$xml,
        );
    }

    /**
     * generate a client transaction ID
     */
    private static function generateclTRID() : string {
        return strToUpper(substr(base_convert(bin2hex(openssl_random_pseudo_bytes(40)), 16, 36), 0, 64));
    }

    /**
     * send a frame to the server and get the response
     */
    function request(xml\frame $frame) : xml\frame {

        $this->log[] = [$frame, null];

        $this->sendFrame($frame);
        $response = $this->getFrame();

        $this->log[count($this->log)-1][1] = $response;

        $result = $response->getElementsByTagName('result')->item(0);
        if (!($result instanceof \DOMElement)) {
            throw new error('error parsing response from server: no <result> element found');

        } else {
            $code = intval($result->getAttribute('code'));
            if (0 == $code) {
                throw new error("error parsing response from server: missing or empty 'code' attribute for <result> element");

            } elseif ($code < 2000) {
                return $response;

            } else {
                $msg = $response->getElementsByTagName('msg')->item(0);
                if ($msg instanceof \DOMElement) {
                    $msg = $msg->textContent;

                } else {
                    $msg = 'unknown error';

                }

                throw new error(sprintf('%04u error: %s', $code, $msg));
            }
        }
    }

    public function getLastResponse() : xml\frame {
        return $this->log[count($this->log)-1][1];
    }
}
