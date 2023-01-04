<?php

declare(strict_types=1);

namespace WHMCS\Domains\DomainLookup;

define('STATUS_NOT_REGISTERED', true);
define('STATUS_REGISTERED', false);

class ResultsList {
    public array $results = [];

    public function append(SearchResult $result) {
        $this->results[] = $result;
    }
}

class SearchResult {
    public string $sld;
    public string $tld;
    public bool $status = false;
    public bool $premium = false;
    public array $pricing;

    function __construct(string $sld, string $tld) {
        $this->sld = $sld;
        $this->tld = $tld;
    }

    public function setStatus(bool $status) {
        $this->status = $status;
    }

    public function setPremiumDomain(bool $premium) {
        $this->premium = $premium;
    }

    public function setPremiumCostPricing(array $pricing) {
        $this->pricing = $pricing;
    }
}
