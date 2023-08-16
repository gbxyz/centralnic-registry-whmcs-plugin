<?php

/**
 * CentralNic Registry WHMCS Module
 * Copyright 2023 CentralNic Group PLC. All rights reserved.
 */

declare(strict_types=1);

namespace centralnic\whmcs\xml;

trait shortcuts {
    function add($node) {
        return $this->appendChild($node);
    }

    public function get(string $qualifiedName) : \DOMNodeList {
        return $this->getElementsByTagName($qualifiedName);
    }

    public function first(string $qualifiedName) {
        return $this->get($qualifiedName)->item(0);
    }
}

class node extends \DOMNode {
    use shortcuts;
}

class element extends \DOMElement {
    use shortcuts;
}

class frame extends \DOMDocument {
    use shortcuts;

    public function __construct(string $version='1.0', string $encoding='UTF-8') {
        parent::__construct($version, $encoding);
        $this->formatOutput = true;
        $this->preserveWhiteSpace = false;

        $this->registerNodeClass('\DOMNode',    __NAMESPACE__.'\node');
        $this->registerNodeClass('\DOMElement', __NAMESPACE__.'\element');
    }

    public function loadXML(string $source, int $options=0) : bool {
        $was = libxml_use_internal_errors(false);

        $result = parent::loadXML($source, $options);

        libxml_use_internal_errors($was);

        return $result;
    }

    public function create(string $localName, string $value='') : element {
        $el = $this->createElement($localName);
        if (strlen($value) > 0) $el->add($this->createTextNode($value));
        return $el;
    }

    public function nsCreate(string $xmlns, string $localName, string $value='') : element {
        $el = $this->createElementNS($xmlns, $localName);
        if (strlen($value) > 0) $el->add($this->createTextNode($value));
        return $el;
    }
}
