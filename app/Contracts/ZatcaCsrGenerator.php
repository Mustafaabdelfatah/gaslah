<?php

namespace App\Contracts;

use App\Models\Organization;

interface ZatcaCsrGenerator
{
    /**
     * @return array{private_key_pem:string,csr_pem:string,subject:array<string,string>}
     */
    public function generate(Organization $organization): array;
}
