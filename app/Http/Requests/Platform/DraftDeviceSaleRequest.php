<?php

namespace App\Http\Requests\Platform;

use App\Enum\Platform\InvoicePaymentMethodEnum;
use App\Http\Requests\BaseFormRequest;
use App\Models\Organization;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Draft an invoice for hardware sold.
 *
 * The buyer is either a tenant or an outside party. Requiring one of the two is a rule
 * rather than a controller check, so an invoice addressed to nobody is refused as a field
 * error before any pricing happens.
 */
class DraftDeviceSaleRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'buyer_name' => ['nullable', 'string', 'max:200', 'required_without:organization_id'],
            'buyer_vat' => ['nullable', 'string', 'max:50'],

            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.device_id' => ['required', 'integer', Rule::exists('platform_devices', 'id')],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],

            'payment_method' => ['required', Rule::in(InvoicePaymentMethodEnum::values())],
            'bank_name' => ['nullable', 'string', 'max:255', 'required_if:payment_method,'.InvoicePaymentMethodEnum::BankTransfer->value],
            'transfer_ref' => ['nullable', 'string', 'max:255', 'required_if:payment_method,'.InvoicePaymentMethodEnum::BankTransfer->value],
            'gateway_name' => ['nullable', 'string', 'max:255', 'required_if:payment_method,'.InvoicePaymentMethodEnum::Gateway->value],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'buyer_name.required_without' => __('api.device_sale_buyer_required'),
        ];
    }

    public function organization(): ?Organization
    {
        return $this->filled('organization_id')
            ? Organization::query()->find($this->integer('organization_id'))
            : null;
    }

    /**
     * The name on the invoice: the tenant's own, or the outside buyer as typed.
     */
    public function buyerName(): string
    {
        $name = $this->input('buyer_name') ?: $this->organization()?->name;

        abort_if(empty($name), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.device_sale_buyer_required'));

        return (string) $name;
    }

    /**
     * @return array<int, array{device_id: int, qty: int}>
     */
    public function lines(): array
    {
        return array_map(
            static fn (array $line) => ['device_id' => (int) $line['device_id'], 'qty' => (int) $line['qty']],
            $this->input('lines', []),
        );
    }

    public function paymentMethod(): InvoicePaymentMethodEnum
    {
        return InvoicePaymentMethodEnum::from($this->string('payment_method')->toString());
    }

    /**
     * @return array{bank_name: string|null, transfer_ref: string|null, gateway_name: string|null}
     */
    public function paymentMeta(): array
    {
        return [
            'bank_name' => $this->input('bank_name'),
            'transfer_ref' => $this->input('transfer_ref'),
            'gateway_name' => $this->input('gateway_name'),
        ];
    }
}
