<?php

namespace App\Support;

/**
 * ZATCA Phase 2 — the stored UBL 2.1 invoice, its SHA-256 hash, and the tag-6 QR.
 *
 * The invoice chain (ICV counter + previous-invoice hash) makes the sequence
 * tamper-evident. The hash feeds tag 6 and becomes the next invoice's PIH.
 *
 * Documented gaps carried from the source system: the hash is taken over the serialized
 * XML directly (full ZATCA acceptance hashes the XML after C14N canonicalization), and
 * the XAdES <Signature> block is not embedded — both are unreachable without a real CSID
 * (OTP-gated) and are out of scope here.
 */
class ZatcaPhase2
{
    /**
     * base64 of the hex SHA-256 of "0" — the first-invoice PIH defined by ZATCA.
     */
    public const GENESIS_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    /**
     * SHA-256 of the input, base64-encoded.
     */
    public static function sha256Base64(string $input): string
    {
        return base64_encode(hash('sha256', $input, true));
    }

    /**
     * Build the tag 1–6 QR: reuse phase-1 tags 1–5, then append tag 6 (the XML hash).
     */
    public static function qrPayloadV2(
        string $sellerName,
        string $vatNumber,
        string $timestamp,
        string $grandTotal,
        string $vatTotal,
        string $hashBase64,
    ): string {
        $tags1to5 = base64_decode(Zatca::qrPayload($sellerName, $vatNumber, $timestamp, $grandTotal, $vatTotal));

        return base64_encode($tags1to5.Zatca::tlv(6, $hashBase64));
    }

    /**
     * Build a simplified UBL 2.1 invoice (InvoiceTypeCode 388, name 0200000).
     *
     * @param  array{
     *     orderNo: string, uuid: string, icv: int, pih: string, timestamp: string,
     *     currency: string, sellerName: string, vatNumber: string, sellerAddress?: string|null,
     *     buyerName?: string|null, vatRate: float, subtotal: float, discountTotal: float,
     *     taxableTotal: float, vatTotal: float, grandTotal: float,
     *     items: array<int, array{name: string, quantity: float, unitPrice: float, lineTotal: float}>
     * }  $inp
     */
    public static function buildInvoiceXml(array $inp): string
    {
        [$issueDate, $issueTime] = self::splitTimestamp($inp['timestamp']);
        $currency = $inp['currency'];
        $vatRate = (float) $inp['vatRate'];

        $linesSum = 0.0;
        foreach ($inp['items'] as $item) {
            $linesSum += (float) $item['lineTotal'];
        }
        $linesSum = round($linesSum, 2);

        // Express/extra charge = the gap between the taxable base and the raw line sum.
        $chargeTotal = max(0, round(($inp['taxableTotal'] + $inp['discountTotal'] - $linesSum) * 100) / 100);
        $discountTotal = round((float) $inp['discountTotal'], 2);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">';
        $xml .= '<cbc:ProfileID>reporting:1.0</cbc:ProfileID>';
        $xml .= '<cbc:ID>'.self::esc($inp['orderNo']).'</cbc:ID>';
        $xml .= '<cbc:UUID>'.self::esc($inp['uuid']).'</cbc:UUID>';
        $xml .= '<cbc:IssueDate>'.$issueDate.'</cbc:IssueDate>';
        $xml .= '<cbc:IssueTime>'.$issueTime.'</cbc:IssueTime>';
        $xml .= '<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>';
        $xml .= '<cbc:DocumentCurrencyCode>'.self::esc($currency).'</cbc:DocumentCurrencyCode>';
        $xml .= '<cbc:TaxCurrencyCode>'.self::esc($currency).'</cbc:TaxCurrencyCode>';

        // ICV + PIH references.
        $xml .= '<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>'.(int) $inp['icv'].'</cbc:UUID></cac:AdditionalDocumentReference>';
        $xml .= '<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">'.self::esc($inp['pih']).'</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>';

        // Supplier.
        $xml .= '<cac:AccountingSupplierParty><cac:Party>';
        $xml .= '<cac:PartyTaxScheme><cbc:CompanyID>'.self::esc($inp['vatNumber']).'</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>';
        $xml .= '<cac:PartyLegalEntity><cbc:RegistrationName>'.self::esc($inp['sellerName']).'</cbc:RegistrationName></cac:PartyLegalEntity>';
        if (! empty($inp['sellerAddress'])) {
            $xml .= '<cac:PostalAddress><cbc:StreetName>'.self::esc((string) $inp['sellerAddress']).'</cbc:StreetName><cac:Country><cbc:IdentificationCode>SA</cbc:IdentificationCode></cac:Country></cac:PostalAddress>';
        }
        $xml .= '</cac:Party></cac:AccountingSupplierParty>';

        // Customer.
        $xml .= '<cac:AccountingCustomerParty><cac:Party><cac:PartyLegalEntity><cbc:RegistrationName>'.self::esc((string) ($inp['buyerName'] ?? '')).'</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty>';

        // Document-level allowance (discount) and charge (express).
        if ($discountTotal > 0) {
            $xml .= '<cac:AllowanceCharge><cbc:ChargeIndicator>false</cbc:ChargeIndicator><cbc:AllowanceChargeReason>Discount</cbc:AllowanceChargeReason><cbc:Amount currencyID="'.$currency.'">'.self::n2($discountTotal).'</cbc:Amount></cac:AllowanceCharge>';
        }
        if ($chargeTotal > 0) {
            $xml .= '<cac:AllowanceCharge><cbc:ChargeIndicator>true</cbc:ChargeIndicator><cbc:AllowanceChargeReason>Express service</cbc:AllowanceChargeReason><cbc:Amount currencyID="'.$currency.'">'.self::n2($chargeTotal).'</cbc:Amount></cac:AllowanceCharge>';
        }

        // Tax total.
        $xml .= '<cac:TaxTotal><cbc:TaxAmount currencyID="'.$currency.'">'.self::n2($inp['vatTotal']).'</cbc:TaxAmount>';
        $xml .= '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="'.$currency.'">'.self::n2($inp['taxableTotal']).'</cbc:TaxableAmount><cbc:TaxAmount currencyID="'.$currency.'">'.self::n2($inp['vatTotal']).'</cbc:TaxAmount>';
        $xml .= '<cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>'.self::n2($vatRate).'</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>';

        // Legal monetary total.
        $xml .= '<cac:LegalMonetaryTotal>';
        $xml .= '<cbc:LineExtensionAmount currencyID="'.$currency.'">'.self::n2($linesSum).'</cbc:LineExtensionAmount>';
        $xml .= '<cbc:TaxExclusiveAmount currencyID="'.$currency.'">'.self::n2($inp['taxableTotal']).'</cbc:TaxExclusiveAmount>';
        $xml .= '<cbc:TaxInclusiveAmount currencyID="'.$currency.'">'.self::n2($inp['grandTotal']).'</cbc:TaxInclusiveAmount>';
        $xml .= '<cbc:AllowanceTotalAmount currencyID="'.$currency.'">'.self::n2($discountTotal).'</cbc:AllowanceTotalAmount>';
        $xml .= '<cbc:ChargeTotalAmount currencyID="'.$currency.'">'.self::n2($chargeTotal).'</cbc:ChargeTotalAmount>';
        $xml .= '<cbc:PayableAmount currencyID="'.$currency.'">'.self::n2($inp['grandTotal']).'</cbc:PayableAmount>';
        $xml .= '</cac:LegalMonetaryTotal>';

        // Invoice lines.
        $lineId = 0;
        foreach ($inp['items'] as $item) {
            $lineId++;
            $net = round((float) $item['lineTotal'], 2);
            $lineTax = round($net * $vatRate / 100, 2);
            $xml .= '<cac:InvoiceLine>';
            $xml .= '<cbc:ID>'.$lineId.'</cbc:ID>';
            $xml .= '<cbc:InvoicedQuantity unitCode="PCE">'.self::num((float) $item['quantity']).'</cbc:InvoicedQuantity>';
            $xml .= '<cbc:LineExtensionAmount currencyID="'.$currency.'">'.self::n2($net).'</cbc:LineExtensionAmount>';
            $xml .= '<cac:TaxTotal><cbc:TaxAmount currencyID="'.$currency.'">'.self::n2($lineTax).'</cbc:TaxAmount><cbc:RoundingAmount currencyID="'.$currency.'">'.self::n2($net + $lineTax).'</cbc:RoundingAmount></cac:TaxTotal>';
            $xml .= '<cac:Item><cbc:Name>'.self::esc((string) $item['name']).'</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>'.self::n2($vatRate).'</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>';
            $xml .= '<cac:Price><cbc:PriceAmount currencyID="'.$currency.'">'.self::n2((float) $item['unitPrice']).'</cbc:PriceAmount></cac:Price>';
            $xml .= '</cac:InvoiceLine>';
        }

        $xml .= '</Invoice>';

        return $xml;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitTimestamp(string $timestamp): array
    {
        // "Y-m-dTH:i:s.vZ" → [date, time].
        $date = substr($timestamp, 0, 10);
        $time = substr($timestamp, 11, 8);

        return [$date, $time === '' ? '00:00:00' : $time];
    }

    private static function n2(float|int|string $n): string
    {
        return number_format((float) $n, 2, '.', '');
    }

    /**
     * Quantity without trailing zeros (integer if whole, else up to 6 decimals trimmed).
     */
    private static function num(float $n): string
    {
        if ($n === floor($n)) {
            return (string) (int) $n;
        }

        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
    }

    /**
     * Strict XML escaping plus removal of C0 control chars (except TAB/LF/CR), DEL, and
     * the C1 block — a stray control char from a free-text name would make the document
     * invalid and be rejected after it was already chained.
     */
    private static function esc(string $value): string
    {
        $escaped = str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $value);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $escaped) ?? $escaped;
    }
}
