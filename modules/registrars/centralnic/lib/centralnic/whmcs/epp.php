<?php declare(strict_types=1);

/**
 * CentralNic Registry WHMCS Module
 * Copyright 2023 CentralNic Group PLC. All rights reserved.
 */

namespace centralnic\whmcs;
use centralnic\whmcs\xml\frame;

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

    /**
     * @param $host EPP server to connect to (on TCP port 700)
     * @param $clid EPP client ID
     * @param $pw EPP client password
     * @param $debug whether to enable debugging
     * @param $cert path to certificate
     * @param $key path to private key (if not provided, any private key in $cert will be used)
     */
    public function __construct(
        public readonly string $host,
        public readonly string $clid,
        string $pw,
        public bool $debug=false,
        ?string $cert=null,
        ?string $key=null,
    ) {
        $this->connect($host, $key, $cert);
        $this->login($clid, $pw);
    }

    /**
     * connect to the server
     * @param $host EPP server to connect to (on TCP port 700)
     * @param $cert path to certificate
     * @param $key path to private key (if not provided, any private key in $cert will be used)
     * @throws error
     */
    private function connect(
        string $host,
        ?string $key=null,
        ?string $cert=null,
    ) : void {

        $options = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'verify_depth'      => 16,
            ],
        ];

        if ($key)   $options['ssl']['local_pk'] = $key;
        if ($cert)  $options['ssl']['local_cert'] = $cert;

        $context = stream_context_create($options);

        $this->socket = stream_socket_client(
            'tls://'.$host.':'.self::port,
            $error_code,
            $error_mesg,
            self::timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (!is_resource($this->socket)) {
            throw new error(sprintf(
                'Error connecting to %s:%d: %s (%u)',
                $host,
                self::port,
                $error_mesg,
                $error_code,
            ));
        }

        $this->greeting = $this->getFrame();
    }

    /**
     * send a <login> command
     * @param $clid EPP client ID
     * @param $pw EPP client password
     * @throws error
     */
    private function login(string $id, string $pw) : frame {
        $frame = new frame;

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
     * @throws error
     */
    public function getFrame() : frame {
        $hdr = fread($this->socket, 4);
        if (false === $hdr || strlen($hdr) !== 4) {
            throw new error('error reading frame header');
        }

        list(,$len) = unpack('N', $hdr);

        $xml = fread($this->socket, $len-4);
        if (false === $xml) {
            throw new error('error reading frame payload from socket');
        }

        $frame = self::parseXML($xml);

        if ($this->debug) fwrite(STDERR, $frame->saveXML());

        return $frame;
    }

    public static function parseXML(string $xml) : frame {
        $frame = new frame;

        $was = libxml_use_internal_errors(true);

        $ok = $frame->loadXML($xml);

        if (false === $ok) {
            foreach (libxml_get_errors() as $e) {
                if (LIBXML_ERR_WARNING != $e->level) {
                    throw new error(sprintf(
                        'error parsing XML from server: %s on line %u column %u',
                        $e->message,
                        $e->line,
                        $e->column,
                    ));
                }
            }
        }

        libxml_use_internal_errors($was);

        return $frame;
    }

    /**
     * send a frame to the server
     * @throws error
     */
    public function sendFrame(frame $frame) : void {
        if (1 == $frame->getElementsByTagName('command')->length && 0 == $frame->getElementsByTagName('clTRID')->length) {
            $frame->getElementsByTagName('command')->item(0)->appendChild($frame->createElement('clTRID', self::generateclTRID()));
        }

        $xml = $frame->saveXML();

        if ($this->debug) fwrite(STDERR, $xml);

        $result = fwrite(
            $this->socket,
            pack('N', 4+strlen($xml)).$xml,
        );

        if (false === $result) {
            throw new error('error writing XML to socket');
        }
    }

    /**
     * generate a client transaction ID
     */
    private static function generateclTRID() : string {
        return strToUpper(substr(base_convert(bin2hex(openssl_random_pseudo_bytes(40)), 16, 36), 0, 64));
    }

    /**
     * send a frame to the server and get the response
     * @throws error
     */
    public function request(frame $frame) : frame {
        //
        // send the frame to the server and get the response
        //
        $this->sendFrame($frame);

        $response = $this->getFrame();

        //
        // check the response from the server
        //
        $result = $response->getElementsByTagNameNS(self::xmlns, 'result')->item(0);
        if (!($result instanceof \DOMElement)) {
            throw new error('error parsing response from server: no <result> element found');
        }

        $code = intval($result->getAttribute('code') ?: 2400);
        if (0 == $code) {
            throw new error("error parsing response from server: missing or empty 'code' attribute for <result> element");
        }

        if ($code < 2000) {
            //
            // result code indicates success so return the frame
            //
            return $response;
        }

        //
        // command failed, so throw an error
        //
        $msg = $response->getElementsByTagName('msg')->item(0);
        if ($msg instanceof \DOMElement) {
            $msg = $msg->textContent;

        } else {
            $msg = 'unknown error';

        }

        throw new error(sprintf('%04u error: %s', $code, $msg));
    }

    /**
     * @throws error
     */
    public function logout() : void {
        $frame = new frame;

        $frame->appendChild($frame->createElementNS(self::xmlns, 'epp'))
            ->appendChild($frame->createElement('command'))
                ->appendChild($frame->createElement('logout'));

        $this->request($frame);

        fclose($this->socket);
    }
}
